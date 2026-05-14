<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_handle_maintenance and its permission callback.
 *
 * Mockery's overload: prefix declares the Redis class globally for the
 * process; each test that overloads Redis MUST run in a separate process
 * with global state disabled so the declaration doesn't leak between
 * tests.
 */
final class MaintenanceHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
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

        $this->assertTrue(cdcf_maintenance_permission_check());
    }

    public function test_invalid_action_returns_wp_error_400(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'foo');

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_action', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_begin_with_default_duration_calls_setex_with_300(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'begin');
        // No duration_seconds — handler defaults to 300.

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(true);
        $redis->shouldReceive('setex')
            ->once()
            ->with('cdcf:maintenance:until', 300, '1')
            ->andReturn(true);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertTrue($response->get_data()['ok']);
        $this->assertSame(300, $response->get_data()['duration']);
        $this->assertSame(200, $response->get_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_begin_clamps_low_duration_to_60(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'begin');
        $req->set_param('duration_seconds', 1);

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(true);
        $redis->shouldReceive('setex')
            ->once()
            ->with('cdcf:maintenance:until', 60, '1')
            ->andReturn(true);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(60, $response->get_data()['duration']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_begin_clamps_high_duration_to_600(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'begin');
        $req->set_param('duration_seconds', 99999);

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(true);
        $redis->shouldReceive('setex')
            ->once()
            ->with('cdcf:maintenance:until', 600, '1')
            ->andReturn(true);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(600, $response->get_data()['duration']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_begin_with_300_passes_through_unchanged(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'begin');
        $req->set_param('duration_seconds', 300);

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(true);
        $redis->shouldReceive('setex')
            ->once()
            ->with('cdcf:maintenance:until', 300, '1')
            ->andReturn(true);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(300, $response->get_data()['duration']);
        $this->assertIsInt($response->get_data()['until']);
        $this->assertGreaterThanOrEqual(time() + 300 - 5, $response->get_data()['until']);
        $this->assertLessThanOrEqual(time() + 300 + 5, $response->get_data()['until']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_end_calls_del_and_returns_ok(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'end');

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(true);
        $redis->shouldReceive('del')
            ->once()
            ->with('cdcf:maintenance:until')
            ->andReturn(1);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertTrue($response->get_data()['ok']);
        $this->assertSame(200, $response->get_status());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_end_is_idempotent_when_key_absent(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'end');

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(true);
        // del returns 0 when the key doesn't exist — handler still
        // returns ok:true.
        $redis->shouldReceive('del')
            ->once()
            ->with('cdcf:maintenance:until')
            ->andReturn(0);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertTrue($response->get_data()['ok']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_missing_redis_class_returns_500(): void
    {
        // No `overload:Redis` mock — the production code's
        // class_exists('Redis') === false branch fires.
        $req = new WP_REST_Request();
        $req->set_param('action', 'begin');

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('redis_unavailable', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_redis_connect_returns_false_returns_500(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'begin');

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(false);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('redis_unavailable', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_setex_returns_false_returns_500_redis_write_failed(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('action', 'begin');

        $redis = Mockery::mock('overload:Redis');
        $redis->shouldReceive('connect')->once()->andReturn(true);
        $redis->shouldReceive('setex')->once()->andReturn(false);

        $response = cdcf_handle_maintenance($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('redis_write_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }
}
