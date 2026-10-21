<?php
/**
 * Council review — activity log and notification fan-out.
 *
 * Every event lands in cdcf_review_events and gets one
 * cdcf_review_notifications row per member except the actor (the bell).
 * The cdcf_review_event_recorded action lets the email layer,
 * member-emails.php, react without this module knowing about email.
 */

defined('ABSPATH') || exit;

function cdcf_review_record_event(
    int $project_id,
    string $type,
    ?int $actor_user_id,
    ?int $entry_id = null,
    array $data = []
): int {
    global $wpdb;
    $ok = $wpdb->insert(cdcf_review_table('events'), [
        'project_id'    => $project_id,
        'type'          => $type,
        'actor_user_id' => $actor_user_id,
        'entry_id'      => $entry_id,
        'data'          => $data ? wp_json_encode($data) : null,
        'created_at'    => cdcf_review_now(),
    ]);
    if (!$ok) {
        return 0;
    }
    $event_id = (int) $wpdb->insert_id;
    foreach (cdcf_review_member_user_ids() as $user_id) {
        if ($user_id === $actor_user_id) {
            continue;
        }
        $wpdb->insert(cdcf_review_table('notifications'), [
            'event_id' => $event_id,
            'user_id'  => $user_id,
        ]);
    }
    do_action('cdcf_review_event_recorded', $event_id, $project_id, $type, $actor_user_id, $entry_id, $data);
    return $event_id;
}

/**
 * Give a member the notifications they missed for projects still under
 * review. cdcf_review_record_event() notifies only the members of the
 * moment, so someone who joins later — a council seat added on the About
 * page, a bio linked to a new account — would otherwise start with an
 * empty bell. Runs once per membership generation per user (one user-meta
 * read otherwise). INSERT IGNORE keeps existing rows and their read state;
 * no email is sent. Decided projects are left out: nothing to act on.
 */
function cdcf_review_catch_up_notifications(int $user_id): void {
    $gen = (int) get_option('cdcf_review_membership_gen', 0);
    if ($user_id <= 0 || (string) get_user_meta($user_id, 'cdcf_review_notified_gen', true) === (string) $gen) {
        return;
    }
    if (!cdcf_review_can($user_id, 'view')) {
        return;
    }
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    $events        = cdcf_review_table('events');
    $ok = $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO {$notifications} (event_id, user_id)"
            . " SELECT e.id, %d FROM {$events} e"
            . ' WHERE (e.actor_user_id IS NULL OR e.actor_user_id <> %d)'
            . " AND e.project_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (%s, %s))",
            $user_id,
            $user_id,
            CDCF_REVIEW_STAGE_META,
            'in_review',
            'awaiting_submitter'
        )
    );
    // On failure leave the marker alone so the next request retries.
    if ($ok !== false) {
        update_user_meta($user_id, 'cdcf_review_notified_gen', (string) $gen);
    }
}

/** @return array<int, int> project id => unread notification count */
function cdcf_review_unread_counts(int $user_id, array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    $events        = cdcf_review_table('events');
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT e.project_id, COUNT(*) AS n FROM {$notifications} n JOIN {$events} e ON e.id = n.event_id"
            . ' WHERE n.user_id = %d AND n.read_at IS NULL',
            $user_id
        )
        . ' AND e.project_id IN (' . cdcf_review_id_list($project_ids) . ') GROUP BY e.project_id',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['project_id']] = (int) $row['n'];
    }
    return $out;
}

/** @return array{id: int, project_id: int, type: string, actor_user_id: ?int, entry_id: ?int, data: array, created_at: string} */
function cdcf_review_normalize_event(array $row): array {
    $data = !empty($row['data']) ? json_decode((string) $row['data'], true) : [];
    return [
        'id'            => (int) $row['id'],
        'project_id'    => (int) $row['project_id'],
        'type'          => (string) $row['type'],
        'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
        'entry_id'      => $row['entry_id'] !== null ? (int) $row['entry_id'] : null,
        'data'          => is_array($data) ? $data : [],
        'created_at'    => (string) $row['created_at'],
    ];
}

/** @return array{id: int, project_id: int, type: string, actor_user_id: ?int, entry_id: ?int, data: array, created_at: string}|null */
function cdcf_review_get_event(int $event_id): ?array {
    global $wpdb;
    $events = cdcf_review_table('events');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$events} WHERE id = %d", $event_id), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_event($row) : null;
}
