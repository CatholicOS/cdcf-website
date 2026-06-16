<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Shared scaffolding for the three public-submission handlers:
 *   - cdcf_rest_refer_local_group        (/refer-local-group)
 *   - cdcf_rest_refer_community_project  (/refer-community-project)
 *   - cdcf_rest_submit_project           (/submit-project)
 *
 * All three follow the same skeleton: per-IP rate limit → DNSBL →
 * email format → disposable domain → content spam → verification
 * code check → wp_insert_post → Polylang link → ACF field writes →
 * private-meta writes for the admin meta box → wp_mail to admin.
 *
 * Concrete subclasses supply only the function under test, the
 * per-handler IP transient prefix (different for each), and a
 * minimal request-payload factory.
 *
 * Brain Monkey ordering: stubs declared BEFORE the wholesale
 * function_exists override (otherwise FunctionStub short-circuits
 * and the symbols stay undefined at call time).
 */
abstract class SubmissionHandlerTestBase extends TestCase
{
    abstract protected function invokeHandler(WP_REST_Request $request): mixed;

    /** 'cdcf_refer_' / 'cdcf_refer_cp_' / 'cdcf_projsub_'. */
    abstract protected function getIpTransientPrefix(): string;

    abstract protected function makeRequest(array $overrides = []): WP_REST_Request;

    /** The CPT slug wp_insert_post is expected to use. */
    abstract protected function getExpectedPostType(): string;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    /**
     * Build a stored-code transient blob matching what
     * cdcf_rest_send_verification_code() would have written.
     */
    protected function storedCode(string $code = '123456', int $attempts = 0): array
    {
        return ['code' => $code, 'attempts' => $attempts];
    }

    /**
     * Stub the side-effect-free helpers + the abuse pipeline in the
     * happy-path configuration (code valid, no spam, no DNSBL hits).
     * The default request payload (subclass-provided) uses code
     * '123456'.
     */
    protected function stubCommonFunctions(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('get_transient')->alias(
            fn(string $key) => str_starts_with($key, 'cdcf_email_code_') ? $this->storedCode() : false
        );
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('is_email')->justReturn(true);
        Functions\when('cdcf_check_ip_rbl')->justReturn(false);
        Functions\when('cdcf_is_disposable_email')->justReturn(false);
        Functions\when('cdcf_is_spam_content')->justReturn(false);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('update_field')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('get_option')->justReturn('admin@cdcf.dev');
        Functions\when('admin_url')->returnArg(1);
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('esc_url_raw')->returnArg(1);
    }

    protected function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    // ─── Abuse-check pipeline ─────────────────────────────────────

    public function test_returns_429_when_per_ip_submission_quota_exhausted(): void
    {
        $this->stubCommonFunctions();
        $expectedKey = $this->getIpTransientPrefix() . md5('198.51.100.42');
        Functions\when('get_transient')->alias(
            static fn(string $key) => $key === $expectedKey ? 3 : false
        );
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rate_limited', $response->get_error_code());
        $this->assertSame(429, $response->get_error_data()['status']);
    }

