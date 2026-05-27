<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/create-user handler.
 *
 * Branch matrix:
 *   - role allowlist: rejects editor / administrator before any insert
 *   - validation: invalid email, duplicate username, duplicate email
 *   - happy path: server-generated password, set-password email, 201 payload
 *     never leaks the password
 */
final class CreateUserHandlerTest extends TestCase
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

    private function makeRequest(array $params): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'username'       => 'casey',
            'email'          => 'casey@example.org',
            'role'           => 'author',
            'display_name'   => '',
            'first_name'     => '',
            'last_name'      => '',
            'team_member_id' => 0,
        ];
        foreach (array_merge($defaults, $params) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    // ─── Role allowlist (privilege-escalation guard) ──────────────

    public function test_rejects_editor_role_before_any_insert(): void
    {
        Functions\expect('wp_insert_user')->never();

        $response = cdcf_rest_create_user($this->makeRequest(['role' => 'editor']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_role', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_rejects_administrator_role(): void
    {
        Functions\expect('wp_insert_user')->never();

        $response = cdcf_rest_create_user($this->makeRequest(['role' => 'administrator']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_role', $response->get_error_code());
    }

    // ─── Validation ───────────────────────────────────────────────

    public function test_rejects_invalid_email(): void
    {
        Functions\when('is_email')->justReturn(false);
        Functions\expect('wp_insert_user')->never();

        $response = cdcf_rest_create_user($this->makeRequest(['email' => 'nope']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_email', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_rejects_empty_username(): void
    {
        Functions\when('is_email')->justReturn(true);
        Functions\expect('wp_insert_user')->never();

        $response = cdcf_rest_create_user($this->makeRequest(['username' => '']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_username', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_rejects_duplicate_username(): void
    {
        Functions\when('is_email')->justReturn(true);
        Functions\when('username_exists')->justReturn(5);
        Functions\expect('wp_insert_user')->never();

        $response = cdcf_rest_create_user($this->makeRequest([]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('username_exists', $response->get_error_code());
        $this->assertSame(409, $response->get_error_data()['status']);
    }

    public function test_rejects_duplicate_email(): void
    {
        Functions\when('is_email')->justReturn(true);
        Functions\when('username_exists')->justReturn(false);
        Functions\when('email_exists')->justReturn(9);
        Functions\expect('wp_insert_user')->never();

        $response = cdcf_rest_create_user($this->makeRequest([]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('email_exists', $response->get_error_code());
        $this->assertSame(409, $response->get_error_data()['status']);
    }

    // ─── Happy path ────────────────────────────────────────────────

    public function test_creates_user_with_generated_password_and_sends_email(): void
    {
        Functions\when('is_email')->justReturn(true);
        Functions\when('username_exists')->justReturn(false);
        Functions\when('email_exists')->justReturn(false);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $captured = null;
        Functions\when('wp_generate_password')->justReturn('generated-secret-xyz');
        Functions\when('wp_insert_user')->alias(function ($userdata) use (&$captured): int {
            $captured = $userdata;
            return 88;
        });
        // The set-password email must fire, addressed to the user only.
        Functions\expect('wp_new_user_notification')->once()->with(88, null, 'user');

        $response = cdcf_rest_create_user($this->makeRequest([
            'role'         => 'contributor',
            'display_name' => 'Casey Contributor',
            'first_name'   => 'Casey',
            'last_name'    => 'Contributor',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(201, $response->get_status());

        // Inserted with the server-generated password and the requested role.
        $this->assertSame('generated-secret-xyz', $captured['user_pass']);
        $this->assertSame('contributor', $captured['role']);
        $this->assertSame('Casey Contributor', $captured['display_name']);
        $this->assertSame('Casey', $captured['first_name']);
        $this->assertSame('Contributor', $captured['last_name']);

        // The response must never echo the password back to the agent.
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame(88, $data['user_id']);
        $this->assertSame('contributor', $data['role']);
        $this->assertArrayNotHasKey('user_pass', $data);
        $this->assertArrayNotHasKey('password', $data);
    }

    public function test_display_name_defaults_to_username(): void
    {
        Functions\when('is_email')->justReturn(true);
        Functions\when('username_exists')->justReturn(false);
        Functions\when('email_exists')->justReturn(false);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_generate_password')->justReturn('pw');
        Functions\when('wp_new_user_notification')->justReturn(null);

        $captured = null;
        Functions\when('wp_insert_user')->alias(function ($userdata) use (&$captured): int {
            $captured = $userdata;
            return 90;
        });

        cdcf_rest_create_user($this->makeRequest(['username' => 'solo', 'display_name' => '']));

        $this->assertSame('solo', $captured['display_name']);
    }

    public function test_surfaces_wp_insert_user_error(): void
    {
        Functions\when('is_email')->justReturn(true);
        Functions\when('username_exists')->justReturn(false);
        Functions\when('email_exists')->justReturn(false);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_generate_password')->justReturn('pw');
        Functions\when('wp_insert_user')->justReturn(new WP_Error('db', 'insert failed'));
        Functions\expect('wp_new_user_notification')->never();

        $response = cdcf_rest_create_user($this->makeRequest([]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('db', $response->get_error_code());
    }

    // ─── Optional team_member link (link-on-creation) ─────────────

    /** Stub the common create-success path so each link test can focus on linking. */
    private function stubSuccessfulCreate(int $new_user_id = 88): void
    {
        Functions\when('is_email')->justReturn(true);
        Functions\when('username_exists')->justReturn(false);
        Functions\when('email_exists')->justReturn(false);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_generate_password')->justReturn('pw');
        Functions\when('wp_new_user_notification')->justReturn(null);
        Functions\when('wp_insert_user')->justReturn($new_user_id);
    }

    public function test_links_team_member_when_id_provided(): void
    {
        $this->stubSuccessfulCreate(88);
        // The shared helper (loaded via bootstrap) is delegated to with the
        // freshly-created user id and the requested team_member id.
        Functions\expect('cdcf_set_author_team_member')
            ->once()
            ->with(88, 1368)
            ->andReturn(true);

        $response = cdcf_rest_create_user($this->makeRequest(['team_member_id' => 1368]));

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['linked']);
        $this->assertSame(1368, $data['team_member_id']);
        $this->assertSame([], $data['link_errors']);
    }

    public function test_link_failure_is_non_fatal(): void
    {
        $this->stubSuccessfulCreate(88);
        // Helper failure must NOT fail the request — the user and their
        // set-password email already exist. Surface it in link_errors.
        Functions\when('cdcf_set_author_team_member')
            ->justReturn(new WP_Error('invalid_team_member', 'team_member_id must reference a team_member post.'));

        $response = cdcf_rest_create_user($this->makeRequest(['team_member_id' => 999]));

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertFalse($data['linked']);
        $this->assertNotEmpty($data['link_errors']);
    }

    public function test_no_link_attempt_when_team_member_id_absent(): void
    {
        $this->stubSuccessfulCreate(88);
        // No team_member_id → helper must never be called.
        Functions\expect('cdcf_set_author_team_member')->never();

        $response = cdcf_rest_create_user($this->makeRequest([])); // default 0

        $data = $response->get_data();
        $this->assertFalse($data['linked']);
        $this->assertSame(0, $data['team_member_id']);
        $this->assertSame([], $data['link_errors']);
    }
}
