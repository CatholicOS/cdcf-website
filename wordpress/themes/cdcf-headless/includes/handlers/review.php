<?php
/**
 * REST handlers for council review of submitted projects.
 *
 *   GET   /cdcf/v1/review/me                      membership flags
 *   GET   /cdcf/v1/review/projects?stage=&page=   list (paged, has_more)
 *   GET   /cdcf/v1/review/projects/{id}           one project's workspace
 *   GET   /cdcf/v1/review/inbox?unread=&page=     the caller's notifications (paged)
 *   GET   /cdcf/v1/review/inbox/count             unread total for the bell
 *   POST  /cdcf/v1/review/inbox/read              mark event_ids, or all, read
 *   PUT   /cdcf/v1/review/projects/{id}/my-review create/update own review + vote
 *   POST  /cdcf/v1/review/projects/{id}/entries   reply or discussion post
 *   PATCH /cdcf/v1/review/entries/{id}            edit own reply/discussion post
 *   POST  /cdcf/v1/review/entries/{id}/attachments upload (multipart or pasted Markdown)
 *   GET   /cdcf/v1/review/attachments/{id}       stream an attachment
 *   POST  /cdcf/v1/review/projects/{id}/decision  Board decision (outcome, rationale, submitter summary)
 *
 * Callers authenticate with a Zitadel bearer token (zitadel-bearer.php);
 * membership comes from includes/review/membership.php. Request fields
 * are sanitized by the args blocks in functions.php.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_SUMMARY_MAX = 10000;

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

function cdcf_rest_review_permission_decide(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('decide');
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

function cdcf_review_present_entry(array $row, array $attachments = []): array {
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
        'version'     => cdcf_review_entry_version($row),
        'attachments' => array_map('cdcf_review_present_attachment', $attachments),
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
        'user_id'  => $uid,
        'member'   => $can['view'],
        'councils' => array_keys(array_filter($m)),
        'can'      => $can,
    ]);
}

function cdcf_rest_review_list_projects(WP_REST_Request $request) {
    $page  = max(1, (int) ($request['page'] ?? 1));
    $query = [
        'post_type'      => ['project', 'community_project'],
        'post_status'    => 'any',
        // One extra row tells us whether another page exists without a
        // COUNT query; it is dropped before building the response.
        'posts_per_page' => CDCF_REVIEW_LIST_PAGE_SIZE + 1,
        'offset'         => ($page - 1) * CDCF_REVIEW_LIST_PAGE_SIZE,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_key'       => CDCF_REVIEW_STAGE_META,
        'lang'           => '', // all languages; Polylang would filter to the current one
    ];
    $stage = (string) $request['stage'];
    if ($stage !== '') {
        $query['meta_value'] = $stage;
    }
    $posts    = get_posts($query);
    $has_more = count($posts) > CDCF_REVIEW_LIST_PAGE_SIZE;
    $posts    = array_slice($posts, 0, CDCF_REVIEW_LIST_PAGE_SIZE);
    $ids      = array_map(static fn($p): int => (int) $p->ID, $posts);
    $uid   = get_current_user_id();

    $tallies = cdcf_review_tallies($ids);
    $mine    = cdcf_review_votes_by_user($uid, $ids);
    $unread  = cdcf_review_unread_counts($uid, $ids);
    $voters  = cdcf_review_member_user_ids('vote');
    $voted   = cdcf_review_voter_ids($ids);

    $items = [];
    foreach ($posts as $post) {
        $pid   = (int) $post->ID;
        $tally = $tallies[$pid] ?? cdcf_review_empty_tally();
        $items[] = [
            'id'             => $pid,
            'title'          => (string) $post->post_title,
            'category'       => cdcf_review_submission_fields($pid)['category'],
            'language'       => cdcf_review_post_language($pid),
            'submitted_at'   => cdcf_review_submitted_at($post),
            'stage'          => (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true),
            'tally'          => $tally,
            'voters_pending' => cdcf_review_pending_count($voters, $voted[$pid] ?? []),
            'my_vote'        => $mine[$pid] ?? null,
            'unread'         => $unread[$pid] ?? 0,
        ];
    }
    return rest_ensure_response(['projects' => $items, 'page' => $page, 'has_more' => $has_more]);
}

function cdcf_rest_review_get_project(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $post = cdcf_review_get_reviewable_project($pid);
    if (is_wp_error($post)) {
        return $post;
    }
    $uid = get_current_user_id();
    // Opening the workspace is reading its activity.
    cdcf_review_mark_read($uid, null, $pid);

    $rows        = cdcf_review_project_entries($pid);
    $attachments = cdcf_review_attachments_for_entries(array_column($rows, 'id'));

    $reviews = $questions = $discussion = $replies = $answers = [];
    foreach ($rows as $row) {
        $entry = cdcf_review_present_entry($row, $attachments[$row['id']] ?? []);
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
    $stage      = (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true);
    $mine       = cdcf_review_find_review($pid, $uid);

    return rest_ensure_response([
        'id'             => $pid,
        'title'          => (string) $post->post_title,
        'content'        => (string) $post->post_content,
        'post_type'      => (string) $post->post_type,
        'post_status'    => (string) $post->post_status,
        'stage'          => $stage,
        'language'       => cdcf_review_post_language($pid),
        'submitted_at'   => cdcf_review_submitted_at($post),
        'fields'         => cdcf_review_submission_fields($pid),
        'submitter'      => [
            'name'  => (string) get_post_meta($pid, '_submission_submitter_name', true),
            'email' => $can_decide ? (string) get_post_meta($pid, '_submission_submitter_email', true) : null,
        ],
        'tally'          => $tally,
        'plurality'      => cdcf_review_plurality($tally),
        'voters_pending' => cdcf_review_pending_count(cdcf_review_member_user_ids('vote'), cdcf_review_voter_ids([$pid])[$pid] ?? []),
        'reviews'        => array_values($reviews),
        'discussion'     => $discussion,
        'questions'      => array_values($questions),
        'decision'       => $stage === 'decided' ? cdcf_review_present_decision($pid) : null,
        'decision_email' => $can_decide && $stage !== 'decided' ? cdcf_review_decision_email_preview($pid) : null,
        'me'             => [
            'can_vote'   => cdcf_review_can($uid, 'vote'),
            'can_decide' => $can_decide,
            'review_id'  => $mine['id'] ?? null,
        ],
    ]);
}

/**
 * sanitize_callback for Markdown bodies. Markdown is stored verbatim and
 * rendered with raw HTML disabled (Phase 2), so sanitizing means only:
 * reject invalid UTF-8, drop NUL bytes, trim.
 */
