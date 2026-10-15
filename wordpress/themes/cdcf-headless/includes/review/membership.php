<?php
/**
 * Council review — who may review.
 *
 * Membership is derived, not granted: a user is on a council when their
 * `author_team_member` bio (any language) is listed in the English About
 * page's council relationship field. Adding someone to a council on the
 * About page grants access; removing them revokes it.
 *
 * Results are cached per user in a transient whose key embeds a
 * generation counter. Saving an About page or changing any user's
 * `author_team_member` link bumps the counter, so roster changes apply
 * immediately; the one-hour TTL only bounds stale-cache garbage.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_COUNCIL_FIELDS = [
    'technical' => 'technical_council',
    'ecclesial' => 'ecclesial_council',
    'board'     => 'team_members',
];

/**
 * @return array{technical: list<int>, ecclesial: list<int>, board: list<int>}
 */
function cdcf_review_council_ids(): array {
    $out = ['technical' => [], 'ecclesial' => [], 'board' => []];
    $about_id = cdcf_my_team_member_get_english_about_page_id();
    if ($about_id <= 0 || !function_exists('get_field')) {
        return $out;
    }
    foreach (CDCF_REVIEW_COUNCIL_FIELDS as $council => $field) {
        $ids = get_field($field, $about_id, false);
        $out[$council] = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }
    return $out;
}

function cdcf_review_membership_cache_key(int $user_id): string {
    return 'cdcf_review_membership_' . (int) get_option('cdcf_review_membership_gen', 0) . '_' . $user_id;
}

/**
 * @return array{technical: bool, ecclesial: bool, board: bool}
 */
function cdcf_review_membership(int $user_id): array {
    $flags = ['technical' => false, 'ecclesial' => false, 'board' => false];
    if ($user_id <= 0) {
        return $flags;
    }
    $key    = cdcf_review_membership_cache_key($user_id);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    $link = cdcf_my_team_member_resolve_link($user_id);
    if ($link > 0) {
        $group      = cdcf_my_team_member_collect_group($link);
        $candidates = $group ? array_values($group) : [$link];
        foreach (cdcf_review_council_ids() as $council => $ids) {
            $flags[$council] = (bool) array_intersect($candidates, $ids);
        }
    }
    set_transient($key, $flags, HOUR_IN_SECONDS);
    return $flags;
}

function cdcf_review_can(int $user_id, string $permission): bool {
    $m = cdcf_review_membership($user_id);
    return match ($permission) {
        'view', 'discuss' => $m['technical'] || $m['ecclesial'] || $m['board'],
        'vote'            => $m['technical'] || $m['ecclesial'],
        'decide'          => $m['board'],
        default           => false,
    };
}

/**
 * Every WP user holding $permission. Only users with an
 * `author_team_member` link can be members, so that meta key bounds the
 * scan to a handful of accounts.
 *
 * @return list<int>
 */
function cdcf_review_member_user_ids(string $permission = 'view'): array {
    $out = [];
    foreach (get_users(['meta_key' => 'author_team_member', 'fields' => 'ID']) as $uid) {
        $uid = (int) $uid;
        if (cdcf_review_can($uid, $permission)) {
            $out[] = $uid;
        }
    }
    return $out;
}

function cdcf_review_bump_membership_generation(): void {
    update_option(
        'cdcf_review_membership_gen',
        (int) get_option('cdcf_review_membership_gen', 0) + 1,
        false
    );
}

/** acf/save_post callback. ACF also passes 'user_5', 'options', etc. */
function cdcf_review_flush_on_about_save($post_id): void {
    if (!is_int($post_id) && !ctype_digit((string) $post_id)) {
        return;
    }
    if (get_page_template_slug((int) $post_id) === 'templates/about.php') {
        cdcf_review_bump_membership_generation();
    }
}

/** added_/updated_/deleted_user_meta callback. */
function cdcf_review_flush_on_user_meta($meta_id, $user_id, $meta_key): void {
    if ($meta_key === 'author_team_member') {
        cdcf_review_bump_membership_generation();
    }
}

/**
 * Every council and Board seat with the WP user linked to it (0 when
 * none), for the Review access admin screen.
 *
 * @return list<array{council: string, team_member_id: int, name: string, user_id: int}>
 */
function cdcf_review_roster(): array {
    $user_by_team_member = [];
    foreach (get_users(['meta_key' => 'author_team_member', 'fields' => 'ID']) as $uid) {
        $uid  = (int) $uid;
        $link = cdcf_my_team_member_resolve_link($uid);
        if ($link <= 0) {
            continue;
        }
        foreach (cdcf_my_team_member_collect_group($link) ?: [$link] as $team_member_id) {
            $user_by_team_member[(int) $team_member_id] = $uid;
        }
    }
    $rows = [];
    foreach (cdcf_review_council_ids() as $council => $ids) {
        foreach ($ids as $team_member_id) {
            $rows[] = [
                'council'        => $council,
                'team_member_id' => $team_member_id,
                'name'           => get_the_title($team_member_id),
                'user_id'        => $user_by_team_member[$team_member_id] ?? 0,
            ];
        }
    }
    return $rows;
}
