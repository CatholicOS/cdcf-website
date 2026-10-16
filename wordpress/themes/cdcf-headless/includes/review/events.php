<?php
/**
 * Council review — activity log and notification fan-out.
 *
 * Every event lands in cdcf_review_events and gets one
 * cdcf_review_notifications row per member except the actor (the bell,
 * Phase 5). The cdcf_review_event_recorded action lets the email layer
 * (Phase 5) react without this module knowing about email.
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
    do_action('cdcf_review_event_recorded', $event_id, $project_id, $type, $actor_user_id, $entry_id);
    return $event_id;
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