    public function test_returns_403_when_ip_is_on_dnsbl(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_check_ip_rbl')->justReturn(true);
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('forbidden', $response->get_error_code());
        $this->assertSame(403, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_email_format_invalid(): void
    {
        $this->stubCommonFunctions();
        Functions\when('is_email')->justReturn(false);
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_email', $response->get_error_code());
    }

    public function test_returns_400_when_email_domain_is_disposable(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_is_disposable_email')->justReturn(true);
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('disposable_email', $response->get_error_code());
    }

    public function test_returns_silent_success_when_content_scores_as_spam(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_is_spam_content')->justReturn(true);
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        // Silent success: post_id=0 mimics a real submission so bots
        // can't tell their content tripped the spam scorer.
        $this->assertSame(['success' => true, 'post_id' => 0], $response);
    }

    // ─── Verification-code pipeline ───────────────────────────────

    public function test_returns_400_when_code_transient_missing_or_expired(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('code_expired', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_429_after_too_many_invalid_code_attempts(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_transient')->alias(
            fn(string $key) => str_starts_with($key, 'cdcf_email_code_')
                ? $this->storedCode('123456', 5)
                : false
        );
        $deleted = [];
        Functions\when('delete_transient')->alias(
            function (string $key) use (&$deleted): bool {
                $deleted[] = $key;
                return true;
            }
        );
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('too_many_attempts', $response->get_error_code());
        $this->assertSame(429, $response->get_error_data()['status']);
        // The stored code is purged after too-many-attempts so a fresh
        // request must obtain a new one.
        $this->assertContains(
            'cdcf_email_code_' . md5('user@example.com'),
            $deleted
        );
    }

    public function test_returns_400_and_increments_attempts_on_invalid_code(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_transient')->alias(
            fn(string $key) => str_starts_with($key, 'cdcf_email_code_')
                ? $this->storedCode('123456', 2)
                : false
        );

        $codeWrites = [];
        Functions\when('set_transient')->alias(
            function (string $key, $value, int $ttl) use (&$codeWrites): bool {
                if (str_starts_with($key, 'cdcf_email_code_')) {
                    $codeWrites[] = $value;
                }
                return true;
            }
        );
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest(['verification_code' => 'wrong']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_code', $response->get_error_code());

        // The handler bumps attempts from 2 → 3 and re-stores the
        // transient so the next attempt sees the updated counter.
        $this->assertNotEmpty($codeWrites);
        $this->assertSame(3, $codeWrites[0]['attempts']);
    }

    public function test_returns_500_when_wp_insert_post_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('wp_insert_post')->justReturn(0);
        Functions\expect('pll_set_post_language')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    // ─── Happy path (shared envelope) ─────────────────────────────

    public function test_happy_path_inserts_pending_post_and_emails_admin(): void
    {
        $this->stubCommonFunctions();

        $insertArgs = null;
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$insertArgs): int {
                $insertArgs = $args;
                return 800;
            }
        );

        $mailedTo = null;
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body) use (&$mailedTo): bool {
                $mailedTo = $to;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertSame(['success' => true, 'post_id' => 800], $response);
        $this->assertSame($this->getExpectedPostType(), $insertArgs['post_type']);
        $this->assertSame('pending', $insertArgs['post_status']);
        $this->assertSame('admin@cdcf.dev', $mailedTo);
    }

    public function test_happy_path_deletes_code_transient_so_it_cannot_be_replayed(): void
    {
        $this->stubCommonFunctions();

        $deleted = [];
        Functions\when('delete_transient')->alias(
            function (string $key) use (&$deleted): bool {
                $deleted[] = $key;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest());

        $this->assertContains(
            'cdcf_email_code_' . md5('user@example.com'),
            $deleted
        );
    }

    // ─── Submission language (CDCF_LOCALE_NAMES) ───────────────────

    public function test_uses_submission_language_from_request_when_provided(): void
    {
        // The public submission form now exposes a content-language
        // selector defaulting to the page's current locale, so that a
        // Spanish submission lands as a Spanish post and the
        // translation pipeline auto-creates EN/IT/FR/PT/DE siblings
        // (rather than starting as English and getting manually
        // re-tagged after the fact — the 2026-06-16 Enciclopedia
        // Católica regression that exposed three independent
        // hardcoded-EN bugs in the publish pipeline).
        $this->stubCommonFunctions();
        $langCalls = [];
        Functions\when('pll_set_post_language')->alias(
            function (int $post_id, string $lang) use (&$langCalls): bool {
                $langCalls[] = [$post_id, $lang];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest(['language' => 'es']));

        $this->assertSame(
            [[800, 'es']],
            $langCalls,
            'A Spanish submission must call pll_set_post_language with "es" for the newly inserted post — not the legacy hardcoded "en".'
        );
    }

    public function test_defaults_to_english_when_no_submission_language_provided(): void
    {
        // Back-compat: callers that don't supply a language (the
        // legacy contract) keep landing as English. The empty default
        // from the args block resolves to 'en' here.
        $this->stubCommonFunctions();
        $langCalls = [];
        Functions\when('pll_set_post_language')->alias(
            function (int $post_id, string $lang) use (&$langCalls): bool {
                $langCalls[] = [$post_id, $lang];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest(['language' => '']));

        $this->assertSame([[800, 'en']], $langCalls);
    }

    public function test_rejects_unsupported_submission_language_with_400(): void
    {
        // Defense in depth: the args block sanitizes the field, but
        // validation against the CDCF_LOCALE_NAMES allowlist lives in
        // the handler so a tampered request can't land posts in an
        // unconfigured Polylang language.
        $this->stubCommonFunctions();
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest(['language' => 'zh']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_language', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }
}
