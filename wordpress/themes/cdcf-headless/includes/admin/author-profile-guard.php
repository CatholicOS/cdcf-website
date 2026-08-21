<?php

/**
 * Capability guard for the `author_team_member` ACF user field.
 *
 * The `group_author_profile` field group (registered in functions.php) is
 * located with `user_form == all`, and ACF has no location parameter that
 * expresses "only when the VIEWER is an editor" — `user_form == edit`
 * still covers profile.php, and `user_role` matches the user being
 * edited, not the one doing the editing. So the gate lives here instead.
 *
 * Why it matters: ACF saves user field groups straight from $_POST['acf']
 * with no capability check of its own, WordPress lets any user save their
 * own profile, and `author_team_member` is the SOLE ownership signal for
 * /cdcf/v1/my-team-member — cdcf_rest_my_team_member_permission() asks
 * only for a logged-in user with a non-zero link. Left unguarded, any
 * subscriber could open profile.php, link themselves to any published
 * team_member, and then PATCH that person's bio in all six languages.
 * Zitadel sign-in auto-provisions subscribers, so the reachable
 * population is everyone who can sign in to the frontend.
 *
 * Two guards, because either alone is a half-fix:
 *   1. acf/prepare_field  — removes the control from the form.
 *   2. acf/pre_update_value — refuses the write. Hiding the field does
 *      NOT stop a hand-crafted POST to profile.php carrying
 *      acf[field_author_team_member][]=123, because ACF's save loop
 *      iterates whatever $_POST['acf'] contains. This is the guard that
 *      actually closes the hole; guard 1 keeps the UI honest.
 *
 * The write guard sits on ACF's value filter rather than on
 * personal_options_update so it is path-agnostic — it does not depend on
 * which hook ACF happens to save through, and it covers any future form
 * that exposes the field.
 *
 * Functions are pure; functions.php registers the filters (mirrors the
 * includes/admin/limited-user-provisioning.php convention).
 */

defined('ABSPATH') || exit;

/** ACF field key of the `author_team_member` relationship, as registered in functions.php. */
const CDCF_AUTHOR_TEAM_MEMBER_FIELD_KEY = 'field_author_team_member';

/**
 * May the CURRENT user set someone's author-to-team_member link?
 *
 * `edit_others_posts` is the canonical "editor and above" primitive:
 * editors and administrators hold it, while authors, contributors and
 * subscribers do not.
 *
 * CDCF_LIMITED_USER_CAP is admitted as well because it is exactly the
 * capability POST /cdcf/v1/author-team-member is gated on. Without this
 * clause the guard would regress that endpoint for the opted-in
 * non-admin bot account it exists to serve — the endpoint writes the
 * same field through update_field(), so it passes through guard 2.
 */
function cdcf_can_manage_author_team_member_link(): bool {
    return current_user_can('edit_others_posts')
        || current_user_can(CDCF_LIMITED_USER_CAP);
}

/**
 * Guard 1 — filter for `acf/prepare_field/key=field_author_team_member`.
 *
 * Returning false tells ACF not to render the field at all, so the
 * "Author Profile" section disappears from profile.php for everyone
 * below editor.
 *
 * @param array<string,mixed>|false $field
 * @return array<string,mixed>|false
 */
function cdcf_guard_author_team_member_field($field) {
    return cdcf_can_manage_author_team_member_link() ? $field : false;
}

/**
 * Guard 2 — filter for `acf/pre_update_value` (the BARE filter).
 *
 * ACF registers `key` hook variations for acf/update_value but NOT for
 * acf/pre_update_value (see acf_add_filter_variations calls in
 * includes/acf-value-functions.php), so an
 * `acf/pre_update_value/key=field_author_team_member` filter would never
 * fire — dead code, and the hole left open. We hook the bare filter and
 * discriminate on $field['key'] here instead, which means this runs on
 * every field write and must keep its hands off everything else.
 *
 * ACF short-circuits acf_update_value() when this filter returns anything
 * other than null, so returning false refuses the write before it reaches
 * the database. update_field() then returns false, which the REST handler
 * already treats as a failure — though an authorized caller never lands
 * here.
 *
 * An upstream non-null $check is passed through untouched: another filter
 * has already decided, and resurrecting the write by returning null would
 * override it.
 *
 * @param mixed               $check   Null unless an earlier filter decided.
 * @param mixed               $value   Incoming value (unused).
 * @param int|string          $post_id ACF target, e.g. "user_9" (unused).
 * @param array<string,mixed> $field   Field array; discriminated on by key.
 * @return mixed Null to proceed, anything else to short-circuit.
 */
function cdcf_guard_author_team_member_update($check, $value, $post_id, $field) {
    unset($value, $post_id);
    if ($check !== null) {
        return $check;
    }
    $key = is_array($field) ? ($field['key'] ?? '') : '';
    if ($key !== CDCF_AUTHOR_TEAM_MEMBER_FIELD_KEY) {
        return $check;
    }
    return cdcf_can_manage_author_team_member_link() ? $check : false;
}
