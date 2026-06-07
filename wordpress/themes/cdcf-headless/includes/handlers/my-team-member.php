<?php
/**
 * REST handlers for the authenticated user's own team_member bio.
 *
 *   GET  /cdcf/v1/my-team-member            → discovery (available langs)
 *   PATCH /cdcf/v1/my-team-member/{lang}    → edit the {lang} version of the
 *                                              caller's bio + queue re-
 *                                              translation to the other 5
 *
 * Authorization invariant: the authenticated WP user's `author_team_member`
 * ACF field must point at a post in the same Polylang translation group as
 * the post being edited. That field is admin-managed (set via
 * `/cdcf/v1/author-team-member`); end users never modify it themselves —
 * so it's the canonical ownership signal here. The check covers the case
 * where the link points at, say, the EN post but the user is editing the
 * DE sibling: as long as they're in the same group, the user owns both.
 *
 * Translation fan-out: on every save we hand the (now-updated) source post
 * to cdcf_enqueue_post_translation() for each of the OTHER 5 languages in
 * the group, reusing the existing Polylang link layer + redis queue + locked
 * persistence path. Whichever language the user just saved becomes the new
 * source of truth for the next cycle — the OpenAI prompt's source language
 * is read from the source post (see translation.php:126, fixed in PR #171).
 *
 * Phase 3 of cdcf-bio-edit-zitadel plan.
 */

defined('ABSPATH') || exit;

const CDCF_MY_TEAM_MEMBER_LINKEDIN_HOST = 'linkedin.com';
const CDCF_MY_TEAM_MEMBER_GITHUB_HOST   = 'github.com';

/**
 * Resolve the team_member post id linked to the given WP user via the
 * `author_team_member` ACF user-field. Returns 0 when no link is set.
 *
 * ACF relationship fields return either a single post id, an array of
 * post ids, or false depending on cardinality config — we accept any
 * of those shapes and pick the first valid id.
 */
function cdcf_my_team_member_resolve_link(int $user_id): int {
    if ($user_id <= 0 || !function_exists('get_field')) {
        return 0;
    }
    $linked = get_field('author_team_member', "user_{$user_id}");
    if (is_array($linked)) {
        $linked = reset($linked);
    }
    if ($linked instanceof WP_Post) {
        return (int) $linked->ID;
    }
    return is_numeric($linked) ? (int) $linked : 0;
}

/**
 * Pull the full {lang_slug => post_id} translation group for the given
 * team_member post. Returns at minimum `[post_lang => post_id]` if
 * Polylang has no sibling translations recorded.
 *
 * @return array<string,int>
 */
function cdcf_my_team_member_collect_group(int $team_member_id): array {
    if ($team_member_id <= 0) {
        return [];
    }
    $group = function_exists('pll_get_post_translations')
        ? pll_get_post_translations($team_member_id)
        : [];
    if (empty($group) && function_exists('pll_get_post_language')) {
        $lang = pll_get_post_language($team_member_id);
        if (is_string($lang) && $lang !== '') {
            $group = [$lang => $team_member_id];
        }
    }
    $out = [];
    foreach ($group as $slug => $pid) {
        if (is_string($slug) && is_numeric($pid)) {
            $out[$slug] = (int) $pid;
        }
    }
    return $out;
}

/**
 * Find the English About-page id. Used by Board-of-Directors membership
 * lookups, which key off the canonical English page's ACF relationship.
 * Returns 0 when there is no about.php-templated page at all (test envs,
 * post-fresh-install) so callers can short-circuit cleanly.
 */
function cdcf_my_team_member_get_english_about_page_id(): int {
    if (!function_exists('get_pages')) {
        return 0;
    }
    $about_pages = get_pages([
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'templates/about.php',
    ]);
    if (empty($about_pages)) {
        return 0;
    }
    foreach ($about_pages as $page) {
        $lang = function_exists('pll_get_post_language')
            ? pll_get_post_language($page->ID, 'slug')
            : 'en';
        if ($lang === 'en') {
            return (int) $page->ID;
        }
    }
    // No EN-tagged About page found — fall back to the first one so
    // callers don't see a false negative when Polylang isn't active or
    // language tags are missing on legacy posts.
    return (int) $about_pages[0]->ID;
}

