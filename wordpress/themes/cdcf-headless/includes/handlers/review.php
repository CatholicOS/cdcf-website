<?php
/**
 * REST handlers for council review of submitted projects.
 *
 *   GET   /cdcf/v1/review/me                      membership flags
 *   GET   /cdcf/v1/review/projects?stage=         list
 *   GET   /cdcf/v1/review/projects/{id}           one project's workspace
 *   PUT   /cdcf/v1/review/projects/{id}/my-review create/update own review + vote
 *   POST  /cdcf/v1/review/projects/{id}/entries   reply or discussion post
 *   PATCH /cdcf/v1/review/entries/{id}            edit own reply/discussion post
 *
 * Callers authenticate with a Zitadel bearer token (zitadel-bearer.php);
 * membership comes from includes/review/membership.php. Request fields
 * are sanitized by the args blocks in functions.php.
 */

defined('ABSPATH') || exit;

/** @return true|WP_Error */
function cdcf_review_check_permission(string $permission) {
    if (!is_user_logged_in()) {
        return new WP_Error('rest_not_logged_in', 'Authentication required.', ['status' => 401]);
    }
    if ($permission !== 'signed_in' && !cdcf_review_can(get_current_user_id(), $permission)) {
        return new WP_Error('rest_forbidden', 'Your account is not permitted to do this in project review.', ['status' => 403]);
    }
    return true;
}

function cdcf_rest_review_permission_signed_in(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('signed_in');
}

function cdcf_rest_review_permission_view(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('view');
}

function cdcf_rest_review_permission_vote(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('vote');
}

function cdcf_rest_review_permission_discuss(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('discuss');
}

/** @return object|WP_Error The post, or 404 when it is not under review. */
function cdcf_review_get_reviewable_project(int $project_id) {
    $post = $project_id > 0 ? get_post($project_id) : null;
    $ok = $post
        && in_array($post->post_type, ['project', 'community_project'], true)
        && $post->post_status !== 'trash'
        && in_array((string) get_post_meta($project_id, CDCF_REVIEW_STAGE_META, true), CDCF_REVIEW_STAGES, true);
    if (!$ok) {
        return new WP_Error('review_not_found', 'No project under review with that id.', ['status' => 404]);
    }
    return $post;
}

/** @return true|WP_Error */
function cdcf_review_assert_open(int $project_id) {
    if (get_post_meta($project_id, CDCF_REVIEW_STAGE_META, true) === 'decided') {
        return new WP_Error('review_locked', 'This review has been decided and can no longer be changed.', ['status' => 403]);
    }
    return true;
}

function cdcf_review_post_language(int $project_id): string {
    $lang = function_exists('pll_get_post_language') ? pll_get_post_language($project_id, 'slug') : '';
    return is_string($lang) && $lang !== '' ? $lang : 'en';
}

/** @return array{url: string, category: string, repo_urls: list<string>, tags: list<string>} */
function cdcf_review_submission_fields(int $project_id): array {
    $category = get_field('project_category', $project_id);
    if (is_array($category)) {
        $category = reset($category);
    }
    $repo_urls = json_decode((string) get_post_meta($project_id, '_submission_repo_urls', true), true);
    if (!is_array($repo_urls) || !$repo_urls) {
        $single    = (string) get_field('project_repo_url', $project_id);
        $repo_urls = $single !== '' ? [$single] : [];
    }
    $tags = wp_get_object_terms($project_id, 'project_tag', ['fields' => 'names']);
    return [
        'url'       => (string) get_field('project_url', $project_id),
        'category'  => is_string($category) ? $category : '',
        'repo_urls' => array_values(array_map('strval', $repo_urls)),
        'tags'      => is_array($tags) ? array_values($tags) : [],
    ];
}

function cdcf_review_present_entry(array $row): array {
    $author = null;
    if ($row['author_user_id'] !== null) {
        $uid    = (int) $row['author_user_id'];
        $user   = get_userdata($uid);
        $author = [
            'id'       => $uid,
            'name'     => $user ? (string) $user->display_name : 'Former member',
            'councils' => array_keys(array_filter(cdcf_review_membership($uid))),
        ];
    }
    return [
        'id'          => (int) $row['id'],
        'kind'        => (string) $row['kind'],
        'parent_id'   => $row['parent_id'],
        'body_md'     => (string) $row['body_md'],
        'vote'        => $row['vote'],
        'author'      => $author,
        'created_at'  => (string) $row['created_at'],
        'edited_at'   => $row['edited_at'],
        'attachments' => [],
    ];
}

