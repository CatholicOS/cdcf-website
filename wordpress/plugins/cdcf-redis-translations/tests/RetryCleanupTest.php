<?php

declare(strict_types=1);

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/retry-cleanup.php';

/**
 * Tests for cdcf_delete_superseded_job_record(), which removes the job
 * record a redis-queue retry replaces with a new one.
 */
final class RetryCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_deletes_the_superseded_record_only_while_it_is_still_queued(): void
    {
        $wpdb         = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_redis_queue_jobs', ['job_id' => 'job_abc', 'status' => 'queued'], ['%s', '%s'])
            ->andReturn(1);
        $GLOBALS['wpdb'] = $wpdb;

        $job = new stdClass();
        cdcf_remember_enqueued_job('job_new', $job);
        cdcf_delete_superseded_job_record('job_abc', $job);

        $this->addToAssertionCount(1);
    }

    public function test_keeps_the_record_when_the_replacement_was_not_enqueued(): void
    {
        $wpdb         = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('delete');
        $GLOBALS['wpdb'] = $wpdb;

        // An earlier, unrelated job was enqueued; the retry's enqueue failed.
        cdcf_remember_enqueued_job('job_other', new stdClass());
        cdcf_delete_superseded_job_record('job_abc', new stdClass());

        $this->addToAssertionCount(1);
    }

    public function test_a_successful_enqueue_is_used_once(): void
    {
        $wpdb         = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('delete')->once()->andReturn(1);
        $GLOBALS['wpdb'] = $wpdb;

        $job = new stdClass();
        cdcf_remember_enqueued_job('job_new', $job);
        cdcf_delete_superseded_job_record('job_abc', $job);
        // A second retry of the same job whose enqueue failed.
        cdcf_delete_superseded_job_record('job_new', $job);

        $this->addToAssertionCount(1);
    }

    public function test_keeps_the_record_without_the_job(): void
    {
        $wpdb         = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive('delete');
        $GLOBALS['wpdb'] = $wpdb;

        cdcf_delete_superseded_job_record('job_abc');

        $this->addToAssertionCount(1);
    }
}
