<?php
/**
 * Removes the job record a redis-queue retry leaves behind.
 *
 * redis-queue retries a failed job by enqueueing it again as a new job
 * with its own record, then sets the old record back to 'queued'. The
 * old job is no longer in Redis, so nothing processes it and the record
 * stays 'queued' on the dashboard for good. The new record carries the
 * job on, so the old one can go.
 *
 * The retry fires `redis_queue_job_retried` whether or not the new
 * enqueue succeeded, and does not pass its result. enqueue() fires
 * `redis_queue_job_enqueued` with the same job object only on success,
 * so the old record is deleted only when that happened for this job.
 */

defined('ABSPATH') || exit;

/**
 * Runs on `redis_queue_job_enqueued`, and on `redis_queue_job_retried`
 * to read and clear what was recorded.
 *
 * @param object|null $job  The job just enqueued; null to take the one recorded.
 * @return object|null The job recorded before this call when taking it.
 */
function cdcf_last_enqueued_job($job = null): ?object {
    static $last = null;
    if ($job !== null) {
        $last = $job;
        return null;
    }
    $taken = $last;
    $last  = null;
    return $taken;
}

/**
 * Runs on `redis_queue_job_enqueued`.
 *
 * @param string $job_id The new job's id.
 * @param object $job    The job enqueued.
 */
function cdcf_remember_enqueued_job($job_id, $job): void {
    if (is_object($job)) {
        cdcf_last_enqueued_job($job);
    }
}

/**
 * Runs on `redis_queue_job_retried`, after the old record is set back to
 * 'queued'.
 *
 * @param string $job_id The retried job's old id.
 * @param object $job    The job the retry enqueued again.
 */
function cdcf_delete_superseded_job_record($job_id, $job = null): void {
    if ($job === null || cdcf_last_enqueued_job() !== $job) {
        // The replacement was not enqueued: keep the old record.
        return;
    }
    global $wpdb;
    $wpdb->delete(
        $wpdb->prefix . 'redis_queue_jobs',
        ['job_id' => (string) $job_id, 'status' => 'queued'],
        ['%s', '%s']
    );
}