/**
 * Return true when the caller's team_member is on the Board of Directors.
 * Board membership lives inverse on the English About page's `team_members`
 * ACF relationship field — there's no council meta on the team_member post
 * itself — so we look up the caller's English sibling from the already-
 * collected Polylang group and check membership there.
 *
 * Used to drive the read-only "Position / Affiliation" treatment in the
 * bio editor (Board titles reflect formal council positions and aren't
 * editable by the member themselves).
 *
 * @param array<string,int> $group Polylang group, as returned by
 *                                 cdcf_my_team_member_collect_group().
 */
function cdcf_my_team_member_is_board_member(array $group): bool {
    if (!function_exists('get_field')) {
        return false;
    }
    $english_id = isset($group['en']) ? (int) $group['en'] : 0;
    if ($english_id <= 0) {
        return false;
    }
    $about_id = cdcf_my_team_member_get_english_about_page_id();
    if ($about_id <= 0) {
        return false;
    }
    $board_ids = get_field('team_members', $about_id, false);
    if (!is_array($board_ids)) {
        return false;
    }
    return in_array($english_id, array_map('intval', $board_ids), true);
}

/**
 * Validate that a URL is empty or points at the given allowed hostname
 * (exact or subdomain). Returns true on accept. Empty strings are
 * accepted so the caller can clear the field.
 */
function cdcf_my_team_member_url_host_ok(string $url, string $allowed_host): bool {
    if ($url === '') {
        return true;
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $host = strtolower((string) $parts['host']);
    $allowed = strtolower($allowed_host);
    if ($host === $allowed) {
        return true;
    }
    $suffix = '.' . $allowed;
    return str_ends_with($host, $suffix);
}

/**
 * Permission gate: caller must be logged in AND have any
 * `author_team_member` link. The per-language ownership invariant
 * (the link must point at a post in the same Polylang group as the
 * target) lives in the handler body, since it needs the resolved
 * group which depends on the route's {lang} param.
 *
 * @return true|WP_Error
 */
function cdcf_rest_my_team_member_permission(WP_REST_Request $request) {
    unset($request);
    if (!is_user_logged_in()) {
        return new WP_Error(
            'rest_not_logged_in',
            'Authentication required.',
            ['status' => 401]
        );
    }
    if (cdcf_my_team_member_resolve_link(get_current_user_id()) <= 0) {
        return new WP_Error(
            'rest_no_team_member_link',
            'Your account is not linked to a team_member post. Contact an admin.',
            ['status' => 403]
        );
    }
    return true;
}

/**
 * GET /cdcf/v1/my-team-member — discovery.
 *
 * Returns the resolved team_member post id + every Polylang sibling so
 * the frontend knows which languages are available to edit.
 */
function cdcf_rest_get_my_team_member(WP_REST_Request $request) {
    unset($request);
    $user_id = get_current_user_id();
    $team_member_id = cdcf_my_team_member_resolve_link($user_id);

    // Permission callback already rejected the no-link case; defensive
    // re-check protects against a TOCTOU between the gate and the body.
    if ($team_member_id <= 0) {
        return new WP_Error(
            'rest_no_team_member_link',
            'Your account is not linked to a team_member post.',
            ['status' => 403]
        );
    }

    $group = cdcf_my_team_member_collect_group($team_member_id);
    $available = [];
    foreach ($group as $slug => $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'team_member') {
            continue;
        }
        $available[] = [
            'slug'    => $slug,
            'post_id' => (int) $post_id,
            'title'   => (string) $post->post_title,
            'status'  => (string) $post->post_status,
        ];
    }

    return rest_ensure_response([
        'team_member_id'      => $team_member_id,
        'available_languages' => $available,
        'is_board_member'     => cdcf_my_team_member_is_board_member($group),
    ]);
}

/**
 * GET /cdcf/v1/my-team-member/{lang} — read the {lang} version of the
 * caller's bio.
 *
 * Exists because core REST `GET /wp/v2/team_member/{id}?context=edit`
 * requires `edit_post` capability on the specific post — Phase 5
 * auto-provisioned Subscribers who own a bio via `author_team_member`
 * have NO `edit_post` cap and would hit `rest_forbidden_context` even
 * though they legitimately own the post for bio-edit purposes. The
 * ownership signal here is the same one PATCH uses (the
 * `author_team_member` link + Polylang-group membership), not a
 * capability — so a linked Subscriber correctly succeeds.
 *
 * Returns the flat shape the bio editor consumes
 * (id/title/content/member_title/member_linkedin_url/member_github_url)
 * with `raw` strings (no rendering filters) so the editor doesn't
 * round-trip user-typed markup through `the_content` filters and
 * cause drift on subsequent saves.
 */
