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
    private const TEST_EXPECTED_AUD = '999000111000222000';
    // Second entry of the allow-list defined in tests/bootstrap.php — used
    // by the multi-aud integration test (issue #173).
    private const TEST_EXPECTED_AUD_NONPROD = '888000111000222000';

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

    /**
     * Mint a fake JWT with the given payload claims. Header and signature
     * are arbitrary strings — only the middle segment is read by the
     * audience verifier (signature verification is userinfo's job).
     *
     * Pass aud=null to omit the claim entirely (for negative tests).
     */
    private function mintJwt(array $payloadOverrides = []): string
    {
        $payload = array_merge(['aud' => self::TEST_EXPECTED_AUD], $payloadOverrides);
        if ($payload['aud'] === null) {
            unset($payload['aud']);
        }
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        return 'header-placeholder.' . $encoded . '.signature-placeholder';
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
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
        $this->stubWp();
        Functions\when('get_transient')->justReturn(99);
        Functions\expect('wp_remote_post')->never();

        $this->assertSame(99, cdcf_zitadel_bearer_authenticate(false));
    }

    // ─── Userinfo-response branches ──────────────────────────────────

    public function test_valid_token_with_verified_email_returns_wp_user_id(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
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
        // Mint a JWT with a memorable marker in a non-aud claim so we can
        // assert the marker doesn't leak into the cache key.
        $token = $this->mintJwt(['sub' => 'sensitive-marker']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
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
        $this->assertStringNotContainsString($token, $captured_key);
        $this->assertSame('cdcf_zb_' . hash('sha256', $token), $captured_key);
    }

    public function test_email_verified_false_falls_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
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
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
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
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
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
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
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
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
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
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
        $this->stubWp();
        $err = new WP_Error('http_request_failed', 'connect timeout');
        Functions\when('wp_remote_post')->justReturn($err);
        Functions\expect('get_user_by')->never();
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    // ─── Header-extraction edge cases ────────────────────────────────

    // ─── Audience verification ───────────────────────────────────────

    public function test_token_with_wrong_audience_falls_through_without_userinfo_call(): void
    {
        // Sibling-property token: a token validly issued by Zitadel for
        // LitCal or OntoKit. Userinfo would happily return 200 for it.
        // Audience check must reject it BEFORE the userinfo round-trip.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt([
            'aud' => 'litcal-client-id-not-ours',
        ]);
        Functions\expect('wp_remote_post')->never();
        Functions\expect('get_transient')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_token_missing_aud_claim_falls_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt(['aud' => null]);
        Functions\expect('wp_remote_post')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_token_with_aud_array_containing_match_is_accepted(): void
    {
        // RFC 7519 permits aud to be either a string or string[]. Zitadel
        // emits a single string in practice, but the verifier must accept
        // either shape.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt([
            'aud' => ['some-other-client', self::TEST_EXPECTED_AUD, 'yet-another'],
        ]);
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'email'          => 'a@b.org',
            'email_verified' => true,
        ]));
        Functions\when('get_user_by')->alias(static fn(): WP_User => new WP_User(33));

        $this->assertSame(33, cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_opaque_token_not_a_jwt_falls_through(): void
    {
        // Some IdPs hand out opaque (non-JWT) access tokens. We require
        // JWT format (configured in cdcf-infra as OIDC_TOKEN_TYPE_JWT) so
        // the audience check is enforceable. Anything else = hard reject.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer opaque-token-no-dots';
        Functions\expect('wp_remote_post')->never();

        $this->assertFalse(cdcf_zitadel_bearer_authenticate(false));
    }

    public function test_audience_helper_rejects_when_allow_list_empty(): void
    {
        // Misconfigured deployment: CDCF_ZITADEL_EXPECTED_AUD left at the
        // default '' value (or whitespace-only) — every token must reject.
        // PHP constants can't be redefined at runtime, so test the helper
        // with the empty-allow-list argument directly.
        $this->assertFalse(
            cdcf_zitadel_bearer_audience_ok(['aud' => self::TEST_EXPECTED_AUD], [])
        );
        $this->assertFalse(
            cdcf_zitadel_bearer_audience_ok(['aud' => ['anything']], [])
        );
    }

    public function test_audience_helper_constant_time_compare(): void
    {
        // Exact-string match — no prefix/substring acceptance.
        $this->assertFalse(cdcf_zitadel_bearer_audience_ok(
            ['aud' => self::TEST_EXPECTED_AUD . 'x'],
            [self::TEST_EXPECTED_AUD]
        ));
        $this->assertFalse(cdcf_zitadel_bearer_audience_ok(
            ['aud' => substr(self::TEST_EXPECTED_AUD, 0, -1)],
            [self::TEST_EXPECTED_AUD]
        ));
        $this->assertTrue(cdcf_zitadel_bearer_audience_ok(
            ['aud' => self::TEST_EXPECTED_AUD],
            [self::TEST_EXPECTED_AUD]
        ));
    }

    // ─── Multi-audience allow-list (issue #173) ──────────────────────

    public function test_audience_helper_accepts_match_against_second_allow_list_entry(): void
    {
        // The shared WP backend serves both prod and non-prod frontends,
        // each registered as its own Zitadel client. A token minted by the
        // non-prod client must match the second allow-list entry.
        $allowed = ['prod-client-id', 'nonprod-client-id'];
        $this->assertTrue(cdcf_zitadel_bearer_audience_ok(
            ['aud' => 'nonprod-client-id'],
            $allowed
        ));
        $this->assertTrue(cdcf_zitadel_bearer_audience_ok(
            ['aud' => 'prod-client-id'],
            $allowed
        ));
    }

    public function test_audience_helper_rejects_token_aud_matching_none_of_allow_list(): void
    {
        $this->assertFalse(cdcf_zitadel_bearer_audience_ok(
            ['aud' => 'litcal-client-id'],
            ['cdcf-prod', 'cdcf-nonprod']
        ));
    }

    public function test_audience_helper_aud_array_matches_any_allow_list_entry(): void
    {
        // RFC 7519 permits aud as a string OR string-array. A token whose
        // aud is an array containing one of our allowed values must accept.
        $this->assertTrue(cdcf_zitadel_bearer_audience_ok(
            ['aud' => ['unrelated', 'cdcf-nonprod', 'other-thing']],
            ['cdcf-prod', 'cdcf-nonprod']
        ));
    }

    public function test_nonprod_aud_token_authenticates_through_full_filter(): void
    {
        // End-to-end: a token minted by the non-prod cdcf-website client
        // (aud = second entry of the allow-list) must pass audience check,
        // hit userinfo, and resolve to a WP user — same as a prod token.
        // This is the integration-level shape of issue #173.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt([
            'aud' => self::TEST_EXPECTED_AUD_NONPROD,
        ]);
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'email'          => 'author@example.org',
            'email_verified' => true,
        ]));
        Functions\when('get_user_by')->alias(static fn(): WP_User => new WP_User(55));

        $this->assertSame(55, cdcf_zitadel_bearer_authenticate(false));
    }

    // ─── Parser ──────────────────────────────────────────────────────

    public function test_parse_allowed_auds_handles_comma_separated_list(): void
    {
        $this->assertSame(
            ['abc', 'def'],
            cdcf_zitadel_bearer_parse_allowed_auds('abc,def')
        );
    }

    public function test_parse_allowed_auds_trims_whitespace_around_entries(): void
    {
        $this->assertSame(
            ['abc', 'def'],
            cdcf_zitadel_bearer_parse_allowed_auds(' abc , def ')
        );
    }

    public function test_parse_allowed_auds_drops_empty_and_whitespace_only_entries(): void
    {
        // Trailing comma, double comma, lone whitespace entry: all dropped
        // so a config-typo doesn't accidentally widen the allow-list.
        $this->assertSame(
            ['abc', 'def'],
            cdcf_zitadel_bearer_parse_allowed_auds('abc,,def,   ,')
        );
    }

    public function test_parse_allowed_auds_returns_empty_for_unset_constant(): void
    {
        // The defined()|define() default for the constant is '' — the
        // parser must turn that into [] so the helper fails closed.
        $this->assertSame([], cdcf_zitadel_bearer_parse_allowed_auds(''));
        $this->assertSame([], cdcf_zitadel_bearer_parse_allowed_auds('   '));
        $this->assertSame([], cdcf_zitadel_bearer_parse_allowed_auds(',,,'));
    }

    public function test_token_extracted_from_redirect_http_authorization_fallback(): void
    {
        // Apache + mod_rewrite + FCGI: the original HTTP_AUTHORIZATION
        // may land under REDIRECT_HTTP_AUTHORIZATION instead.
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $this->mintJwt();
        $this->stubWp();
        Functions\when('wp_remote_post')->justReturn($this->buildUserinfoResponse(200, [
            'email'          => 'a@b.org',
            'email_verified' => true,
        ]));
        Functions\when('get_user_by')->alias(static fn(): WP_User => new WP_User(11));

        $this->assertSame(11, cdcf_zitadel_bearer_authenticate(false));
    }
}
