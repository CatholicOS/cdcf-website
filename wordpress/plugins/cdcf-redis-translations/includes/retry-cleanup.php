<?php
/**
 * Removes the job record a redis-queue retry leaves behind.
 *
 * redis-queue retries a failed job by enqueueing it again as a new job
 * with its own record, then sets the old record back to 'queued'. The
 * old job is no longer in Redis, so nothing processes it and the record
 * stays 'queued' on the dashboard for good. The new record carries the
 * job on, so the old one can go.
 */

defined('ABSPATH') || exit;

/**
 * Runs on `redis_queue_job_retried`, after the old record is set back to
 * 'queued'.
 *
 * @param string $job_id The retried job's old id.
 */
function cdcf_delete_superseded_job_record($job_id): void {
    global $wpdb;
    $wpdb->delete(
        $wpdb->prefix . 'redis_queue_jobs',
        ['job_id' => (string) $job_id, 'status' => 'queued'],
        ['%s', '%s']
    );
}
