<?php
/**
 * Council review — applying and recording the Board's decision.
 *
 * The outcome (publish, convert to community project, or move to draft) is
 * applied first and the decision is recorded only when it succeeded. While
 * the outcome runs, cdcf_review_deciding() is true so the admin-override
 * hook does not mistake the endpoint's own publish for an override.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_DECISION_TOKEN_TTL = 90 * DAY_IN_SECONDS;

/** Fields only the CDCF project type has; removed on conversion. */
const CDCF_REVIEW_PROJECT_ONLY_FIELDS = ['project_status', 'project_license', 'project_leads', 'project_repo_url'];

/** ACF reference meta for the fields the community type keeps or gains. */
const CDCF_REVIEW_COMMUNITY_FIELD_KEYS = [
    'project_url'        => 'field_community_project_url',
    'project_category'   => 'field_community_project_category',
    'project_github_url' => 'field_community_project_github_url',
];

function cdcf_review_deciding(?bool $set = null): bool {
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/** @return true|WP_Error */
function cdcf_review_apply_outcome(int $project_id, string $decision) {
    cdcf_review_deciding(true);
    try {
        if ($decision === 'community') {
            return cdcf_review_convert_to_community($project_id);
        }
        $result = wp_update_post([
            'ID'          => $project_id,
            'post_status' => $decision === 'cdcf' ? 'publish' : 'draft',
        ], true);
        if (is_wp_error($result) || !$result) {
            return new WP_Error('outcome_failed', 'The decision could not be applied to the project.', ['status' => 500]);
        }
        return true;
    } finally {
        cdcf_review_deciding(false);
    }
}

/** @return true|WP_Error */
function cdcf_review_convert_to_community(int $project_id) {
    $keys = array_merge(
        CDCF_REVIEW_PROJECT_ONLY_FIELDS,
        array_map(static fn($f) => '_' . $f, CDCF_REVIEW_PROJECT_ONLY_FIELDS),
        array_keys(CDCF_REVIEW_COMMUNITY_FIELD_KEYS),
        array_map(static fn($f) => '_' . $f, array_keys(CDCF_REVIEW_COMMUNITY_FIELD_KEYS)),
        ['_cdcf_converted_from_submission']
    );
    $snapshot = [];
    foreach ($keys as $key) {
        $snapshot[$key] = metadata_exists('post', $project_id, $key) ? get_post_meta($project_id, $key, true) : null;
    }
    $type = (string) get_post_type($project_id);

    $repo = (string) get_post_meta($project_id, 'project_repo_url', true);
    if ($repo !== '') {
        update_post_meta($project_id, 'project_github_url', $repo);
    }
    foreach (CDCF_REVIEW_COMMUNITY_FIELD_KEYS as $field => $acf_key) {
        if ($field !== 'project_github_url' || $repo !== '') {
            update_post_meta($project_id, '_' . $field, $acf_key);
        }
    }
    foreach (CDCF_REVIEW_PROJECT_ONLY_FIELDS as $field) {
        delete_post_meta($project_id, $field);
        delete_post_meta($project_id, '_' . $field);
    }
    set_post_type($project_id, 'community_project');
    update_post_meta($project_id, '_cdcf_converted_from_submission', '1');

    $result = wp_update_post(['ID' => $project_id, 'post_status' => 'publish'], true);
    if (!is_wp_error($result) && $result) {
        return true;
    }

    // Roll back: the same project type and fields as before.
    set_post_type($project_id, $type);
    foreach ($snapshot as $key => $value) {
        if ($value === null) {
            delete_post_meta($project_id, $key);
        } else {
            // update_post_meta() unslashes its value; slash it so backslashes survive.
            update_post_meta($project_id, $key, wp_slash($value));
        }
    }
    return new WP_Error('conversion_failed', 'The project could not be converted to a community project.', ['status' => 500]);
}

function cdcf_review_record_decision(
    int $project_id,
    string $decision,
    ?int $user_id,
    string $rationale,
    string $summary,
    bool $admin_override = false
): void {
    $now = time();
    update_post_meta($project_id, '_cdcf_review_decision', $decision);
    update_post_meta($project_id, '_cdcf_review_decided_by', (int) $user_id);
    update_post_meta($project_id, '_cdcf_review_decided_at', $now);
    update_post_meta($project_id, '_cdcf_review_rationale', $rationale);
    update_post_meta($project_id, '_cdcf_review_summary', $summary);
    if ($admin_override) {
        update_post_meta($project_id, '_cdcf_review_admin_override', '1');
    } else {
        delete_post_meta($project_id, '_cdcf_review_admin_override');
    }
    update_post_meta($project_id, CDCF_REVIEW_STAGE_META, 'decided');
    // The submitter's link keeps working for 90 days after the decision.
    update_post_meta($project_id, CDCF_REVIEW_TOKEN_EXPIRES_META, $now + CDCF_REVIEW_DECISION_TOKEN_TTL);
}

/** @return array{decision: string, rationale: string, summary: string, decided_at: int, decided_by: array{id: int, name: string}|null, admin_override: bool} */
function cdcf_review_present_decision(int $project_id): array {
    $uid  = (int) get_post_meta($project_id, '_cdcf_review_decided_by', true);
    $user = $uid > 0 ? get_userdata($uid) : null;
    return [
        'decision'       => (string) get_post_meta($project_id, '_cdcf_review_decision', true),
        'rationale'      => (string) get_post_meta($project_id, '_cdcf_review_rationale', true),
        'summary'        => (string) get_post_meta($project_id, '_cdcf_review_summary', true),
        'decided_at'     => (int) get_post_meta($project_id, '_cdcf_review_decided_at', true),
        'decided_by'     => $uid > 0 ? ['id' => $uid, 'name' => $user ? (string) $user->display_name : 'Former member'] : null,
        'admin_override' => get_post_meta($project_id, '_cdcf_review_admin_override', true) === '1',
    ];
}

/**
 * An administrator publishing a submission still under review in wp-admin
 * decides it as accepted. The decision endpoint's own publish is skipped
 * (cdcf_review_deciding()). No submitter email is sent.
 */
function cdcf_review_admin_override_on_publish($new_status, $old_status, $post): void {
    if ($new_status !== 'publish' || $old_status === 'publish' || cdcf_review_deciding()) {
        return;
    }
    $pid = (int) $post->ID;
    if (!in_array((string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true), ['in_review', 'awaiting_submitter'], true)) {
        return;
    }
    $uid      = (int) get_current_user_id();
    $decision = $post->post_type === 'community_project' ? 'community' : 'cdcf';
    cdcf_review_record_decision($pid, $decision, $uid ?: null, '', '', true);
    cdcf_review_record_event($pid, 'admin_override', $uid ?: null, null, ['decision' => $decision]);
}

/** On the edit screen of a submission under review: decide it in /review. */
function cdcf_review_edit_screen_notice(): void {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $post   = $GLOBALS['post'] ?? null;
    if (!$screen || $screen->base !== 'post' || !is_object($post)) {
        return;
    }
    $stage = (string) get_post_meta((int) $post->ID, CDCF_REVIEW_STAGE_META, true);
    if (!in_array($stage, ['in_review', 'awaiting_submitter'], true)) {
        return;
    }
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    $url      = rtrim($frontend, '/') . '/review/' . (int) $post->ID;
    printf(
        '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
        esc_html('Under council review — the Board decides in the review workspace. Publishing here records an administrator override.'),
        esc_url($url),
        esc_html('Open the review')
    );
}
