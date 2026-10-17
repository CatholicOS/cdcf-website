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

        cdcf_delete_superseded_job_record('job_abc');

        $this->addToAssertionCount(1);
    }
}
