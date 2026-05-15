<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// CDCF_Translation_Job extends Soderlind\RedisQueue\Jobs\Abstract_Base_Job
// — pull in a no-op stub so we can require the class without the
// redis-queue plugin.
require_once __DIR__ . '/stubs/AbstractBaseJobStub.php';

// add_filter is called at the bottom of class-translation-job.php; stub
// it before require so it's a no-op under test.
if (!function_exists('add_filter')) {
    function add_filter(...$args): bool {
        return true;
    }
}

require_once __DIR__ . '/../includes/class-translation-job.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Tests for cdcf_enqueue_translation() — the Redis-up vs Redis-down
 * fallback branch in includes/functions.php.
 */
final class EnqueueTranslationFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // spawn_cron() is the WP wrapper around fork()-ish behaviour
        // — make it a no-op under test.
        Functions\when('spawn_cron')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_redis_up_enqueues_job_and_does_not_schedule_wp_cron(): void
    {
        $queueManager = Mockery::mock();
        $queueManager->shouldReceive('enqueue')
            ->once()
            ->with(Mockery::on(function (CDCF_Translation_Job $job): bool {
                $payload = $job->get_payload();
                return $payload['post_id'] === 42
                    && $payload['source_id'] === 7
                    && $payload['target_lang'] === 'it';
            }));

        $queue = Mockery::mock();
        $queue->queue_manager = $queueManager;

        Functions\when('redis_queue')->justReturn($queue);
        Functions\expect('wp_schedule_single_event')->never();

        $result = cdcf_enqueue_translation(42, 7, 'it');

        $this->assertSame('redis', $result);
    }

    public function test_redis_down_falls_back_to_wp_schedule_single_event(): void
    {
        // Force function_exists('redis_queue') to return false so the
        // handler hits its "redis not available" branch. Brain Monkey
        // may have eval-declared redis_queue() in a prior test (the
        // declaration persists in PHP's symbol table), so the native
        // function_exists no longer reflects "not installed".
        Functions\when('function_exists')->alias(
            fn(string $name): bool => $name !== 'redis_queue'
        );

        Functions\expect('wp_schedule_single_event')
            ->once()
            ->with(
                Mockery::type('integer'),
                'cdcf_async_translate',
                [42, 7, 'it']
            );

        $result = cdcf_enqueue_translation(42, 7, 'it');

        $this->assertSame('wp-cron', $result);
    }

    public function test_enqueue_throw_falls_back_to_wp_schedule_single_event(): void
    {
        $queueManager = Mockery::mock();
        $queueManager->shouldReceive('enqueue')
            ->once()
            ->andThrow(new RuntimeException('redis down'));

        $queue = Mockery::mock();
        $queue->queue_manager = $queueManager;

        Functions\when('redis_queue')->justReturn($queue);
        // error_log is what the handler calls before falling back.
        Functions\when('error_log')->justReturn(true);

        Functions\expect('wp_schedule_single_event')
            ->once()
            ->with(
                Mockery::type('integer'),
                'cdcf_async_translate',
                [42, 7, 'it']
            );

        $result = cdcf_enqueue_translation(42, 7, 'it');

        $this->assertSame('wp-cron', $result);
    }

    public function test_redis_up_passes_documented_payload_shape(): void
    {
        $capturedPayload = null;

        $queueManager = Mockery::mock();
        $queueManager->shouldReceive('enqueue')
            ->once()
            ->andReturnUsing(function (CDCF_Translation_Job $job) use (&$capturedPayload): void {
                $capturedPayload = $job->get_payload();
            });

        $queue = Mockery::mock();
        $queue->queue_manager = $queueManager;

        Functions\when('redis_queue')->justReturn($queue);

        cdcf_enqueue_translation(101, 99, 'es');

        $this->assertIsArray($capturedPayload);
        $this->assertSame(
            ['post_id', 'source_id', 'target_lang'],
            array_keys($capturedPayload)
        );
        $this->assertSame(101, $capturedPayload['post_id']);
        $this->assertSame(99, $capturedPayload['source_id']);
        $this->assertSame('es', $capturedPayload['target_lang']);
    }
}
