<?php
/**
 * REST route handler for /cdcf/v1/author-team-member.
 *
 * Links a WordPress user (a blog author) to their team_member bio card
 * by writing the `author_team_member` ACF relationship field on the
 * USER object (ACF target "user_{id}"). Author pages reuse the linked
 * team_member's translated bio, photo, role, and social links.
 *
 * Why a dedicated endpoint rather than the existing /relationship route
 * or the core users REST endpoint:
 *   - /cdcf/v1/relationship is post-only — it absint()s post_id and
 *     guards with get_post(), so a user id can never reach update_field.
 *   - ACF 6.x *free* does not expose user-located field groups via the
 *     `acf` property of /wp/v2/users/{id}; a PUT with {"acf":{...}} is
 *     silently dropped (the value round-trips as []). Confirmed against
 *     production: a value set in wp-admin still reads back empty over REST.
 * The only reliable path is the canonical ACF call update_field(field,
 * value, "user_{id}") server-side, which this endpoint exposes.
 *
 * Extracted into its own file (like the other cdcf/v1 handlers) so the
 * body can be unit-tested with Brain Monkey + Mockery.
 */

defined('ABSPATH') || exit;

/**
 * Set (or clear) the `author_team_member` relationship on a user.
 *
 * Shared by the REST endpoint below and by the create-user handler's
 * optional link-on-creation step, so there is a single update_field
 * code path for this field.
 *
 * @param int $user_id        Target WordPress user id.
 * @param int $team_member_id team_member post id to link, or 0 to clear.
 * @return bool|WP_Error       true if a write occurred, false if the user
 *                             was already in the desired state (no-op);
 *                             WP_Error on validation/persistence failure.
 *                             Callers must detect failure with is_wp_error().
 */
function cdcf_set_author_team_member(int $user_id, int $team_member_id) {
    if (!function_exists('update_field') || !function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    if ($user_id === 0 || get_userdata($user_id) === false) {
        return new WP_Error('user_not_found', 'User not found.', ['status' => 404]);
    }

    // A positive id must reference an existing team_member post; 0 clears
    // the link and skips the post lookup entirely.
    if ($team_member_id > 0) {
        $post = get_post($team_member_id);
        if ($post === null || ($post->post_type ?? '') !== 'team_member') {
            return new WP_Error(
                'invalid_team_member',
                'team_member_id must reference a team_member post.',
                ['status' => 400]
            );
        }
    }

    $target  = "user_{$user_id}";
    $desired = $team_member_id > 0 ? [$team_member_id] : [];

    // ACF stores relationship ids as strings; normalize to ints so the
    // no-op comparison is reliable. Skipping an unchanged write avoids
    // ACF's update_field() returning false on a no-op (which would
    // otherwise surface as a spurious 500 — see #109).
    $current = get_field('author_team_member', $target, false);
    $current = is_array($current) ? array_values(array_map('absint', $current)) : [];

    if ($current === $desired) {
        return false; // no-op
    }

    if (!update_field('author_team_member', $desired, $target)) {
        return new WP_Error('update_failed', 'update_field returned false.', ['status' => 500]);
    }

    return true; // wrote
}

/**
 * POST /cdcf/v1/author-team-member — link (or unlink) a user and a
 * team_member bio card.
 */
function cdcf_rest_link_author_team_member(WP_REST_Request $request) {
    $user_id        = absint($request['user_id']);
    $team_member_id = absint($request['team_member_id']);

    $result = cdcf_set_author_team_member($user_id, $team_member_id);
    if (is_wp_error($result)) {
        return $result;
    }

    return rest_ensure_response([
        'success'        => true,
        'user_id'        => $user_id,
        'team_member_id' => $team_member_id,
        'value'          => $team_member_id > 0 ? [$team_member_id] : [],
        // false when the user was already linked to this team_member.
        'updated'        => $result,
    ]);
}
