<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/flush-opcache handler.
 *
 * Note: opcache_invalidate / opcache_reset are deliberately NOT mocked
 * through Brain Monkey. Two reasons:
 *
 *   1. Brain Monkey's `Functions\when()` eval-declares the symbol into
 *      the global scope for the rest of the PHP process. Patchwork's
 *      PHPUnit shutdown hook (Patchwork\Utils\clearOpcodeCaches)
 *      then calls opcache_reset() during process exit, hitting the
 *      leftover stub — which throws MissingFunctionExpectations
 *      because Brain Monkey has already torn down the Mockery binding.
 *
 *   2. Patchwork's redefinable-internals only works for functions
 *      that exist natively. Local dev (no opcache extension) and CI
 *      (opcache loaded by default in setup-php) disagree on whether
 *      the symbol exists, so we can't statically add it to
 *      patchwork.json without breaking one environment.
 *
 * Workaround: declare the missing internals via raw eval guarded by
 * function_exists. On CI the call goes to the real (no-op for a
 * non-existent path) opcache_invalidate / opcache_reset; on dev it
 * hits the eval'd stub that records the call into static class
 * properties. Either path leaves the symbol table clean for
 * Patchwork shutdown.
 */
final class FlushOpcacheHandlerTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: bool}>
     */
    public static array $invalidateCalls = [];
    public static int $resetCalls = 0;

    public static function setUpBeforeClass(): void
    {
        // Eval is used here only to declare missing PHP internals at
        // class-load time (PHP has no other way to conditionally add a
        // global function). The bodies are hardcoded; no user input
        // ever reaches eval.
        if (!function_exists('opcache_invalidate')) {
            // phpcs:ignore Squiz.PHP.Eval.Discouraged
            eval(
                'function opcache_invalidate($path, $force = false): bool {'
                . 'FlushOpcacheHandlerTest::$invalidateCalls[] = [$path, (bool) $force];'
                . 'return true;'
                . '}'
            );
        }
        if (!function_exists('opcache_reset')) {
            // phpcs:ignore Squiz.PHP.Eval.Discouraged
            eval(
                'function opcache_reset(): bool {'
                . 'FlushOpcacheHandlerTest::$resetCalls++;'
                . 'return true;'
                . '}'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        self::$invalidateCalls = [];
        self::$resetCalls = 0;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_all_three_flushes_when_opcache_available(): void
    {
        // Use expect (not when) so the tests fail loudly if the handler
        // ever stops calling these — the return value alone wouldn't
        // catch that regression for rest_ensure_response, since the
        // handler could return the same array shape directly.
        Functions\expect('flush_rewrite_rules')->once();
        Functions\expect('rest_ensure_response')
            ->once()
            ->andReturnUsing(static fn(array $payload): array => $payload);
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);

        $response = cdcf_rest_flush_opcache(new WP_REST_Request());

        $this->assertSame(['flushed' => ['functions.php', 'full-reset', 'rewrite-rules']], $response);
    }

    public function test_returns_only_rewrite_rules_when_opcache_unavailable(): void
    {
        // Use expect (not when) so the tests fail loudly if the handler
        // ever stops calling these — the return value alone wouldn't
        // catch that regression for rest_ensure_response, since the
        // handler could return the same array shape directly.
        Functions\expect('flush_rewrite_rules')->once();
        Functions\expect('rest_ensure_response')
            ->once()
            ->andReturnUsing(static fn(array $payload): array => $payload);
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => !in_array($name, ['opcache_invalidate', 'opcache_reset'], true)
        );

        $response = cdcf_rest_flush_opcache(new WP_REST_Request());

        $this->assertSame(['flushed' => ['rewrite-rules']], $response);
    }

    public function test_returns_invalidate_and_rewrite_rules_when_only_invalidate_available(): void
    {
        // Use expect (not when) so the tests fail loudly if the handler
        // ever stops calling these — the return value alone wouldn't
        // catch that regression for rest_ensure_response, since the
        // handler could return the same array shape directly.
        Functions\expect('flush_rewrite_rules')->once();
        Functions\expect('rest_ensure_response')
            ->once()
            ->andReturnUsing(static fn(array $payload): array => $payload);
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'opcache_reset'
        );

        $response = cdcf_rest_flush_opcache(new WP_REST_Request());

        $this->assertSame(['flushed' => ['functions.php', 'rewrite-rules']], $response);
    }

    public function test_passes_functions_file_constant_to_opcache_invalidate(): void
    {
        // Only meaningful on dev where the eval'd stub records calls.
        // On CI (opcache extension loaded) the real opcache_invalidate
        // runs against CDCF_FUNCTIONS_FILE (a non-existent path) as a
        // no-op and there's nothing to inspect.
        if (extension_loaded('Zend OPcache')) {
            $this->markTestSkipped(
                'Real opcache extension is loaded; call-tracking only works against the eval stub.'
            );
        }

        // Use expect (not when) so the tests fail loudly if the handler
        // ever stops calling these — the return value alone wouldn't
        // catch that regression for rest_ensure_response, since the
        // handler could return the same array shape directly.
        Functions\expect('flush_rewrite_rules')->once();
        Functions\expect('rest_ensure_response')
            ->once()
            ->andReturnUsing(static fn(array $payload): array => $payload);
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);

        cdcf_rest_flush_opcache(new WP_REST_Request());

        $this->assertSame([[CDCF_FUNCTIONS_FILE, true]], self::$invalidateCalls);
        $this->assertSame(1, self::$resetCalls);
    }
}
