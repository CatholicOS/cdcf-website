<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * cdcf_request_client_ip(): every public request reaches WordPress from the
 * Next.js server, so the visitor's address is forwarded in a header signed
 * with the shared preview secret, and trusted only when the signature holds.
 */
final class ClientIpTest extends TestCase
{
    private const SECRET = 'test-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_text_field')->returnArg();
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5'; // the Next.js server
        putenv('CDCF_CLIENT_IP_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        putenv('CDCF_CLIENT_IP_SECRET');
        Monkey\tearDown();
        parent::tearDown();
    }

    private function request(?string $ip, ?string $signature): WP_REST_Request
    {
        $r = new WP_REST_Request();
        if ($ip !== null) {
            $r->set_header('X-CDCF-Client-IP', $ip);
        }
        if ($signature !== null) {
            $r->set_header('X-CDCF-Client-IP-Signature', $signature);
        }
        return $r;
    }

    private function sign(string $ip, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $ip, $secret);
    }

    public function test_a_correctly_signed_address_is_the_client(): void
    {
        $this->assertSame('203.0.113.9', cdcf_request_client_ip($this->request('203.0.113.9', $this->sign('203.0.113.9'))));
        $this->assertSame('2001:db8::7', cdcf_request_client_ip($this->request('2001:db8::7', $this->sign('2001:db8::7'))));
    }

    public function test_falls_back_to_the_connection_address_without_a_valid_signature(): void
    {
        $this->assertSame('10.0.0.5', cdcf_request_client_ip($this->request(null, null)));
        $this->assertSame('10.0.0.5', cdcf_request_client_ip($this->request('203.0.113.9', null)));
        $this->assertSame('10.0.0.5', cdcf_request_client_ip($this->request('203.0.113.9', $this->sign('203.0.113.9', 'other-secret'))));
        $this->assertSame('10.0.0.5', cdcf_request_client_ip($this->request('203.0.113.9', $this->sign('203.0.113.10'))));
    }

    public function test_a_signed_value_that_is_not_an_ip_is_ignored(): void
    {
        $this->assertSame('10.0.0.5', cdcf_request_client_ip($this->request('not-an-ip', $this->sign('not-an-ip'))));
    }

    public function test_nothing_is_trusted_when_no_secret_is_configured(): void
    {
        putenv('CDCF_CLIENT_IP_SECRET');
        $this->assertSame('10.0.0.5', cdcf_request_client_ip($this->request('203.0.113.9', hash_hmac('sha256', '203.0.113.9', ''))));
    }

    public function test_the_preview_secret_does_not_sign_addresses(): void
    {
        // It reaches browsers in preview URLs, so it must not be enough.
        putenv('CDCF_CLIENT_IP_SECRET');
        putenv('WP_PREVIEW_SECRET=' . self::SECRET);
        $this->assertSame('10.0.0.5', cdcf_request_client_ip($this->request('203.0.113.9', $this->sign('203.0.113.9'))));
        putenv('WP_PREVIEW_SECRET');
    }
}
