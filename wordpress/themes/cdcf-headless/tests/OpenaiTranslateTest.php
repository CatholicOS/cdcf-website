<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_openai_translate() — the bounded-retry wrapper — and
 * _cdcf_openai_translate_attempt() — the single-shot HTTP call to OpenAI.
 *
 * Retry-policy tests stub _cdcf_openai_translate_attempt() directly with
 * a sequence of return values; HTTP-attempt tests stub wp_remote_post()
 * and friends.
 *
 * sleep() and error_log() are PHP built-ins, so they're listed in
 * patchwork.json under redefinable-internals; tests Patchwork\redefine
 * them to no-ops (sleep) or capture-helpers (error_log).
 */
final class OpenaiTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Always silence sleep + error_log so tests don't actually sleep
        // for 2+5 seconds on the retry-bounded test, and don't litter
        // PHPUnit output with retry-attempt log lines. Individual tests
        // re-redefine these when they need to assert on them.
        Patchwork\redefine('sleep', static fn(int $seconds): int => 0);
        Patchwork\redefine('error_log', static fn(string $msg): bool => true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ─── cdcf_openai_translate() retry policy ─────────────────────────

    public function test_returns_result_on_first_attempt_success(): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(
            function () use (&$calls): array {
                $calls++;
                return ['post_title' => 'Titolo'];
            }
        );
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['post_title' => 'Title'], 'English', 'Italian', 'sk-test');

        $this->assertSame(['post_title' => 'Titolo'], $result);
        $this->assertSame(1, $calls);
    }

    public function test_retries_on_openai_parse_error_then_succeeds(): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls) {
            $calls++;
            return $calls === 1
                ? new WP_Error('openai_parse', 'bad JSON')
                : ['k' => 'v'];
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        $this->assertSame(['k' => 'v'], $result);
        $this->assertSame(2, $calls);
    }

    public function test_retries_on_openai_empty_error_then_succeeds(): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls) {
            $calls++;
            return $calls === 1
                ? new WP_Error('openai_empty', 'empty response')
                : ['k' => 'v'];
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        $this->assertSame(['k' => 'v'], $result);
        $this->assertSame(2, $calls);
    }

    /**
     * @dataProvider provideRetryableStatuses
     */
    public function test_retries_on_retryable_http_status(int $status): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls, $status) {
            $calls++;
            return $calls === 1
                ? new WP_Error('openai_error', "HTTP {$status}", ['status' => $status])
                : ['k' => 'v'];
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        $this->assertSame(['k' => 'v'], $result);
        $this->assertSame(2, $calls);
    }

    public static function provideRetryableStatuses(): array
    {
        return [
            '408 request timeout' => [408],
            '429 too many requests' => [429],
            '500 internal' => [500],
            '502 bad gateway' => [502],
            '503 unavailable' => [503],
            '504 gateway timeout' => [504],
        ];
    }

    /**
     * @dataProvider provideHardFailureStatuses
     */
    public function test_does_not_retry_on_hard_4xx_failure(int $status): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls, $status) {
            $calls++;
            return new WP_Error('openai_error', "HTTP {$status}", ['status' => $status]);
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('openai_error', $result->get_error_code());
        // Hard 4xx — never retried.
        $this->assertSame(1, $calls);
    }

    public static function provideHardFailureStatuses(): array
    {
        return [
            '400 bad request' => [400],
            '401 unauthorized' => [401],
            '403 forbidden' => [403],
            '404 not found' => [404],
        ];
    }

    public function test_retries_on_curl_timeout_string_match(): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls) {
            $calls++;
            return $calls === 1
                ? new WP_Error('http_request_failed', 'cURL error 28: Operation timed out')
                : ['k' => 'v'];
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        $this->assertSame(['k' => 'v'], $result);
        $this->assertSame(2, $calls);
    }

    public function test_does_not_retry_on_non_timeout_http_request_failure(): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls) {
            $calls++;
            return new WP_Error('http_request_failed', 'cURL error 6: DNS resolution failed');
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(1, $calls);
    }

    public function test_returns_last_error_after_max_attempts_exhausted(): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls) {
            $calls++;
            return new WP_Error('openai_error', 'still down', ['status' => 503]);
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('openai_error', $result->get_error_code());
        // Three attempts then give up.
        $this->assertSame(3, $calls);
    }

    public function test_sleeps_between_retries_with_exponential_backoff(): void
    {
        $calls = 0;
        Functions\when('_cdcf_openai_translate_attempt')->alias(function () use (&$calls) {
            $calls++;
            return new WP_Error('openai_error', 'still down', ['status' => 503]);
        });
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $sleeps = [];
        Patchwork\redefine('sleep', function (int $seconds) use (&$sleeps): int {
            $sleeps[] = $seconds;
            return 0;
        });

        cdcf_openai_translate(['k' => 'v'], 'English', 'Italian', 'sk-test');

        // Backoff schedule from the function: 2s before retry 2, 5s before retry 3.
        // No sleep after the last failed attempt.
        $this->assertSame([2, 5], $sleeps);
    }

    // ─── _cdcf_openai_translate_attempt() HTTP layer ──────────────────

    public function test_attempt_returns_wp_error_when_wp_remote_post_fails(): void
    {
        $netError = new WP_Error('http_request_failed', 'cURL error 28: timed out');

        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('wp_remote_post')->justReturn($netError);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $result = _cdcf_openai_translate_attempt(['k' => 'v'], 'English', 'Italian', 'sk');

        $this->assertSame($netError, $result);
    }

    public function test_attempt_returns_openai_error_with_status_on_non_200(): void
    {
        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('wp_remote_post')->justReturn(['response_mock']);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(429);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'error' => ['message' => 'Rate limit exceeded'],
        ]));

        $result = _cdcf_openai_translate_attempt(['k' => 'v'], 'English', 'Italian', 'sk');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('openai_error', $result->get_error_code());
        $this->assertSame(['status' => 429], $result->get_error_data());
        $this->assertStringContainsString('Rate limit exceeded', $result->get_error_message());
    }

    public function test_attempt_returns_openai_empty_when_content_blank(): void
    {
        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('wp_remote_post')->justReturn(['ok']);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [['message' => ['content' => '']]],
        ]));

        $result = _cdcf_openai_translate_attempt(['k' => 'v'], 'English', 'Italian', 'sk');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('openai_empty', $result->get_error_code());
    }

    public function test_attempt_returns_openai_parse_when_content_is_not_json(): void
    {
        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('wp_remote_post')->justReturn(['ok']);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [['message' => ['content' => 'totally not JSON']]],
        ]));

        $result = _cdcf_openai_translate_attempt(['k' => 'v'], 'English', 'Italian', 'sk');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('openai_parse', $result->get_error_code());
    }

    public function test_attempt_parses_valid_json_response(): void
    {
        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('wp_remote_post')->justReturn(['ok']);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [['message' => ['content' => '{"post_title":"Titolo"}']]],
        ]));

        $result = _cdcf_openai_translate_attempt(
            ['post_title' => 'Title'],
            'English',
            'Italian',
            'sk'
        );

        $this->assertSame(['post_title' => 'Titolo'], $result);
    }

    public function test_attempt_strips_markdown_code_fence_around_json(): void
    {
        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('wp_remote_post')->justReturn(['ok']);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        // The model occasionally wraps JSON in a ```json fence despite
        // the system-prompt instruction not to. Stripping keeps the
        // happy path intact.
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [['message' => [
                'content' => "```json\n{\"k\":\"v\"}\n```",
            ]]],
        ]));

        $result = _cdcf_openai_translate_attempt(['k' => 'src'], 'English', 'Italian', 'sk');

        $this->assertSame(['k' => 'v'], $result);
    }

    public function test_attempt_sends_context_as_separate_user_message_when_provided(): void
    {
        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [['message' => ['content' => '{"k":"v"}']]],
        ]));

        $sentBody = null;
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$sentBody): array {
                $sentBody = json_decode($args['body'], true);
                return ['ok'];
            }
        );

        _cdcf_openai_translate_attempt(
            ['k' => 'src'],
            'English',
            'Italian',
            'sk',
            'Previous translated chunk tail …'
        );

        // Expected message ordering: system, context user message, payload user message.
        $this->assertCount(3, $sentBody['messages']);
        $this->assertSame('system', $sentBody['messages'][0]['role']);
        $this->assertSame('user',   $sentBody['messages'][1]['role']);
        $this->assertStringContainsString(
            'Previous translated chunk tail',
            $sentBody['messages'][1]['content']
        );
        $this->assertSame('user', $sentBody['messages'][2]['role']);
    }

    public function test_attempt_omits_context_message_when_context_empty(): void
    {
        Functions\when('get_option')->justReturn('gpt-4o-mini');
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [['message' => ['content' => '{"k":"v"}']]],
        ]));

        $sentBody = null;
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$sentBody): array {
                $sentBody = json_decode($args['body'], true);
                return ['ok'];
            }
        );

        _cdcf_openai_translate_attempt(['k' => 'src'], 'English', 'Italian', 'sk');

        // System + single user payload — no context message in between.
        $this->assertCount(2, $sentBody['messages']);
    }

    public function test_attempt_passes_model_option_to_request_body(): void
    {
        Functions\when('get_option')->alias(
            static fn(string $opt, $default = null) => $opt === 'cdcf_openai_model' ? 'gpt-4-turbo' : $default
        );
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [['message' => ['content' => '{"k":"v"}']]],
        ]));

        $sentBody = null;
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$sentBody): array {
                $sentBody = json_decode($args['body'], true);
                return ['ok'];
            }
        );

        _cdcf_openai_translate_attempt(['k' => 'src'], 'English', 'Italian', 'sk');

        $this->assertSame('gpt-4-turbo', $sentBody['model']);
    }
}
