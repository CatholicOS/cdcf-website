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
        Functions\expect('update_field')
            ->once()
            ->with('technical_council', [101, 102, 103], 5);
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
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        // The handler does `array_map('absint', array_filter($value))` so:
        //   - array_filter removes 0 (drops index 2, keeping 0, 1, 3)
        //   - array_map preserves those keys but coerces values to abs ints
        // Expectation matches that exact shape — including the gap at
        // key 2 — so future refactors that change the sanitisation
        // pipeline are caught.
        Functions\expect('update_field')
            ->once()
            ->with(
                'technical_council',
                [0 => 101, 1 => 102, 3 => 103],
                5
            );
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 5);
        $req->set_param('field', 'technical_council');
        // 0 is filtered out by array_filter; "-102" becomes 102 via absint; "103" stays 103.
        $req->set_param('value', [101, '-102', 0, '103']);

        $response = cdcf_rest_update_relationship($req);

        $this->assertTrue($response['updated']);
        $this->assertSame([101, 102, 103], array_values($response['value']));
    }
}
