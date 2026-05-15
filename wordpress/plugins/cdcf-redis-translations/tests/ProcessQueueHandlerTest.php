<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_handle_process_queue and its permission callback.
 *
 * The handler delegates to the global redis_queue() function, which
 * (in production) is defined by the redis-queue plugin. Brain Monkey
 * lets us stub function_exists() and redis_queue() per-test without
 * needing a separate process.
 */
final class ProcessQueueHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Production calls ignore_user_abort(true) — stub it so it's
        // a no-op under test.
        Functions\when('ignore_user_abort')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_permission_check_delegates_to_current_user_can(): void
    {
        Functions\expect('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(true);

        $this->assertTrue(cdcf_process_queue_permission_check());
    }

    public function test_redis_queue_unavailable_returns_503(): void
    {
        // Brain Monkey stubs from other tests may have eval-declared
        // redis_queue() in the symbol table; force function_exists to
        // return false so the handler hits its early-return branch.
        Functions\when('function_exists')->alias(
            fn(string $name): bool => $name !== 'redis_queue'
        );

        $req = new WP_REST_Request();
        $response = cdcf_handle_process_queue($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('redis_queue_unavailable', $response->get_error_code());
        $this->assertSame(503, $response->get_error_data()['status']);
    }

    public function test_happy_path_delegates_to_processor_with_default_batch_10(): void
    {
        $processor = Mockery::mock();
        $processor->shouldReceive('process_jobs')
            ->once()
            ->with(['default'], 10)
            ->andReturn(['processed' => 3, 'failed' => 0]);

        $queue = Mockery::mock();
        $queue->shouldReceive('get_job_processor')->once()->andReturn($processor);

        // Defining redis_queue() via Brain Monkey also makes
        // function_exists('redis_queue') return true.
        Functions\when('redis_queue')->justReturn($queue);

        $req = new WP_REST_Request();
        // No batch_size param — handler defaults to 10.

        $response = cdcf_handle_process_queue($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['processed' => 3, 'failed' => 0], $response->get_data()['processed']);
    }

    public function test_batch_size_clamped_low_to_one(): void
    {
        $processor = Mockery::mock();
        $processor->shouldReceive('process_jobs')
            ->once()
            ->with(['default'], 1)
            ->andReturn([]);

        $queue = Mockery::mock();
        $queue->shouldReceive('get_job_processor')->once()->andReturn($processor);

        Functions\when('redis_queue')->justReturn($queue);

        $req = new WP_REST_Request();
        $req->set_param('batch_size', 0);

        $response = cdcf_handle_process_queue($req);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
    }

    public function test_batch_size_clamped_high_to_fifty(): void
    {
        $processor = Mockery::mock();
        $processor->shouldReceive('process_jobs')
            ->once()
            ->with(['default'], 50)
            ->andReturn([]);

        $queue = Mockery::mock();
        $queue->shouldReceive('get_job_processor')->once()->andReturn($processor);

        Functions\when('redis_queue')->justReturn($queue);

        $req = new WP_REST_Request();
        $req->set_param('batch_size', 100);

        $response = cdcf_handle_process_queue($req);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
    }

    public function test_batch_size_default_is_ten(): void
    {
        $processor = Mockery::mock();
        $processor->shouldReceive('process_jobs')
            ->once()
            ->with(['default'], 10)
            ->andReturn([]);

        $queue = Mockery::mock();
        $queue->shouldReceive('get_job_processor')->once()->andReturn($processor);

        Functions\when('redis_queue')->justReturn($queue);

        $req = new WP_REST_Request();
        // Intentionally no set_param('batch_size') — covers the default.

        $response = cdcf_handle_process_queue($req);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
    }
}
