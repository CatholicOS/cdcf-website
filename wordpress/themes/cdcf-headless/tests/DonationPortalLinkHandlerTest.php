<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/donation-portal-link handler.
 */
final class DonationPortalLinkHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('rest_ensure_response')->returnArg(1);
        // wp_mail is stubbed per test: a when() here would win over the
        // expect()->never() below, so that assertion would never fire.
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function request(array $overrides = []): WP_REST_Request
    {
        $params = array_merge([
            'email'      => 'donor@example.org',
            'locale'     => 'en',
            'portal_url' => 'https://billing.stripe.com/p/session/xyz',
        ], $overrides);

        $req = new WP_REST_Request();
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }
        return $req;
    }

    public function test_mails_the_portal_link_to_the_donor(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        $res = cdcf_rest_donation_portal_link($this->request());

        $this->assertSame(['success' => true], $res);
        $this->assertStringContainsString(
            'https://billing.stripe.com/p/session/xyz',
            $captured
        );
    }

    public function test_localizes_the_message(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_portal_link($this->request(['locale' => 'de']));

        $this->assertStringContainsString('monatliche Spende', $captured);
    }

    public function test_sends_nothing_when_no_portal_url_was_resolved(): void
    {
        // The caller found no Stripe customer for this address. Sending
        // nothing is correct; the endpoint still reports success so the
        // response cannot be used to probe who has donated.
        Functions\expect('wp_mail')->never();

        $res = cdcf_rest_donation_portal_link($this->request(['portal_url' => '']));

        $this->assertSame(['success' => true], $res);
    }

    public function test_returns_500_when_mail_fails(): void
    {
        Functions\when('wp_mail')->justReturn(false);

        $res = cdcf_rest_donation_portal_link($this->request());

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('mail_failed', $res->get_error_code());
    }
}
