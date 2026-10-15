<?php
/**
 * Public endpoints for a submitter's status page.
 *
 * Authenticated only by the status token (X-CDCF-Submission-Token), which
 * is never stored in clear. Every token failure — missing, malformed,
 * wrong, rotated, expired, unknown project — is the same 404, so the
 * endpoints reveal nothing about which submissions exist. Repeated failures
 * (not valid requests) eventually get a 429; a valid token is never limited.
 */

defined('ABSPATH') || exit;

const CDCF_SUBMISSION_ANSWER_MAX = 10000;

/**
 * Rate limiting counts only what an attacker can cause and a legitimate
 * submitter cannot: failed token checks (GET/answer) and link emails sent
 * (resend). A valid token is never limited. Keys are per IP *and* project:
 * every request reaches WordPress from the Next.js server, so a per-IP key
 * alone would be one bucket shared by everyone. Peek and count are separate
 * so tokenless junk cannot spend a valid caller's budget.
 */
function cdcf_submission_status_key(string $bucket, int $project_id): string {
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return 'cdcf_sst_' . $bucket . '_' . md5($ip . '|' . $project_id);
}

function cdcf_submission_status_over_limit(string $bucket, int $project_id, int $limit): bool {
    return (int) get_transient(cdcf_submission_status_key($bucket, $project_id)) >= $limit;
}

function cdcf_submission_status_count(string $bucket, int $project_id): void {
    $key = cdcf_submission_status_key($bucket, $project_id);
    set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
}

function cdcf_submission_status_not_found(): WP_Error {
    return new WP_Error('not_found', 'No submission matches this link.', ['status' => 404]);
}

