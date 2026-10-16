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
        $member    = $this->get_payload_value('member');
        $submitter = $this->get_payload_value('submitter');
        if (is_array($member)) {
            // A member email: built now, so the stored job holds only ids.
            $sent = cdcf_review_deliver_member_email(
                (int) ($member['user_id'] ?? 0),
                (int) ($member['event_id'] ?? 0)
            ) !== 'failed';
        } elseif (is_array($submitter)) {
            // A submitter email: built and sent now, so the stored job never
            // holds the address, the status link or its token.
            $sent = cdcf_review_deliver_submitter_email(
                (int) ($submitter['project_id'] ?? 0),
                (string) ($submitter['template'] ?? ''),
                (array) ($submitter['vars'] ?? [])
            ) !== 'failed';
        } else {
            $sent = wp_mail(
                (string) $this->get_payload_value('to'),
                (string) $this->get_payload_value('subject'),
                (string) $this->get_payload_value('body')
            );
            // Jobs queued before submitter emails were built at send time
            // carry their link's token; activate it now that it is sent.
            $token = $this->get_payload_value('token');
            if ($sent && is_array($token) && isset($token['project_id'], $token['hash'], $token['issued_at'])) {
                cdcf_review_commit_token((int) $token['project_id'], (string) $token['hash'], (string) $token['issued_at']);
            }
        }
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