function cdcf_rest_get_my_team_member_lang(WP_REST_Request $request) {
    $user_id        = get_current_user_id();
    $linked_id      = cdcf_my_team_member_resolve_link($user_id);
    $requested_lang = (string) $request['lang'];

    if ($linked_id <= 0) {
        return new WP_Error(
            'rest_no_team_member_link',
            'Your account is not linked to a team_member post.',
            ['status' => 403]
        );
    }

    $group = cdcf_my_team_member_collect_group($linked_id);
    if (!isset($group[$requested_lang])) {
        return new WP_Error(
            'rest_no_translation_for_lang',
            sprintf('No %s translation exists for this team_member.', $requested_lang),
            ['status' => 404]
        );
    }
    $target_post_id = $group[$requested_lang];

    // Ownership invariant: the user's link must point at SOME post in
    // the resolved Polylang group (same check the PATCH handler runs).
    if (!in_array($linked_id, $group, true)) {
        return new WP_Error(
            'rest_forbidden',
            'You do not own this team_member.',
            ['status' => 403]
        );
    }

    $post = get_post($target_post_id);
    if (!$post || $post->post_type !== 'team_member') {
        return new WP_Error(
            'rest_no_translation_for_lang',
            sprintf('The %s translation entry is invalid (deleted or wrong post type).', $requested_lang),
            ['status' => 404]
        );
    }

    return rest_ensure_response([
        'id'                  => (int) $post->ID,
        'title'               => (string) $post->post_title,
        // post_content is the unfiltered, editor-shaped string — no
        // wpautop / oembed / shortcode processing applied. That's what
        // the bio editor wants to put back into TipTap.
        'content'             => (string) $post->post_content,
        'member_title'        => function_exists('get_field')
            ? (string) (get_field('member_title', $target_post_id) ?: '')
            : '',
        'member_linkedin_url' => function_exists('get_field')
            ? (string) (get_field('member_linkedin_url', $target_post_id) ?: '')
            : '',
        'member_github_url'   => function_exists('get_field')
            ? (string) (get_field('member_github_url', $target_post_id) ?: '')
            : '',
        // Surfaced on the load endpoint too (in addition to the discovery
        // endpoint) so a hot language switch in the bio editor doesn't
        // need a second round-trip to decide read-only state.
        'is_board_member'     => cdcf_my_team_member_is_board_member($group),
    ]);
}

/**
 * PATCH /cdcf/v1/my-team-member/{lang} — edit.
 *
 * Updates the {lang} version of the caller's bio (post_content + the
 * three ACF text fields) and queues re-translation to the other 5
 * languages from the just-saved source. Featured-image edits are
 * out of scope for this endpoint (deferred to a separate issue).
 */