function cdcf_submission_status_rate_error(): WP_Error {
    return new WP_Error('rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
}

/**
 * @return object|WP_Error The project when the token is valid. Any failure is
 * the same 404 until the failure budget for this IP+project is spent, then 429.
 */
function cdcf_submission_status_project(WP_REST_Request $request, string $bucket, int $limit) {
    $pid     = (int) $request['id'];
    $token   = (string) $request->get_header('x-cdcf-submission-token');
    $project = cdcf_review_get_reviewable_project($pid);
    if (is_wp_error($project) || !cdcf_review_token_matches($pid, $token)) {
        if (cdcf_submission_status_over_limit($bucket, $pid, $limit)) {
            return cdcf_submission_status_rate_error();
        }
        cdcf_submission_status_count($bucket, $pid);
        return cdcf_submission_status_not_found();
    }
    return $project;
}

function cdcf_submission_status_public_url(object $project): ?string {
    $path = function_exists('cdcf_frontend_path_for') ? cdcf_frontend_path_for($project) : null;
    if (!is_string($path) || $path === '') {
        return null;
    }
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    return rtrim($frontend, '/') . $path;
}

function cdcf_rest_submission_status_get(WP_REST_Request $request) {
    $project = cdcf_submission_status_project($request, 'fail_get', 120);
    if (is_wp_error($project)) {
        return $project;
    }
    $pid = (int) $project->ID;

    // Questions are shown without their author: the submitter hears from
    // "the review committee", never from a named member.
    $questions = [];
    $answers   = [];
    foreach (cdcf_review_project_entries($pid) as $row) {
        if ($row['kind'] === 'question') {
            $questions[(int) $row['id']] = [
                'id' => (int) $row['id'], 'body' => (string) $row['body_md'],
                'asked_at' => (string) $row['created_at'], 'answer' => null,
            ];
        } elseif ($row['kind'] === 'answer') {
            $answers[] = $row;
        }
    }
    foreach ($answers as $answer) {
        $qid = (int) $answer['parent_id'];
        if (isset($questions[$qid]) && $questions[$qid]['answer'] === null) {
            $questions[$qid]['answer'] = ['body' => (string) $answer['body_md'], 'answered_at' => (string) $answer['created_at']];
        }
    }

    $stage = (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true);
    return rest_ensure_response([
        'id'           => $pid,
        'title'        => (string) $project->post_title,
        'submitted_at' => cdcf_review_submitted_at($project),
        'language'     => cdcf_review_post_language($pid),
        'stage'        => $stage,
        'questions'    => array_values($questions),
        'decision'     => $stage === 'decided' ? [
            'outcome'    => (string) get_post_meta($pid, '_cdcf_review_decision', true),
            'summary'    => (string) get_post_meta($pid, '_cdcf_review_summary', true),
            'decided_at' => (int) get_post_meta($pid, '_cdcf_review_decided_at', true),
        ] : null,
        'public_url'   => cdcf_submission_status_public_url($project),
    ]);
}

function cdcf_rest_submission_status_answer(WP_REST_Request $request) {
    $pid = (int) $request['id'];
    $project = cdcf_submission_status_project($request, 'fail_answer', 20);
    if (is_wp_error($project)) {
        return $project;
    }
    if ((string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true) === 'decided') {
        return new WP_Error('review_closed', 'This review has been decided and no longer accepts answers.', ['status' => 409]);
    }
    $body = trim((string) $request['body']);
    if ($body === '') {
        return new WP_Error('empty_body', 'Please write an answer.', ['status' => 400]);
    }
    if (mb_strlen($body) > CDCF_SUBMISSION_ANSWER_MAX) {
        return new WP_Error('body_too_long', 'The answer is too long.', ['status' => 400]);
    }
    if (cdcf_is_spam_content($body)) {
        return new WP_Error('answer_rejected', 'This answer could not be accepted. Please rephrase it and try again.', ['status' => 400]);
    }

    $qid       = (int) $request['question_id'];
    $questions = [];
    $answered  = [];
    foreach (cdcf_review_project_entries($pid) as $row) {
        if ($row['kind'] === 'question') {
            $questions[(int) $row['id']] = true;
        } elseif ($row['kind'] === 'answer') {
            $answered[(int) $row['parent_id']] = true;
        }
    }
    if (!isset($questions[$qid])) {
        return new WP_Error('question_not_found', 'That question does not exist.', ['status' => 404]);
    }
    if (isset($answered[$qid])) {
        return new WP_Error('already_answered', 'This question has already been answered.', ['status' => 409]);
    }

    $id = cdcf_review_insert_entry([
        'project_id' => $pid, 'kind' => 'answer', 'parent_id' => $qid,
        'author_user_id' => null, 'body_md' => $body,
    ]);
    if ($id <= 0) {
        return new WP_Error('insert_failed', 'Could not save the answer.', ['status' => 500]);
    }
    $answered[$qid] = true;
    $stage = count(array_diff_key($questions, $answered)) === 0 ? 'in_review' : 'awaiting_submitter';
    update_post_meta($pid, CDCF_REVIEW_STAGE_META, $stage);
    cdcf_review_record_event($pid, 'submitter_answered', null, $id);

    return new WP_REST_Response(['id' => $id, 'question_id' => $qid, 'stage' => $stage], 201);
}

function cdcf_rest_submission_status_resend_link(WP_REST_Request $request) {
    // One answer for every outcome, so the endpoint cannot be used to test
    // which projects exist or which address submitted them.
    $accepted = new WP_REST_Response(['accepted' => true], 202);
    $pid      = (int) $request['project_id'];
    if (is_wp_error(cdcf_review_get_reviewable_project($pid))) {
        return $accepted;
    }
    $stored = strtolower(trim((string) get_post_meta($pid, '_submission_submitter_email', true)));
    $given  = strtolower(trim((string) $request['email']));
    // Only matching requests are counted, capping mail to the submitter;
    // a caller without the address cannot exhaust the budget.
    if ($stored !== '' && hash_equals($stored, $given) && !cdcf_submission_status_over_limit('send', $pid, 5)) {
        cdcf_submission_status_count('send', $pid);
        cdcf_review_send_submitter_email($pid, 'link');
    }
    return $accepted;
}