function cdcf_review_sanitize_markdown($value): string {
    if (!is_string($value)) {
        return '';
    }
    return trim(str_replace("\0", '', wp_check_invalid_utf8($value)));
}

/**
 * Current voting members who have not voted. Votes from former members
 * still count in the tally but do not stand in for anyone still seated.
 *
 * @param list<int> $voters
 * @param list<int> $voted
 */
/**
 * Submission time in GMT. WordPress leaves post_date_gmt zeroed on pending
 * posts (every public submission starts pending), so fall back to the
 * site-local post_date converted to GMT.
 */
function cdcf_review_submitted_at(object $post): string {
    $gmt = (string) $post->post_date_gmt;
    if ($gmt !== '' && $gmt !== '0000-00-00 00:00:00') {
        return $gmt;
    }
    return (string) get_gmt_from_date((string) $post->post_date);
}

function cdcf_review_pending_count(array $voters, array $voted): int {
    return count(array_diff($voters, $voted));
}

/** @return true|WP_Error */
function cdcf_review_validate_body(string $body) {
    // trim() misses NBSP, ideographic spaces and zero-width characters.
    if (preg_replace('/[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $body) === '') {
        return new WP_Error('empty_body', 'Write something before posting.', ['status' => 400]);
    }
    if (mb_strlen($body) > CDCF_REVIEW_BODY_MAX) {
        return new WP_Error(
            'body_too_long',
            sprintf('Text is limited to %d characters. Attach longer documents as a Markdown file.', CDCF_REVIEW_BODY_MAX),
            ['status' => 400]
        );
    }
    return true;
}

/** @return WP_REST_Response|WP_Error */
function cdcf_review_entry_response(int $id, int $status) {
    $entry = cdcf_review_get_entry($id);
    if ($entry === null) {
        return new WP_Error('entry_missing', 'The entry was saved but could not be read back.', ['status' => 500]);
    }
    $attachments = cdcf_review_attachments_for_entries([$id])[$id] ?? [];
    return new WP_REST_Response(cdcf_review_present_entry($entry, $attachments), $status);
}

/** @return true|WP_Error First error among the reviewable/open/body checks. */
function cdcf_review_guard_write(int $project_id, string $body) {
    foreach ([
        cdcf_review_get_reviewable_project($project_id),
        cdcf_review_assert_open($project_id),
        cdcf_review_validate_body($body),
    ] as $result) {
        if (is_wp_error($result)) {
            return $result;
        }
    }
    return true;
}

function cdcf_rest_review_put_my_review(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $body = (string) $request['body_md'];
    $ok   = cdcf_review_guard_write($pid, $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    $vote = (string) $request['vote'];
    if (!in_array($vote, CDCF_REVIEW_VOTES, true)) {
        return new WP_Error('invalid_vote', 'Vote must be cdcf, community or reject.', ['status' => 400]);
    }
    $uid      = get_current_user_id();
    $existing = cdcf_review_find_review($pid, $uid);

    if ($existing === null) {
        $id = cdcf_review_insert_entry([
            'project_id'     => $pid,
            'kind'           => 'review',
            'author_user_id' => $uid,
            'body_md'        => $body,
            'vote'           => $vote,
        ]);
        if ($id <= 0) {
            // A concurrent first save by the same reviewer won the
            // one_review unique key.
            if (cdcf_review_find_review($pid, $uid) !== null) {
                return new WP_Error('review_exists', 'Your review was already saved. Reload to edit it.', ['status' => 409]);
            }
            return new WP_Error('insert_failed', 'Could not save the review.', ['status' => 500]);
        }
        cdcf_review_record_event($pid, 'review_posted', $uid, $id);
        return cdcf_review_entry_response($id, 201);
    }

    $expected = (string) ($request['version'] ?? '');
    if ($expected === '') {
        // Another tab or device created this review after the caller
        // loaded the page; never overwrite it blind.
        return new WP_Error('review_exists', 'Your review was already saved. Reload to edit it.', ['status' => 409]);
    }
    $stale = new WP_Error('stale_entry', 'This review changed since you opened it. Reload and try again.', ['status' => 409]);
    if (cdcf_review_entry_version($existing) !== $expected) {
        return $stale;
    }
    if (!cdcf_review_update_entry($existing['id'], ['body_md' => $body, 'vote' => $vote], $expected)) {
        return $stale;
    }
    if ($existing['vote'] !== $vote) {
        cdcf_review_record_event($pid, 'vote_changed', $uid, $existing['id'], ['from' => $existing['vote'], 'to' => $vote]);
    }
    if ($existing['body_md'] !== $body) {
        cdcf_review_record_event($pid, 'review_edited', $uid, $existing['id']);
    }
    return cdcf_review_entry_response($existing['id'], 200);
}

function cdcf_rest_review_post_entry(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $body = (string) $request['body_md'];
    $ok   = cdcf_review_guard_write($pid, $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    $kind = (string) $request['kind'];
    if (!in_array($kind, ['reply', 'discussion'], true)) {
        return new WP_Error('invalid_kind', 'Only replies and discussion posts can be created here.', ['status' => 400]);
    }
    $parent_id = null;
    if ($kind === 'reply') {
        $parent = cdcf_review_get_entry((int) $request['parent_id']);
        if ($parent === null || $parent['kind'] !== 'review' || $parent['project_id'] !== $pid) {
            return new WP_Error('invalid_parent', 'Replies must answer a review on this project.', ['status' => 400]);
        }
        $parent_id = $parent['id'];
    }
    $uid = get_current_user_id();
    $id  = cdcf_review_insert_entry([
        'project_id'     => $pid,
        'kind'           => $kind,
        'parent_id'      => $parent_id,
        'author_user_id' => $uid,
        'body_md'        => $body,
    ]);
    if ($id <= 0) {
        return new WP_Error('insert_failed', 'Could not save the post.', ['status' => 500]);
    }
    cdcf_review_record_event($pid, $kind === 'reply' ? 'reply_posted' : 'discussion_posted', $uid, $id);
    return cdcf_review_entry_response($id, 201);
}

function cdcf_rest_review_patch_entry(WP_REST_Request $request) {
    $entry = cdcf_review_get_entry((int) $request['id']);
    if ($entry === null) {
        return new WP_Error('entry_not_found', 'No such entry.', ['status' => 404]);
    }
    if ($entry['author_user_id'] !== get_current_user_id()) {
        return new WP_Error('not_author', 'You can only edit your own posts.', ['status' => 403]);
    }
    if (!in_array($entry['kind'], ['reply', 'discussion'], true)) {
        return new WP_Error('use_my_review', 'Edit your review through the my-review endpoint.', ['status' => 400]);
    }
    $body = (string) $request['body_md'];
    $ok   = cdcf_review_guard_write($entry['project_id'], $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    $expected = (string) ($request['version'] ?? '');
    if (cdcf_review_entry_version($entry) !== $expected
        || !cdcf_review_update_entry($entry['id'], ['body_md' => $body], $expected)) {
        return new WP_Error('stale_entry', 'This post changed since you opened it. Reload and try again.', ['status' => 409]);
    }
    return cdcf_review_entry_response($entry['id'], 200);
}

/** POST /review/projects/{id}/question — ask the submitter (emailed verbatim). */
function cdcf_rest_review_post_question(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $body = trim((string) $request['body']);
    $ok   = cdcf_review_guard_write($pid, $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    if (!is_email((string) get_post_meta($pid, '_submission_submitter_email', true))) {
        return new WP_Error('no_submitter_email', 'This submission has no submitter email to send the question to.', ['status' => 409]);
    }
    $uid = get_current_user_id();
    $id  = cdcf_review_insert_entry([
        'project_id' => $pid, 'kind' => 'question', 'author_user_id' => $uid, 'body_md' => $body,
    ]);
    if ($id <= 0) {
        return new WP_Error('insert_failed', 'Could not save the question.', ['status' => 500]);
    }
    update_post_meta($pid, CDCF_REVIEW_STAGE_META, 'awaiting_submitter');
    cdcf_review_record_event($pid, 'question_sent', $uid, $id);

    $email    = cdcf_review_send_submitter_email($pid, 'question', ['question' => $body]);
    $response = cdcf_review_entry_response($id, 201);
    if (is_wp_error($response)) {
        return $response;
    }
    $response->set_data($response->get_data() + [
        'warnings' => in_array($email, ['failed', 'no_email'], true) ? ['email_failed'] : [],
    ]);
    return $response;
}

/**
 * Lock against two Board members deciding at once. A MySQL named lock is
 * atomic (unlike add_post_meta's SELECT-then-INSERT) and is released by
 * the server when the connection ends, so a crashed request never leaves
 * it held. Timeout 0: a second decider is refused, not queued.
 */
function cdcf_review_take_decision_lock(int $project_id): bool {
    global $wpdb;
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', 'cdcf_review_decide_' . $project_id));
    return (string) $got === '1';
}

function cdcf_review_release_decision_lock(int $project_id): void {
    global $wpdb;
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'cdcf_review_decide_' . $project_id));
}

function cdcf_rest_review_post_decision(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $post = cdcf_review_get_reviewable_project($pid);
    if (is_wp_error($post)) {
        return $post;
    }
    $open = cdcf_review_assert_open($pid);
    if (is_wp_error($open)) {
        return $open;
    }
    $decision  = (string) $request['decision'];
    $rationale = trim((string) $request['rationale']);
    $summary   = trim((string) $request['submitter_summary']);
    if ($summary === '') {
        return new WP_Error('summary_required', 'Write a summary for the submitter.', ['status' => 400]);
    }
    if (mb_strlen($summary) > CDCF_REVIEW_SUMMARY_MAX || mb_strlen($rationale) > CDCF_REVIEW_BODY_MAX) {
        return new WP_Error('body_too_long', 'This text is too long.', ['status' => 400]);
    }
    $tally     = cdcf_review_tallies([$pid])[$pid] ?? cdcf_review_empty_tally();
    $plurality = cdcf_review_plurality($tally);
    if ($decision !== $plurality && $rationale === '') {
        return new WP_Error('rationale_required', 'Explain why the decision differs from the councils\' plurality vote.', ['status' => 400]);
    }
    if (!cdcf_review_take_decision_lock($pid)) {
        return new WP_Error('decision_in_progress', 'Another decision on this project is being recorded.', ['status' => 409]);
    }
    // A concurrent request may have decided between the early check and the lock.
    wp_cache_delete($pid, 'post_meta');
    $open = cdcf_review_assert_open($pid);
    if (is_wp_error($open)) {
        cdcf_review_release_decision_lock($pid);
        return $open;
    }
    try {
        $applied = cdcf_review_apply_outcome($pid, $decision);
        if (is_wp_error($applied)) {
            return $applied;
        }
        $uid = get_current_user_id();
        cdcf_review_record_decision($pid, $decision, $uid, $rationale, $summary);
    } finally {
        cdcf_review_release_decision_lock($pid);
    }
    // After the lock: recording the event emails members, inline when the
    // queue is unavailable, and SMTP must not hold up another decider.
    cdcf_review_record_event($pid, 'decision_recorded', $uid, null, ['decision' => $decision, 'plurality' => $plurality]);

    $email = cdcf_review_send_submitter_email($pid, 'decision', ['outcome' => $decision, 'summary' => $summary]);
    return rest_ensure_response([
        'id'          => $pid,
        'stage'       => 'decided',
        'decision'    => $decision,
        'post_type'   => (string) get_post_type($pid),
        'post_status' => (string) get_post_status($pid),
        'warnings'    => in_array($email, ['failed', 'no_email'], true) ? ['email_failed'] : [],
    ]);
}

/** @return array{language: string, subject: string, body: string, outcomes: array<string, string>} */
function cdcf_review_decision_email_preview(int $project_id): array {
    $lang = cdcf_review_post_language($project_id);
    $mail = cdcf_review_render_email('decision', $lang, [
        'title'   => (string) get_post_field('post_title', $project_id),
        'link'    => '{link}',
        'outcome' => '{outcome}',
        'summary' => '{summary}',
    ]);
    $labels = cdcf_review_outcome_labels();
    return $mail + ['language' => $lang, 'outcomes' => $labels[$lang] ?? $labels['en']];
}

/**
 * REST args per review route, keyed by route path. Lives here (not in
 * functions.php) so tests can assert on it. Every arg that carries an
 * `enum` also sets `validate_callback => rest_validate_request_arg`:
 * once a custom sanitize_callback is present, core no longer runs schema
 * validation (type/enum) on its own.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function cdcf_review_route_args(): array {
    $id = [
        'required'          => true,
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
    ];
    $body = [
        'required'          => true,
        'type'              => 'string',
        'sanitize_callback' => 'cdcf_review_sanitize_markdown',
    ];
    $version = [
        'required'          => false,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ];
    return [
        '/review/me'       => [],
        '/review/inbox' => [
            'unread' => [
                'required'          => false,
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'page'   => [
                'required'          => false,
                'type'              => 'integer',
                'minimum'           => 1,
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ],
        '/review/inbox/count' => [],
        '/review/inbox/read'  => [
            'event_ids' => [
                'required'          => false,
                'type'              => 'array',
                'items'             => ['type' => 'integer'],
                'maxItems'          => 200,
                'default'           => [],
                'sanitize_callback' => 'cdcf_review_sanitize_id_list',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'all'       => [
                'required'          => false,
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
        ],
        '/review/projects' => [
            'stage' => [
                'required'          => false,
                'type'              => 'string',
                'enum'              => array_merge([''], CDCF_REVIEW_STAGES),
                'default'           => '',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'page'  => [
                'required'          => false,
                'type'              => 'integer',
                'minimum'           => 1,
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ],
        '/review/projects/(?P<id>\d+)'           => ['id' => $id],
        '/review/projects/(?P<id>\d+)/my-review' => [
            'id'      => $id,
            'vote'    => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => CDCF_REVIEW_VOTES,
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'body_md' => $body,
            'version' => $version,
        ],
        '/review/projects/(?P<id>\d+)/entries' => [
            'id'        => $id,
            'kind'      => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => ['reply', 'discussion'],
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'parent_id' => [
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ],
            'body_md'   => $body,
        ],
        '/review/projects/(?P<id>\d+)/question' => [
            'id'   => $id,
            'body' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
        ],
        '/review/projects/(?P<id>\d+)/decision' => [
            'id'                => $id,
            'decision'          => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => CDCF_REVIEW_VOTES,
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'rationale'         => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'cdcf_review_sanitize_markdown'],
            'submitter_summary' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'cdcf_review_sanitize_markdown'],
        ],
        '/review/entries/(?P<id>\d+)' => [
            'id'      => $id,
            'body_md' => $body,
            'version' => $version,
        ],
        '/review/entries/(?P<id>\d+)/attachments' => [
            'id'       => $id,
            'title'    => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ],
            'markdown' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'cdcf_review_sanitize_markdown',
                'default'           => '',
            ],
        ],
        '/review/attachments/(?P<id>\d+)' => [
            'id'       => $id,
            'download' => [
                'required'          => false,
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ],
    ];
}

const CDCF_REVIEW_FILE_MARKER = '__cdcf_review_file';

/**
 * POST /review/entries/{id}/attachments — a multipart `file`, or JSON
 * `{title, markdown}` for pasted Markdown. Only the entry's author may
 * attach, and only while the review is open.
 */
function cdcf_rest_review_post_attachment(WP_REST_Request $request) {
    $entry = cdcf_review_get_entry((int) $request['id']);
    if ($entry === null) {
        return new WP_Error('entry_not_found', 'No such entry.', ['status' => 404]);
    }
    if ($entry['author_user_id'] !== get_current_user_id()) {
        return new WP_Error('not_author', 'You can only attach files to your own posts.', ['status' => 403]);
    }
    foreach ([cdcf_review_get_reviewable_project($entry['project_id']), cdcf_review_assert_open($entry['project_id'])] as $check) {
        if (is_wp_error($check)) {
            return $check;
        }
    }
    if (cdcf_review_count_attachments($entry['id']) >= CDCF_REVIEW_ATTACHMENTS_PER_ENTRY) {
        return new WP_Error('too_many_attachments', 'This post already has the maximum number of attachments.', ['status' => 400]);
    }

    $files = $request->get_file_params();
    if (!empty($files['file'])) {
        $file = $files['file'];
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return new WP_Error('file_too_large', 'The file is too large.', ['status' => 413]);
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !cdcf_review_is_uploaded_file((string) $file['tmp_name'])) {
            return new WP_Error('upload_failed', 'The upload did not complete.', ['status' => 400]);
        }
        $source = (string) $file['tmp_name'];
        $name   = sanitize_file_name((string) $file['name']);
    } else {
        $markdown = (string) $request['markdown'];
        if ($markdown === '') {
            return new WP_Error('empty_file', 'Paste some Markdown or choose a file.', ['status' => 400]);
        }
        $source = tempnam(get_temp_dir(), 'cdcfmd');
        if ($source === false || file_put_contents($source, $markdown) !== strlen($markdown)) {
            if ($source !== false) {
                wp_delete_file($source);
            }
            return new WP_Error('store_failed', 'The attachment could not be saved.', ['status' => 500]);
        }
        $name = cdcf_review_markdown_filename((string) $request['title']);
    }

    $meta = cdcf_review_validate_upload($source, $name);
    if (is_wp_error($meta)) {
        wp_delete_file($source);
        return $meta;
    }
    $key = cdcf_review_store_file($source);
    if (is_wp_error($key)) {
        wp_delete_file($source);
        return $key;
    }
    $id = cdcf_review_insert_attachment($entry['id'], $meta, $name, $key);
    if ($id <= 0) {
        wp_delete_file(cdcf_review_attachment_path(['storage_key' => $key]));
        return new WP_Error('insert_failed', 'The attachment could not be saved.', ['status' => 500]);
    }
    cdcf_review_record_event($entry['project_id'], 'attachment_added', get_current_user_id(), $entry['id'], ['attachment_id' => $id]);
    return new WP_REST_Response(cdcf_review_present_attachment([
        'id' => $id, 'kind' => $meta['kind'], 'mime' => $meta['mime'], 'original_name' => $name, 'size' => $meta['size'],
    ]), 201);
}

/**
 * GET /review/attachments/{id}[?download=1] — the file bytes. The REST
 * response carries headers plus a marker; cdcf_review_serve_attachment()
 * (rest_pre_serve_request) writes the bytes instead of JSON.
 */
function cdcf_rest_review_get_attachment(WP_REST_Request $request) {
    $attachment = cdcf_review_get_attachment((int) $request['id']);
    if ($attachment === null) {
        return new WP_Error('attachment_not_found', 'No such attachment.', ['status' => 404]);
    }
    $entry   = cdcf_review_get_entry($attachment['entry_id']);
    $project = $entry ? cdcf_review_get_reviewable_project($entry['project_id']) : null;
    if ($entry === null || is_wp_error($project)) {
        return new WP_Error('attachment_not_found', 'No such attachment.', ['status' => 404]);
    }
    $path = cdcf_review_attachment_path($attachment);
    if (!is_readable($path)) {
        return new WP_Error('file_missing', 'The attachment file is missing.', ['status' => 404]);
    }
    $disposition = !empty($request['download']) ? 'attachment' : 'inline';
    $response = new WP_REST_Response(null, 200);
    $response->set_data([CDCF_REVIEW_FILE_MARKER => $path]);
    $response->header('Content-Type', $attachment['mime'] . ($attachment['kind'] === 'markdown' ? '; charset=utf-8' : ''));
    $response->header('Content-Length', (string) filesize($path));
    $response->header('Content-Disposition', $disposition . "; filename*=UTF-8''" . rawurlencode($attachment['original_name']));
    $response->header('X-Content-Type-Options', 'nosniff');
    $response->header('Content-Security-Policy', "default-src 'none'; sandbox");
    $response->header('Cache-Control', 'private, no-store');
    return $response;
}

/** rest_pre_serve_request: write raw bytes for marked attachment responses. */
function cdcf_review_serve_attachment($served, $result, $request): bool {
    if ($served) {
        return true;
    }
    if (!($result instanceof WP_REST_Response)) {
        return false;
    }
    // Only the attachment route may stream a file: a marker key smuggled
    // into any other endpoint's response data must never read from disk.
    if (!is_object($request) || !method_exists($request, 'get_route')
        || !preg_match('#^/cdcf/v1/review/attachments/\d+$#', (string) $request->get_route())) {
        return false;
    }
    $data = $result->get_data();
    if (!is_array($data) || !isset($data[CDCF_REVIEW_FILE_MARKER])) {
        return false;
    }
    readfile($data[CDCF_REVIEW_FILE_MARKER]);
    return true;
}

/** @return list<int> Positive integer ids; anything else is dropped. */
function cdcf_review_sanitize_id_list($value): array {
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_filter(array_map('intval', $value), static fn(int $id) => $id > 0));
}

function cdcf_rest_review_inbox(WP_REST_Request $request) {
    $uid  = get_current_user_id();
    $page = max(1, (int) $request['page']);
    $out  = cdcf_review_inbox($uid, (bool) $request['unread'], $page);
    return rest_ensure_response([
        'items'    => $out['items'],
        'page'     => $page,
        'has_more' => $out['has_more'],
        'unread'   => cdcf_review_unread_total($uid),
    ]);
}

function cdcf_rest_review_inbox_count(WP_REST_Request $request) {
    unset($request);
    return rest_ensure_response(['unread' => cdcf_review_unread_total(get_current_user_id())]);
}

function cdcf_rest_review_inbox_read(WP_REST_Request $request) {
    $uid = get_current_user_id();
    if ($request['all']) {
        cdcf_review_mark_read($uid);
    } else {
        $ids = (array) $request['event_ids'];
        if (!$ids) {
            return new WP_Error('invalid_input', 'Pass event_ids or all.', ['status' => 400]);
        }
        cdcf_review_mark_read($uid, $ids);
    }
    return rest_ensure_response(['unread' => cdcf_review_unread_total($uid)]);
}
