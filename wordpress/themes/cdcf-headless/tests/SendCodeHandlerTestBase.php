<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Shared scaffolding for /cdcf/v1/refer-local-group/send-code,
 * /cdcf/v1/refer-community-project/send-code (both pointing at
 * cdcf_rest_send_verification_code) and /cdcf/v1/submit-project/send-code
 * (cdcf_rest_submit_project_send_code).
 *
 * All three share the same layered abuse-check pipeline: per-IP rate
 * limit → honeypot → timing → DNSBL → email format → disposable
 * domain → content spam → per-email rate limit → code generation →
 * wp_mail. Concrete subclasses supply just the function under test,
 * the IP transient prefix, and a request-payload factory.
 *
 * Brain Monkey ordering: every stub Brain Monkey needs to eval-declare
 * must be set up BEFORE function_exists is wholesale overridden. See
 * stubCommonFunctions() / allowAllFunctionsToExist().
 */
abstract class SendCodeHandlerTestBase extends TestCase
{
    abstract protected function invokeHandler(WP_REST_Request $request): mixed;

    /**
     * The 'cdcf_verify_' or 'cdcf_projv_' prefix used by the IP
     * rate-limit transient. Verified by the IP-rate-limit test below.
     */
    abstract protected function getIpTransientPrefix(): string;

    abstract protected function makeRequest(array $overrides = []): WP_REST_Request;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42'; // RFC-5737 test address
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    /**
     * Stub the side-effect-free WP/spam helpers in the happy-path
     * configuration. Tests override individual stubs to trigger
     * specific branches.
     */
    protected function stubCommonFunctions(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('is_email')->justReturn(true);
        Functions\when('cdcf_check_ip_rbl')->justReturn(false);
        Functions\when('cdcf_is_disposable_email')->justReturn(false);
        Functions\when('cdcf_is_spam_content')->justReturn(false);
        Functions\when('wp_mail')->justReturn(true);
    }

    protected function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    // ─── Abuse-check pipeline ─────────────────────────────────────

    public function test_returns_429_when_ip_rate_limit_already_exhausted(): void
    {
        $this->stubCommonFunctions();
        $expectedKey = $this->getIpTransientPrefix() . md5('198.51.100.42');
        Functions\when('get_transient')->alias(
            static fn(string $key) => $key === $expectedKey ? 5 : false
        );
        Functions\expect('wp_mail')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rate_limited', $response->get_error_code());
        $this->assertSame(429, $response->get_error_data()['status']);
    }

    public function test_returns_silent_success_when_honeypot_filled(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_mail')->never();
        Functions\expect('cdcf_check_ip_rbl')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest(['honeypot' => 'bot-filled']));

        // Silent success — the response shape matches a legit success
        // so bots can't tell their submission was rejected.
        $this->assertSame(['success' => true], $response);
    }

    public function test_returns_silent_success_when_form_filled_too_fast(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_mail')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest(['elapsed_ms' => 500]));

        $this->assertSame(['success' => true], $response);
    }

    public function test_returns_403_when_ip_is_on_dnsbl(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_check_ip_rbl')->justReturn(true);
        Functions\expect('wp_mail')->never();
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
        Functions\expect('wp_mail')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_email', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_email_domain_is_disposable(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_is_disposable_email')->justReturn(true);
        Functions\expect('wp_mail')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('disposable_email', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_silent_success_when_content_scores_as_spam(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_is_spam_content')->justReturn(true);
        Functions\expect('wp_mail')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertSame(['success' => true], $response);
    }

    public function test_returns_429_when_email_send_quota_exhausted(): void
    {
        $this->stubCommonFunctions();
        $sendsKey = 'cdcf_code_sends_' . md5('user@example.com');
        Functions\when('get_transient')->alias(
            static fn(string $key) => $key === $sendsKey ? 3 : false
        );
        Functions\expect('wp_mail')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rate_limited', $response->get_error_code());
        $this->assertStringContainsString('email', $response->get_error_message());
    }

    public function test_returns_500_when_wp_mail_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('wp_mail')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('mail_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_happy_path_stores_code_transient_and_emails_user(): void
    {
        $this->stubCommonFunctions();

        $transients = [];
        Functions\when('set_transient')->alias(
            function (string $key, $value, int $ttl) use (&$transients): bool {
                $transients[] = [$key, $value, $ttl];
                return true;
            }
        );

        $mailedTo = null;
        $mailedSubject = null;
        $mailedBody = null;
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body)
            use (&$mailedTo, &$mailedSubject, &$mailedBody): bool {
                $mailedTo = $to;
                $mailedSubject = $subject;
                $mailedBody = $body;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertSame(['success' => true], $response);

        // The 6-digit code is stored under cdcf_email_code_<md5(email)>
        // with a 10-minute TTL. Don't assert the exact code (it's random),
        // but assert the envelope.
        $codeKey = 'cdcf_email_code_' . md5('user@example.com');
        $codeWrite = null;
        foreach ($transients as $w) {
            if ($w[0] === $codeKey) {
                $codeWrite = $w;
                break;
            }
        }
        $this->assertNotNull($codeWrite, 'code transient should have been written');
        $this->assertSame(600, $codeWrite[2]);
        $this->assertSame(0, $codeWrite[1]['attempts']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codeWrite[1]['code']);

        $this->assertSame('user@example.com', $mailedTo);
        $this->assertSame('[CDCF] Your verification code', $mailedSubject);
        $this->assertStringContainsString($codeWrite[1]['code'], $mailedBody);
    }
}
