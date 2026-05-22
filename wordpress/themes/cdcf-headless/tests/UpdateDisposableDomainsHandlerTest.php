<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/update-disposable-domains handler.
 *
 * Brain Monkey covers the WP HTTP layer (wp_remote_get,
 * wp_remote_retrieve_*) and is_wp_error. Filesystem side effects
 * (file_put_contents, fopen, fsync, rename, unlink) happen for real
 * on a tmp path defined by CDCF_DISPOSABLE_DOMAINS_FILE in the
 * bootstrap; each test cleans up afterwards.
 */
final class UpdateDisposableDomainsHandlerTest extends TestCase
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

        // Clean tmp files between tests so writes from one don't leak
        // into another's assertions.
        $main = CDCF_DISPOSABLE_DOMAINS_FILE;
        foreach (glob($main . '*') ?: [] as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    /**
     * Build a body that yields at least $count non-empty lines after
     * the trim+filter pipeline the handler uses.
     */
    private function bodyWithDomains(int $count): string
    {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = "mail{$i}.example.com";
        }
        return implode("\n", $lines) . "\n";
    }

    private function makeRequest(): WP_REST_Request
    {
        return new WP_REST_Request();
    }

    // ─── Upstream HTTP failures ───────────────────────────────────

    public function test_returns_502_when_wp_remote_get_returns_wp_error(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_get')->justReturn(new WP_Error('http_timeout', 'Connection timed out'));

        $response = cdcf_rest_update_disposable_domains($this->makeRequest());

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(502, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['success']);
        $this->assertSame('Connection timed out', $data['error']);
    }

    public function test_returns_502_on_non_200_http_code(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_get')->justReturn(['response_stub' => true]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);

        $response = cdcf_rest_update_disposable_domains($this->makeRequest());

        $this->assertSame(502, $response->get_status());
        $this->assertSame('GitHub returned HTTP 503', $response->get_data()['error']);
    }

    // ─── Payload validation ───────────────────────────────────────

    public function test_returns_422_when_downloaded_list_is_suspiciously_small(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_get')->justReturn(['response_stub' => true]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        // Only 5 domains in the response — well below the 100-line guard.
        Functions\when('wp_remote_retrieve_body')->justReturn($this->bodyWithDomains(5));

        $response = cdcf_rest_update_disposable_domains($this->makeRequest());

        $this->assertSame(422, $response->get_status());
        $this->assertStringContainsString('suspiciously small', $response->get_data()['error']);
        $this->assertStringContainsString('(5 domains)', $response->get_data()['error']);
        // No file should have been written.
        $this->assertFileDoesNotExist(CDCF_DISPOSABLE_DOMAINS_FILE);
    }

    public function test_filters_blank_lines_before_counting(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_get')->justReturn(['response_stub' => true]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        // 50 real domains padded with blank lines — still under 100
        // after trim+array_filter strips the blanks.
        $body = $this->bodyWithDomains(50) . str_repeat("\n   \n\t\n", 100);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);

        $response = cdcf_rest_update_disposable_domains($this->makeRequest());

        $this->assertSame(422, $response->get_status());
        $this->assertStringContainsString('(50 domains)', $response->get_data()['error']);
    }

    // ─── Happy path ───────────────────────────────────────────────

    public function test_happy_path_writes_file_and_returns_success(): void
    {
        $body = $this->bodyWithDomains(150);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_remote_get')->justReturn(['response_stub' => true]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('rest_ensure_response')->returnArg(1);

        $response = cdcf_rest_update_disposable_domains($this->makeRequest());

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertSame(150, $response['domains']);
        $this->assertSame(strlen($body), $response['bytes']);

        // The target file should now exist and contain exactly the
        // downloaded body. The .tmp.* file is gone (renamed away).
        $this->assertFileExists(CDCF_DISPOSABLE_DOMAINS_FILE);
        $this->assertSame($body, file_get_contents(CDCF_DISPOSABLE_DOMAINS_FILE));
        $this->assertEmpty(glob(CDCF_DISPOSABLE_DOMAINS_FILE . '.tmp.*'));
    }

    // ─── Filesystem failure paths ─────────────────────────────────
    //
    // The "Failed to write temp file" and "Failed to rename" branches
    // are defensive guards around file_put_contents() and rename().
    // Testing them cleanly would require either Patchwork's
    // redefinable-internals (to stub the PHP built-ins) or a separate
    // process to redefine CDCF_DISPOSABLE_DOMAINS_FILE to an unwritable
    // location. Given they're simple two-line error-response branches
    // around well-understood PHP behavior, the test-infrastructure
    // cost outweighs the coverage gain. Tracked for follow-up if the
    // numbers ever matter enough.
}
