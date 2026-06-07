<?php
/**
 * GET /cdcf/v1/translation-status — return the current translation status
 * recorded on a target post by cdcf_enqueue_post_translation() and
 * cdcf_process_translation() (via includes/translation-status.php).
 *
 * The meta-box JS polls this endpoint every few seconds after enqueueing
 * so the "Queued" badge can flip to "Done" or "Failed" without a page
 * reload — replacing the "Queued forever" behaviour PR #156 introduced
 * when it moved the meta-box from synchronous OpenAI calls to enqueue.
 *
 * Returns 200 with { post_id, status, completed_at, error } for any
 * post id that exists. Statuses returned:
 *   - "enqueued"   — waiting for the worker
 *   - "processing" — worker started but hasn't finished
 *   - "completed"  — happy path; completed_at populated
 *   - "failed"     — worker hit an error; error string populated
 *   - "unknown"    — post predates this feature or was never enqueued
 *                    through cdcf_enqueue_post_translation(); UI treats
 *                    it as "completed" so legacy posts don't poll forever
 */

defined('ABSPATH') || exit;

function cdcf_rest_translation_status(WP_REST_Request $request) {
    // post_id is already absint'd by the args block; trust per #111.
    $post_id = (int) $request['post_id'];
    if ($post_id <= 0) {
        return new WP_Error('missing_post_id', 'post_id is required.', ['status' => 400]);
    }
    if (!get_post($post_id)) {
        return new WP_Error('not_found', "Post {$post_id} not found.", ['status' => 404]);
    }

    $status = cdcf_translation_status_get($post_id);

    return new WP_REST_Response([
        'post_id'      => $post_id,
        'status'       => $status['status'],
        'completed_at' => $status['completed_at'],
        'error'        => $status['error'],
    ], 200);
}