function cdcf_rest_update_my_team_member(WP_REST_Request $request) {
    $user_id        = get_current_user_id();
    $linked_id      = cdcf_my_team_member_resolve_link($user_id);
    $requested_lang = (string) $request['lang'];

    if ($linked_id <= 0) {
        return new WP_Error(
            'rest_no_team_member_link',
            'Your account is not linked to a team_member post.',
            ['status' => 403]
        );
    }

    $group = cdcf_my_team_member_collect_group($linked_id);
    if (!isset($group[$requested_lang])) {
        return new WP_Error(
            'rest_no_translation_for_lang',
            sprintf('No %s translation exists for this team_member.', $requested_lang),
            ['status' => 404]
        );
    }
    $target_post_id = $group[$requested_lang];

    // Ownership invariant: the user's link must point at SOME post in
    // the resolved Polylang group. By construction it does — we built
    // the group from the linked post — but verify so a stale/orphaned
    // link can't slip through.
    if (!in_array($linked_id, $group, true)) {
        return new WP_Error(
            'rest_forbidden',
            'You do not own this team_member.',
            ['status' => 403]
        );
    }

    // Verify the target post actually exists and is a team_member.
    // A polluted Polylang group could otherwise route an edit to a
    // deleted or wrong-CPT post — surface that as a 404 rather than
    // silently writing wp_update_post against an invalid id.
    $target_post = get_post($target_post_id);
    if (!$target_post || $target_post->post_type !== 'team_member') {
        return new WP_Error(
            'rest_no_translation_for_lang',
            sprintf('The %s translation entry is invalid (deleted or wrong post type).', $requested_lang),
            ['status' => 404]
        );
    }

    // Board-of-Directors members can't edit their Position / Affiliation
    // (member_title) — that field reflects their formal council position
    // and is set by an administrator, not the member themselves. Reject
    // the write at the boundary; the bio editor disables the input on
    // the read side too. Empty/null member_title is fine (clear is also
    // a "no change" for our purposes).
    if (
        cdcf_my_team_member_is_board_member($group)
        && is_string($request->get_param('member_title'))
    ) {
        return new WP_Error(
            'rest_member_title_readonly',
            'member_title is read-only for Board of Directors members. Contact an administrator to change it.',
            ['status' => 403]
        );
    }

    // URL validation: LinkedIn + GitHub allowed hosts. Sanitization
    // (esc_url_raw) already ran via the args block; we only enforce
    // the hostname allowlist here.
    $linkedin = (string) ($request->get_param('member_linkedin_url') ?? '');
    if (!cdcf_my_team_member_url_host_ok($linkedin, CDCF_MY_TEAM_MEMBER_LINKEDIN_HOST)) {
        return new WP_Error(
            'rest_invalid_url',
            'LinkedIn URL must point at linkedin.com (or empty to clear).',
            ['status' => 400]
        );
    }
    $github = (string) ($request->get_param('member_github_url') ?? '');
    if (!cdcf_my_team_member_url_host_ok($github, CDCF_MY_TEAM_MEMBER_GITHUB_HOST)) {
        return new WP_Error(
            'rest_invalid_url',
            'GitHub URL must point at github.com (or empty to clear).',
            ['status' => 400]
        );
    }

    // Apply edits. content is only updated when the request actually
    // supplied a value (so a PATCH that only changes member_title
    // doesn't clobber the bio HTML). Track whether ANY field was
    // actually supplied so a no-op PATCH skips the fan-out below.
    $did_update = false;
    $content = $request->get_param('content');
    if (is_string($content)) {
        $upd = wp_update_post(
            ['ID' => $target_post_id, 'post_content' => $content],
            true
        );
        if (is_wp_error($upd)) {
            return $upd;
        }
        $did_update = true;
    }
    foreach (['member_title', 'member_linkedin_url', 'member_github_url'] as $field) {
        $value = $request->get_param($field);
        if (is_string($value) && function_exists('update_field')) {
            update_field($field, $value, $target_post_id);
            $did_update = true;
        }
    }

    // No-op PATCH (no mutable field supplied) → don't burn OpenAI
    // quota on five re-translation jobs that would produce identical
    // output. Return the same envelope shape for caller stability.
    if (!$did_update) {
        return rest_ensure_response([
            'post_id' => $target_post_id,
            'queued'  => [],
            'errors'  => [],
        ]);
    }

    // Fan out re-translation to the other (n-1) languages, reusing the
    // queue + lock infrastructure. Each non-source-lang sibling gets a
    // re-translation job pointing at the just-edited post as source.
    $queued = [];
    $errors = [];
    foreach ($group as $other_lang => $other_post_id) {
        if ($other_lang === $requested_lang) {
            continue;
        }
        if (!function_exists('cdcf_enqueue_post_translation')) {
            $errors[] = sprintf('translation enqueue helper unavailable for %s', $other_lang);
            continue;
        }
        $result = cdcf_enqueue_post_translation(
            $target_post_id,
            $other_lang,
            $other_post_id
        );
        if (is_wp_error($result)) {
            $errors[] = sprintf('%s: %s', $other_lang, $result->get_error_message());
        } else {
            $queued[] = $other_lang;
        }
    }

    return rest_ensure_response([
        'post_id' => $target_post_id,
        'queued'  => $queued,
        'errors'  => $errors,
    ]);
}
