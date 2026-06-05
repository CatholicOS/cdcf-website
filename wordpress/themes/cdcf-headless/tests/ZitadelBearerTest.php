<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Zitadel bearer-token authenticator
 * (includes/auth/zitadel-bearer.php).
 *
 * The filter callback cdcf_zitadel_bearer_authenticate() runs on every
 * REST request once it's wired into `determine_current_user`. These
 * tests invoke it directly (bypassing the filter mechanism, which is
 * Brain Monkey's domain) and assert on the value it would return.
 *
 * Each test stubs only the WP helpers the specific branch consults.
 */
final class ZitadelBearerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // error_log() is called on every "unexpected" branch (5xx
        // userinfo, malformed JSON, missing WP_Error message). Silence
        // so PHPUnit output stays clean.
        Patchwork\redefine('error_log', static fn(string $msg): bool => true);
        // Reset request-scoped state between tests.
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        parent::tearDown();
    }

    /** Common stubs used by most happy/sad-path tests. */
    private function stubWp(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn(array $r): int => (int) ($r['response']['code'] ?? 0)
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn(array $r): string => (string) ($r['body'] ?? '')
        );
    }

    private function buildUserinfoResponse(int $code, array $body): array
    {
        return [
            'response' => ['code' => $code],
            'body'     => json_encode($body),
        ];
    }

    // ─── Early-exit branches ──────────────────────────────────────────

    public function test_returns_user_id_unchanged_when_already_authenticated(): void
    {
        // Pre-authenticated by cookie/AppPassword — must not call out.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        Functions\expect('wp_remote_post')->never();
        Functions\expect('get_transient')->never();

        $this->assertSame(42, cdcf_zitadel_bearer_authenticate(42));
    }

    public function test_returns_user_id_unchanged_when_no_authorization_header(): void
    {
        Functions\expect('wp_remote_post')->never();
        Functions\expect('get_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
        $this->assertSame(0, cdcf_zitadel_bearer_authenticate(0));
    }

    public function test_returns_user_id_unchanged_when_non_bearer_scheme(): void
    {
        // E.g. Basic auth (Application Password). We never touch it.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        Functions\expect('wp_remote_post')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    // ─── Cache-hit branch ─────────────────────────────────────────────

    public function test_cache_hit_returns_cached_user_id_without_userinfo_call(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer cached.jwt';
        $this->stubWp();
        Functions\when('get_transient')->justReturn(99);
        Functions\expect('wp_remote_post')->never();

        $this->assertSame(99, cdcf_zitadel_bearer_authenticate(false));
    }

    // ─── Userinfo-response branches ──────────────────────────────────

    public function test_valid_token_with_verified_email_returns_wp_user_id(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid.jwt';
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'sub'            => 'zitadel-user-id-123',
            'email'          => 'author@example.org',
            'email_verified' => true,
        ]));
        Functions\when('get_user_by')->alias(
            static fn(string $field, string $value): WP_User => new WP_User(7)
        );
        // Cache must be primed.
        $captured = null;
        Functions\when('set_transient')->alias(
            function (string $key, $value, int $ttl) use (&$captured): bool {
                $captured = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
                return true;
            }
        );

        $this->assertSame(7, cdcf_zitadel_bearer_authenticate(false));
        $this->assertNotNull($captured);
        $this->assertStringStartsWith('cdcf_zb_', $captured['key']);
        $this->assertSame(7, $captured['value']);
        $this->assertSame(60, $captured['ttl']);
    }

    public function test_token_hash_used_as_cache_key_not_raw_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sensitive.jwt.value';
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'email'          => 'a@b.org',
            'email_verified' => true,
        ]));
        Functions\when('get_user_by')->alias(
            static fn(): WP_User => new WP_User(1)
        );
        $captured_key = null;
        Functions\when('set_transient')->alias(
            function (string $key) use (&$captured_key): bool {
                $captured_key = $key;
                return true;
            }
        );

        cdcf_zitadel_bearer_authenticate(false);

        $this->assertNotNull($captured_key);
        $this->assertStringNotContainsString('sensitive', $captured_key);
        $this->assertStringNotContainsString('jwt', $captured_key);
        $this->assertSame('cdcf_zb_' . hash('sha256', 'sensitive.jwt.value'), $captured_key);
    }

    public function test_email_verified_false_falls_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer unverified.jwt';
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'email'          => 'unconfirmed@example.org',
            'email_verified' => false,
        ]));
        Functions\expect('get_user_by')->never();
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_missing_email_falls_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer noemail.jwt';
        $this->stubWp();
        // sub present, email field omitted entirely (some IdPs allow this).
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'sub'            => 'zitadel-user-no-email',
            'email_verified' => true,
        ]));
        Functions\expect('get_user_by')->never();
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_no_matching_wp_user_falls_through(): void
    {
        // First-time login from a Zitadel user the admin hasn't yet
        // mapped to a WP account — fall through, no auto-provisioning.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer unknown.jwt';
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'email'          => 'stranger@example.org',
            'email_verified' => true,
        ]));
        Functions\when('get_user_by')->justReturn(false);
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_userinfo_non_200_falls_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer expired.jwt';
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn(
            $this->buildUserinfoResponse(401, ['error' => 'invalid_token'])
        );
        Functions\expect('get_user_by')->never();
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_userinfo_malformed_json_falls_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bogus.jwt';
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn([
            'response' => ['code' => 200],
            'body'     => 'not actually json {{{',
        ]);
        Functions\expect('get_user_by')->never();
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_wp_error_from_http_layer_falls_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer network-died.jwt';
        $this->stubWp();
        $err = new WP_Error('http_request_failed', 'connect timeout');
        Functions\when('wp_remote_post')->justReturn($err);
        Functions\expect('get_user_by')->never();
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    // ─── Header-extraction edge cases ────────────────────────────────

    public function test_token_extracted_from_redirect_http_authorization_fallback(): void
    {
        // Apache + mod_rewrite + FCGI: the original HTTP_AUTHORIZATION
        // may land under REDIRECT_HTTP_AUTHORIZATION instead.
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer fcgi-fallback.jwt';
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'email'          => 'a@b.org',
            'email_verified' => true,
        ]));
        Functions\when('get_user_by')->alias(static fn(): WP_User => new WP_User(11));

        $this->assertSame(11, cdcf_zitadel_bearer_authenticate(false));
    }
}
