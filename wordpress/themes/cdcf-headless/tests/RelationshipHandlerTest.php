<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/relationship handler pair plus its shared
 * permission callback.
 */
final class RelationshipHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // The handlers call rest_ensure_response on the happy path.
        // The WP core implementation just returns its argument when
        // already a JSON-serialisable value; reproduce that here.
        Functions\when('rest_ensure_response')->returnArg(1);
        // absint() and get_post() are reached by both handlers' new
        // post-existence guard. Default to a coercion that mirrors
        // WordPress and a non-null post; tests that want to exercise
        // the missing-post path override with justReturn(null).
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('get_post')->alias(fn($id) => (object) ['ID' => (int) $id]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_permission_check_delegates_to_current_user_can_edit_posts(): void
    {
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_posts')
            ->andReturn(true);

        $this->assertTrue(cdcf_relationship_permission_check());
    }

    // ─── GET handler ──────────────────────────────────────────────

    public function test_get_returns_500_when_acf_inactive(): void
    {
        // Force function_exists('get_field') to return false.
        Functions\when('function_exists')->alias(
            fn(string $name): bool => $name !== 'get_field'
        );

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');

        $response = cdcf_rest_get_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('acf_missing', $response->code);
        $this->assertSame(500, $response->data['status']);
    }

    public function test_get_returns_400_when_field_is_not_relationship(): void
    {
        // Stub acf_get_field FIRST so Brain Monkey's eval-declares it
        // BEFORE we override function_exists. Patchwork-overriding
        // function_exists to return true for every name would confuse
        // Brain Monkey's FunctionStub constructor into skipping the
        // eval (it short-circuits on existing functions).
        Functions\when('acf_get_field')->justReturn(['type' => 'text']);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'bio');

        $response = cdcf_rest_get_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_field', $response->code);
        $this->assertSame(400, $response->data['status']);
    }

    public function test_get_returns_400_when_acf_field_is_unknown(): void
    {
        // acf_get_field returns null/false for unknown fields.
        Functions\when('acf_get_field')->justReturn(false);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'nonexistent');

        $response = cdcf_rest_get_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_field', $response->code);
    }

    public function test_get_happy_path_returns_field_value(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'relationship']);
        Functions\expect('get_field')
            ->once()
            ->with('technical_council', 5, false)
            ->andReturn([101, 102, 103]);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');

        $response = cdcf_rest_get_relationship($req);

        $this->assertSame([
            'post_id' => 5,
            'field'   => 'technical_council',
            'value'   => [101, 102, 103],
        ], $response);
    }

    public function test_get_empty_value_returns_empty_array(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'relationship']);
        Functions\when('get_field')->justReturn(false);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');

        $response = cdcf_rest_get_relationship($req);

        $this->assertSame([], $response['value']);
    }

    // ─── POST handler ─────────────────────────────────────────────

    public function test_post_returns_500_when_acf_inactive(): void
    {
        Functions\when('function_exists')->alias(
            fn(string $name): bool => $name !== 'update_field'
        );

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');
        $req->set_param('value', [101, 102]);

        $response = cdcf_rest_update_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('acf_missing', $response->code);
        $this->assertSame(500, $response->data['status']);
    }

    public function test_post_returns_400_when_field_is_not_relationship(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'text']);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'bio');
        $req->set_param('value', [101]);

        $response = cdcf_rest_update_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_field', $response->code);
        $this->assertSame(400, $response->data['status']);
    }

    public function test_post_happy_path_calls_update_field_and_returns_payload(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'relationship']);
        // absint() is a WP helper — stub it as PHP's (int)abs.
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        // andReturn(true) is required now that the handler checks the
        // return value (#109): a Mockery expect() with no explicit
        // andReturn defaults to null, which the handler treats as a
        // failed write and converts to a 500.
        Functions\expect('update_field')
            ->once()
            ->with('technical_council', [101, 102, 103], 5)
            ->andReturn(true);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');
        $req->set_param('value', [101, 102, 103]);

        $response = cdcf_rest_update_relationship($req);

        $this->assertSame([
            'post_id' => 5,
            'field'   => 'technical_council',
            'value'   => [101, 102, 103],
            'updated' => true,
        ], $response);
    }

    public function test_post_sanitizes_value_to_positive_integers(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'relationship']);
        // The handler's new pipeline:
        //   array_values(array_filter(array_map('absint', (array)$value), > 0))
        //   so absint coerces every element first, then anything <=0 is
        //   filtered, then array_values reindexes. Result is a packed
        //   [0..n-1] array with no gaps.
        Functions\expect('update_field')
            ->once()
            ->with(
                'technical_council',
                [101, 102, 103],
                5
            )
            ->andReturn(true);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');
        // 0 is dropped (not positive); "-102" → absint → 102; "103" → 103.
        $req->set_param('value', [101, '-102', 0, '103']);

        $response = cdcf_rest_update_relationship($req);

        $this->assertTrue($response['updated']);
        $this->assertSame([101, 102, 103], $response['value']);
    }

    public function test_post_drops_non_numeric_input(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'relationship']);
        // Non-numeric strings like "abc" used to sneak through as 0
        // (array_filter ran before absint). With the new ordering they
        // hit absint first → 0, and array_filter > 0 drops them. Pin
        // that explicitly.
        Functions\expect('update_field')
            ->once()
            ->with('technical_council', [42], 5)
            ->andReturn(true);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');
        $req->set_param('value', [42, 'abc', null, false]);

        $response = cdcf_rest_update_relationship($req);

        $this->assertSame([42], $response['value']);
    }

    public function test_post_returns_500_when_update_field_returns_false(): void
    {
        // ACF's update_field returns false on real persistence failure.
        // Surface as 500 so silent failures don't masquerade as success
        // to the API client (#109).
        Functions\when('acf_get_field')->justReturn(['type' => 'relationship']);
        Functions\when('update_field')->justReturn(false);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');
        $req->set_param('value', [101, 102]);

        $response = cdcf_rest_update_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('update_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    // ─── Post-existence guard (#4) ────────────────────────────────

    public function test_get_returns_404_when_post_does_not_exist(): void
    {
        Functions\when('function_exists')->alias(fn(string $name): bool => true);
        Functions\when('get_post')->justReturn(null);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 99999);
        $req->set_param('field', 'technical_council');

        $response = cdcf_rest_get_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('post_not_found', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_get_returns_404_when_post_id_is_zero_or_missing(): void
    {
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        // No post_id param at all → absint(null) → 0 → 404.
        $req->set_param('field', 'technical_council');

        $response = cdcf_rest_get_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('post_not_found', $response->get_error_code());
    }

    public function test_post_returns_404_when_post_does_not_exist(): void
    {
        Functions\when('function_exists')->alias(fn(string $name): bool => true);
        Functions\when('get_post')->justReturn(null);
        // update_field must not be called when the post is missing.
        Functions\expect('update_field')->never();

        $req = new WP_REST_Request();
        $req->set_param('post_id', 99999);
        $req->set_param('field', 'technical_council');
        $req->set_param('value', [101]);

        $response = cdcf_rest_update_relationship($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('post_not_found', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }
}
