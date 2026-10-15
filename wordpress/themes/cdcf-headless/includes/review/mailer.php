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

/** @return 'queued'|'sent'|'failed' */
function cdcf_review_send_email(string $to, string $subject, string $body): string {
    if (function_exists('redis_queue') && class_exists('CDCF_Review_Email_Job')) {
        try {
            redis_queue()->queue_manager->enqueue(
                new CDCF_Review_Email_Job(['to' => $to, 'subject' => $subject, 'body' => $body])
            );
            return 'queued';
        } catch (\Throwable $e) {
            error_log('[cdcf-review] email enqueue failed, sending inline: ' . $e->getMessage());
        }
    }
    if (wp_mail($to, $subject, $body)) {
        return 'sent';
    }
    // Never log the address or the body (it carries the status token).
    error_log('[cdcf-review] email could not be sent: ' . $subject);
    return 'failed';
}

/**
 * Rotate the submitter's token and email them `$template` with the fresh
 * status link, in the submission language.
 *
 * @return 'queued'|'sent'|'failed'|'no_email'
 */
function cdcf_review_send_submitter_email(int $project_id, string $template, array $vars = []): string {
    $to = (string) get_post_meta($project_id, '_submission_submitter_email', true);
    if (!is_email($to)) {
        return 'no_email';
    }
    $lang  = cdcf_review_post_language($project_id);
    $token = cdcf_review_issue_token($project_id);
    $mail  = cdcf_review_render_email($template, $lang, $vars + [
        // Raw title: get_the_title() would HTML-escape it for a text email.
        'title' => (string) get_post_field('post_title', $project_id),
        'link'  => cdcf_review_status_url($project_id, $lang, $token),
    ]);
    return cdcf_review_send_email($to, $mail['subject'], $mail['body']);
}
