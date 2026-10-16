<?php
/**
 * Council review — the caller's notifications (the header bell and
 * /review/inbox). cdcf_review_record_event() writes one row per member
 * except the actor; this module reads them and records when they are read.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_INBOX_PAGE_SIZE = 20;

/** @return array{items: list<array>, has_more: bool} Newest first. */
function cdcf_review_inbox(int $user_id, bool $unread_only, int $page): array {
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    $events        = cdcf_review_table('events');
    $limit         = CDCF_REVIEW_INBOX_PAGE_SIZE;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT e.id, e.project_id, e.type, e.actor_user_id, e.entry_id, e.data, e.created_at, n.read_at'
            . " FROM {$notifications} n JOIN {$events} e ON e.id = n.event_id WHERE n.user_id = %d"
            . ($unread_only ? ' AND n.read_at IS NULL' : '')
            . ' ORDER BY e.id DESC LIMIT %d OFFSET %d',
            $user_id,
            $limit + 1,
            (max(1, $page) - 1) * $limit
        ),
        ARRAY_A
    );
    $rows = is_array($rows) ? $rows : [];
    return [
        'items'    => array_map(
            static fn(array $row) => cdcf_review_present_notification($row, $user_id),
            array_slice($rows, 0, $limit)
        ),
        'has_more' => count($rows) > $limit,
    ];
}

function cdcf_review_present_notification(array $row, int $user_id): array {
    $event = cdcf_review_normalize_event($row);
    $actor = $event['actor_user_id'];
    $user  = $actor !== null ? get_userdata($actor) : null;
    return [
        'event_id'   => $event['id'],
        'type'       => $event['type'],
        'project'    => ['id' => $event['project_id'], 'title' => (string) get_post_field('post_title', $event['project_id'])],
        'actor'      => $actor !== null ? ['id' => $actor, 'name' => $user ? (string) $user->display_name : 'Former member'] : null,
        'entry_id'   => $event['entry_id'],
        'data'       => $event['data'],
        'created_at' => $event['created_at'],
        'read'       => ($row['read_at'] ?? null) !== null,
        'to_you'     => $event['type'] === 'reply_posted' && $event['entry_id'] !== null
            && cdcf_review_reply_parent_author($event['entry_id']) === $user_id,
    ];
}

function cdcf_review_unread_total(int $user_id): int {
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    return (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$notifications} WHERE user_id = %d AND read_at IS NULL", $user_id)
    );
}

/**
 * Mark the user's unread notifications read: all of them, the given event
 * ids, or those of one project. Never touches another user's rows.
 *
 * @param list<int>|null $event_ids
 */
function cdcf_review_mark_read(int $user_id, ?array $event_ids = null, ?int $project_id = null): void {
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    $sql = $wpdb->prepare(
        "UPDATE {$notifications} SET read_at = %s WHERE user_id = %d AND read_at IS NULL",
        cdcf_review_now(),
        $user_id
    );
    if ($event_ids !== null) {
        $ids = array_values(array_filter(array_map('intval', $event_ids), static fn(int $id) => $id > 0));
        if (!$ids) {
            return;
        }
        $sql .= ' AND event_id IN (' . cdcf_review_id_list($ids) . ')';
    }
    if ($project_id !== null) {
        $events = cdcf_review_table('events');
        $sql .= $wpdb->prepare(" AND event_id IN (SELECT id FROM {$events} WHERE project_id = %d)", $project_id);
    }
    $wpdb->query($sql);
}
