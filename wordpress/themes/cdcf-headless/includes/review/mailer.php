<?php
/**
 * Council review — sending email. Through the Redis queue
 * (`cdcf_review_email` job, drained by cdcf-queue-worker via
 * /process-queue) when the queue plugin is active; inline otherwise.
 * WP-Cron is never used: it barely fires on the headless install.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_EMAIL_JOB = 'cdcf_review_email';

function cdcf_review_status_url(int $project_id, string $lang, string $token): string {
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    return rtrim($frontend, '/') . '/api/submission-status/' . $project_id . '/open?'
        . http_build_query(['lang' => $lang, 't' => $token]);
}

/**
 * Plain email, queued when the queue plugin is active, otherwise inline.
 *
 * @return 'queued'|'sent'|'failed'
 */
function cdcf_review_send_email(string $to, string $subject, string $body): string {
    if (cdcf_review_enqueue_email(['to' => $to, 'subject' => $subject, 'body' => $body])) {
        return 'queued';
    }
    if (wp_mail($to, $subject, $body)) {
        return 'sent';
    }
    // Never log the address or the body.
    error_log('[cdcf-review] email could not be sent: ' . $subject);
    return 'failed';
}

/**
 * Hand a job payload to the redis queue. False when the queue is
 * unavailable or refuses the job (its enqueue() returns false on every
 * failure rather than throwing), so the caller sends inline instead.
 */
function cdcf_review_enqueue_email(array $payload): bool {
    if (!function_exists('redis_queue') || !class_exists('CDCF_Review_Email_Job')) {
        return false;
    }
    try {
        $queued = redis_queue()->queue_manager->enqueue(new CDCF_Review_Email_Job($payload)) !== false;
    } catch (\Throwable $e) {
        $queued = false;
    }
    if (!$queued) {
        // A fixed message: exception text could carry connection details.
        error_log('[cdcf-review] email enqueue failed, sending inline');
    }
    return $queued;
}

/**
 * Email the submitter `$template` with a fresh status link, in the
 * submission language.
 *
 * When queued, the job stores only the project id, template and variables:
 * the redis-queue plugin keeps job records in MySQL, so the address, the
 * link and its token are worked out when the job runs, never stored.
 *
 * @return 'queued'|'sent'|'failed'|'no_email'
 */
function cdcf_review_send_submitter_email(int $project_id, string $template, array $vars = []): string {
    if (!is_email((string) get_post_meta($project_id, '_submission_submitter_email', true))) {
        return 'no_email';
    }
    $job = ['submitter' => ['project_id' => $project_id, 'template' => $template, 'vars' => $vars]];
    if (cdcf_review_enqueue_email($job)) {
        return 'queued';
    }
    return cdcf_review_deliver_submitter_email($project_id, $template, $vars);
}

/**
 * Build and send a submitter email now. The new link replaces the old one
 * only once the email has been sent, so a failed email leaves the working
 * link in place.
 *
 * @return 'sent'|'failed'|'no_email'
 */
function cdcf_review_deliver_submitter_email(int $project_id, string $template, array $vars = []): string {
    $to = (string) get_post_meta($project_id, '_submission_submitter_email', true);
    if (!is_email($to)) {
        return 'no_email';
    }
    $lang  = cdcf_review_post_language($project_id);
    $token = cdcf_review_new_token();
    $mail  = cdcf_review_render_email($template, $lang, $vars + [
        // Raw title: get_the_title() would HTML-escape it for a text email.
        'title' => (string) get_post_field('post_title', $project_id),
        'link'  => cdcf_review_status_url($project_id, $lang, $token['token']),
    ]);
    if (!wp_mail($to, $mail['subject'], $mail['body'])) {
        // Never log the address or the body (it carries the status token).
        error_log('[cdcf-review] submitter email could not be sent: ' . $template);
        return 'failed';
    }
    cdcf_review_commit_token($project_id, $token['hash'], $token['issued_at']);
    return 'sent';
}
