<?php
/**
 * Council review — entry storage.
 *
 * Every SQL statement goes through $wpdb->prepare() except IN () lists,
 * which are built from absint()-ed ids.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_INT_COLUMNS = ['id', 'project_id', 'parent_id', 'author_user_id', 'review_slot'];

function cdcf_review_normalize_row(array $row): array {
    foreach (CDCF_REVIEW_INT_COLUMNS as $col) {
        if (array_key_exists($col, $row) && $row[$col] !== null) {
            $row[$col] = (int) $row[$col];
        }
    }
    return $row;
}

function cdcf_review_id_list(array $ids): string {
    return implode(',', array_map('absint', $ids));
}

function cdcf_review_insert_entry(array $row): int {
    global $wpdb;
    $is_review = $row['kind'] === 'review';
    $author    = isset($row['author_user_id']) ? (int) $row['author_user_id'] : null;
    // A duplicate review_slot is an expected conflict (the caller answers
    // 409); keep wpdb from printing it under WP_DEBUG_DISPLAY.
    $suppressed = $wpdb->suppress_errors();
    $ok = $wpdb->insert(cdcf_review_table('entries'), [
        'project_id'     => (int) $row['project_id'],
        'kind'           => (string) $row['kind'],
        'parent_id'      => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
        'author_user_id' => $author,
        'review_slot'    => $is_review ? $author : null,
        'body_md'        => (string) $row['body_md'],
        'vote'           => $is_review ? (string) $row['vote'] : null,
        'created_at'     => cdcf_review_now(),
    ]);
    $wpdb->suppress_errors($suppressed);
    return $ok ? (int) $wpdb->insert_id : 0;
}

function cdcf_review_get_entry(int $id): ?array {
    global $wpdb;
    $table = cdcf_review_table('entries');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_row($row) : null;
}

function cdcf_review_find_review(int $project_id, int $user_id): ?array {
    global $wpdb;
    $table = cdcf_review_table('entries');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE project_id = %d AND kind = 'review' AND author_user_id = %d LIMIT 1",
        $project_id,
        $user_id
    ), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_row($row) : null;
}

/** @return list<array> */
function cdcf_review_project_entries(int $project_id): array {
    global $wpdb;
    $table = cdcf_review_table('entries');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE project_id = %d ORDER BY created_at ASC, id ASC",
        $project_id
    ), ARRAY_A);
    return array_map('cdcf_review_normalize_row', is_array($rows) ? $rows : []);
}

/**
 * Optimistic-concurrency token for an entry: its last edit time, or its
 * creation time when never edited. Never empty, so "no token" can't be
 * confused with "never edited".
 */
function cdcf_review_entry_version(array $entry): string {
    return (string) ($entry['edited_at'] ?? $entry['created_at']);
}

/**
 * Compare-and-set update. Succeeds only when the row's version token
 * (cdcf_review_entry_version) still equals $expected_version, so two
 * people editing from stale copies can't silently overwrite each other.
 */
function cdcf_review_update_entry(int $id, array $fields, string $expected_version): bool {
    global $wpdb;
    $fields = array_intersect_key($fields, array_flip(['body_md', 'vote']));
    if (!$fields || $expected_version === '') {
        return false;
    }
    $sets = [];
    $args = [];
    foreach ($fields as $col => $value) {
        $sets[] = "{$col} = %s";
        $args[] = (string) $value;
    }
    $sets[] = 'edited_at = %s';
    $args[] = cdcf_review_now();
    $args[] = $id;
    $args[] = $expected_version;
    $table = cdcf_review_table('entries');
    $sql   = "UPDATE {$table} SET " . implode(', ', $sets)
        . ' WHERE id = %d AND COALESCE(edited_at, created_at) = %s';
    return 1 === (int) $wpdb->query($wpdb->prepare($sql, $args));
}

/** @return array{cdcf: int, community: int, reject: int} */
function cdcf_review_empty_tally(): array {
    return array_fill_keys(CDCF_REVIEW_VOTES, 0);
}

/** @return array<int, array{cdcf: int, community: int, reject: int}> */
function cdcf_review_tallies(array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    global $wpdb;
    $table = cdcf_review_table('entries');
    $rows = $wpdb->get_results(
        "SELECT project_id, vote, COUNT(*) AS n FROM {$table} WHERE kind = 'review' AND project_id IN ("
        . cdcf_review_id_list($project_ids) . ') GROUP BY project_id, vote',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $pid = (int) $row['project_id'];
        $out[$pid] ??= cdcf_review_empty_tally();
        if (isset($out[$pid][$row['vote']])) {
            $out[$pid][$row['vote']] = (int) $row['n'];
        }
    }
    return $out;
}

/** The single most-voted option; null when there are no votes or a tie. */
function cdcf_review_plurality(array $tally): ?string {
    $max = max($tally);
    if ($max === 0) {
        return null;
    }
    $leaders = array_keys($tally, $max, true);
    return count($leaders) === 1 ? (string) $leaders[0] : null;
}

/** @return array<int, string> project id => the user's vote */
/**
 * Who has voted on each project, including people who have since left a
 * council.
 *
 * @param list<int> $project_ids
 * @return array<int, list<int>>
 */
function cdcf_review_voter_ids(array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    global $wpdb;
    $table = cdcf_review_table('entries');
    $rows = $wpdb->get_results(
        "SELECT project_id, author_user_id FROM {$table} WHERE kind = 'review' AND project_id IN ("
        . cdcf_review_id_list($project_ids) . ')',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['project_id']][] = (int) $row['author_user_id'];
    }
    return $out;
}

function cdcf_review_votes_by_user(int $user_id, array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    global $wpdb;
    $table = cdcf_review_table('entries');
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT project_id, vote FROM {$table} WHERE kind = 'review' AND author_user_id = %d", $user_id)
        . ' AND project_id IN (' . cdcf_review_id_list($project_ids) . ')',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['project_id']] = (string) $row['vote'];
    }
    return $out;
}

/** The author of the review a reply answers, or null. */
function cdcf_review_reply_parent_author(int $reply_id): ?int {
    $reply  = cdcf_review_get_entry($reply_id);
    $parent = $reply && !empty($reply['parent_id']) ? cdcf_review_get_entry((int) $reply['parent_id']) : null;
    return $parent && $parent['author_user_id'] !== null ? (int) $parent['author_user_id'] : null;
}

/**
 * The authors of the reviews the given replies answer, in one query.
 *
 * @param list<int> $reply_ids
 * @return array<int, int> reply id => review author id
 */
function cdcf_review_reply_parent_authors(array $reply_ids): array {
    if (!$reply_ids) {
        return [];
    }
    global $wpdb;
    $entries = cdcf_review_table('entries');
    $rows = $wpdb->get_results(
        "SELECT r.id, p.author_user_id FROM {$entries} r JOIN {$entries} p ON p.id = r.parent_id"
        . ' WHERE r.id IN (' . cdcf_review_id_list($reply_ids) . ') AND p.author_user_id IS NOT NULL',
        ARRAY_A
    );
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $out[(int) $row['id']] = (int) $row['author_user_id'];
    }
    return $out;
}
