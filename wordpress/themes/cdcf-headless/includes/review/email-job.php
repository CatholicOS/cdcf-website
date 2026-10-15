<?php
/**
 * Council review — the queued email job.
 */

defined('ABSPATH') || exit;

class CDCF_Review_Email_Job extends \Soderlind\RedisQueue\Jobs\Abstract_Base_Job
{
    protected int $timeout = 30;
    protected int $retry_attempts = 3;
    protected array $retry_backoff = [60, 300, 900];

    public function get_job_type()
    {
        return CDCF_REVIEW_EMAIL_JOB;
    }

    public function execute()
    {
        $sent = wp_mail(
            (string) $this->get_payload_value('to'),
            (string) $this->get_payload_value('subject'),
            (string) $this->get_payload_value('body')
        );
        if (!$sent) {
            // Throwing hands the job back to the queue for a retry.
            throw new \RuntimeException('wp_mail failed for a review email');
        }
        return $this->success();
    }
}

/**
 * Registered on `redis_queue_create_job` from functions.php, so the queue
 * can rebuild this job from its stored payload.
 */
function cdcf_review_create_email_job($job, $type, $payload) {
    return $type === CDCF_REVIEW_EMAIL_JOB ? new CDCF_Review_Email_Job((array) $payload) : $job;
}
