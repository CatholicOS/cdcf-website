<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/author-team-member handler and its shared
 * cdcf_set_author_team_member() helper.
 *
 * The handler links a WordPress user to their team_member bio card by
 * writing the `author_team_member` ACF relationship field on the user
 * object (target "user_{id}"). This is the only reliable way to set
 * that field: ACF 6.x free does not expose user-located field groups
 * via the REST `acf` property, and the post-only /relationship endpoint
 * can't target a user.
 */
final class AuthorTeamMemberHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        // Sensible happy-path defaults; individual tests override.
        Functions\when('get_userdata')->justReturn((object) ['ID' => 5]);
        Functions\when('get_post')->alias(
            fn($id) => (object) ['ID' => (int) $id, 'post_type' => 'team_member']
        );
        Functions\when('get_field')->justReturn([]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function makeRequest(int $user_id, int $team_member_id): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $req->set_param('user_id', $user_id);
        $req->set_param('team_member_id', $team_member_id);
        return $req;
    }

    public function test_returns_500_when_acf_inactive(): void
    {
        // get_field / update_field absent → ACF not active.
        Functions\when('function_exists')->alias(
            fn(string $name): bool => !in_array($name, ['update_field', 'get_field'], true)
        );

        $response = cdcf_rest_link_author_team_member($this->makeRequest(5, 1368));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('acf_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_404_when_user_not_found(): void
    {
        Functions\when('function_exists')->alias(fn(string $name): bool => true);
        Functions\when('get_userdata')->justReturn(false);
        Functions\expect('update_field')->never();

        $response = cdcf_rest_link_author_team_member($this->makeRequest(99999, 1368));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('user_not_found', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_team_member_id_is_not_a_team_member(): void
    {
        Functions\when('function_exists')->alias(fn(string $name): bool => true);
        // A post of the wrong type (or a page) must be rejected.
        Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_type' => 'page']);
        Functions\expect('update_field')->never();

        $response = cdcf_rest_link_author_team_member($this->makeRequest(5, 42));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_team_member', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_team_member_post_missing(): void
    {
        Functions\when('function_exists')->alias(fn(string $name): bool => true);
        Functions\when('get_post')->justReturn(null);
        Functions\expect('update_field')->never();

        $response = cdcf_rest_link_author_team_member($this->makeRequest(5, 4242));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_team_member', $response->get_error_code());
    }

    public function test_happy_path_writes_field_and_returns_payload(): void
    {
        // Stub update_field BEFORE forcing function_exists true-for-all,
        // or Brain Monkey skips eval-declaring it (see RelationshipHandlerTest).
        Functions\when('get_field')->justReturn([]); // currently unlinked
        Functions\expect('update_field')
            ->once()
            ->with('author_team_member', [1368], 'user_5')
            ->andReturn(true);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $response = cdcf_rest_link_author_team_member($this->makeRequest(5, 1368));

        $this->assertSame([
            'success'        => true,
            'user_id'        => 5,
            'team_member_id' => 1368,
            'value'          => [1368],
            'updated'        => true,
        ], $response);
    }

    public function test_no_op_when_already_linked_to_same_team_member(): void
    {
        Functions\when('function_exists')->alias(fn(string $name): bool => true);
        // ACF stores relationship IDs as strings; the helper normalizes.
        Functions\when('get_field')->justReturn(['1368']);
        // Must NOT write again when the value is unchanged (avoids ACF's
        // update_field() returning false on a no-op being read as failure).
        Functions\expect('update_field')->never();

        $response = cdcf_rest_link_author_team_member($this->makeRequest(5, 1368));

        $this->assertTrue($response['success']);
        $this->assertFalse($response['updated']);
        $this->assertSame([1368], $response['value']);
    }

    public function test_clears_link_when_team_member_id_is_zero(): void
    {
        Functions\when('get_field')->justReturn(['1368']);
        // get_post must not be consulted for the 0 (clear) case.
        Functions\expect('get_post')->never();
        Functions\expect('update_field')
            ->once()
            ->with('author_team_member', [], 'user_5')
            ->andReturn(true);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $response = cdcf_rest_link_author_team_member($this->makeRequest(5, 0));

        $this->assertTrue($response['updated']);
        $this->assertSame([], $response['value']);
        $this->assertSame(0, $response['team_member_id']);
    }

    public function test_returns_500_when_update_field_returns_false(): void
    {
        Functions\when('get_field')->justReturn([]);
        Functions\when('update_field')->justReturn(false);
        Functions\when('function_exists')->alias(fn(string $name): bool => true);

        $response = cdcf_rest_link_author_team_member($this->makeRequest(5, 1368));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('update_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }
}