function cdcf_rest_review_me(WP_REST_Request $request) {
    unset($request);
    $uid = get_current_user_id();
    $m   = cdcf_review_membership($uid);
    $can = [];
    foreach (['view', 'discuss', 'vote', 'decide'] as $permission) {
        $can[$permission] = cdcf_review_can($uid, $permission);
    }
    return rest_ensure_response([
        'member'   => $can['view'],
        'councils' => array_keys(array_filter($m)),
        'can'      => $can,
    ]);
}

function cdcf_rest_review_list_projects(WP_REST_Request $request) {
    $query = [
        'post_type'      => ['project', 'community_project'],
        'post_status'    => 'any',
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_key'       => CDCF_REVIEW_STAGE_META,
    ];
    $stage = (string) $request['stage'];
    if ($stage !== '') {
        $query['meta_value'] = $stage;
    }
    $posts = get_posts($query);
    $ids   = array_map(static fn($p): int => (int) $p->ID, $posts);
    $uid   = get_current_user_id();

    $tallies = cdcf_review_tallies($ids);
    $mine    = cdcf_review_votes_by_user($uid, $ids);
    $unread  = cdcf_review_unread_counts($uid, $ids);
    $voters  = count(cdcf_review_member_user_ids('vote'));

    $items = [];
    foreach ($posts as $post) {
        $pid   = (int) $post->ID;
        $tally = $tallies[$pid] ?? cdcf_review_empty_tally();
        $items[] = [
            'id'             => $pid,
            'title'          => (string) $post->post_title,
            'category'       => cdcf_review_submission_fields($pid)['category'],
            'language'       => cdcf_review_post_language($pid),
            'submitted_at'   => (string) $post->post_date_gmt,
            'stage'          => (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true),
            'tally'          => $tally,
            'voters_pending' => max(0, $voters - array_sum($tally)),
            'my_vote'        => $mine[$pid] ?? null,
            'unread'         => $unread[$pid] ?? 0,
        ];
    }
    return rest_ensure_response(['projects' => $items]);
}

function cdcf_rest_review_get_project(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $post = cdcf_review_get_reviewable_project($pid);
    if (is_wp_error($post)) {
        return $post;
    }
    $uid = get_current_user_id();

    $reviews = $questions = $discussion = $replies = $answers = [];
    foreach (cdcf_review_project_entries($pid) as $row) {
        $entry = cdcf_review_present_entry($row);
        switch ($row['kind']) {
            case 'review':
                $reviews[$entry['id']] = $entry + ['replies' => []];
                break;
            case 'question':
                $questions[$entry['id']] = $entry + ['answers' => []];
                break;
            case 'reply':
                $replies[] = $entry;
                break;
            case 'answer':
                $answers[] = $entry;
                break;
            case 'discussion':
                $discussion[] = $entry;
                break;
        }
    }
    foreach ($replies as $reply) {
        if (isset($reviews[$reply['parent_id']])) {
            $reviews[$reply['parent_id']]['replies'][] = $reply;
        }
    }
    foreach ($answers as $answer) {
        if (isset($questions[$answer['parent_id']])) {
            $questions[$answer['parent_id']]['answers'][] = $answer;
        }
    }

    $tally      = cdcf_review_tallies([$pid])[$pid] ?? cdcf_review_empty_tally();
    $can_decide = cdcf_review_can($uid, 'decide');
    $mine       = cdcf_review_find_review($pid, $uid);

    return rest_ensure_response([
        'id'             => $pid,
        'title'          => (string) $post->post_title,
        'content'        => (string) $post->post_content,
        'post_type'      => (string) $post->post_type,
        'post_status'    => (string) $post->post_status,
        'stage'          => (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true),
        'language'       => cdcf_review_post_language($pid),
        'submitted_at'   => (string) $post->post_date_gmt,
        'fields'         => cdcf_review_submission_fields($pid),
        'submitter'      => [
            'name'  => (string) get_post_meta($pid, '_submission_submitter_name', true),
            'email' => $can_decide ? (string) get_post_meta($pid, '_submission_submitter_email', true) : null,
        ],
        'tally'          => $tally,
        'plurality'      => cdcf_review_plurality($tally),
        'voters_pending' => max(0, count(cdcf_review_member_user_ids('vote')) - array_sum($tally)),
        'reviews'        => array_values($reviews),
        'discussion'     => $discussion,
        'questions'      => array_values($questions),
        'me'             => [
            'can_vote'   => cdcf_review_can($uid, 'vote'),
            'can_decide' => $can_decide,
            'review_id'  => $mine['id'] ?? null,
        ],
    ]);
}
