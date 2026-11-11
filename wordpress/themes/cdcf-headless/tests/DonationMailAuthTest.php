<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The donation mail endpoints send email as the Foundation, so they must be
 * reachable only by the Next.js server: an edit_posts account (any author or
 * contributor) is not enough on its own, and the link they mail must point
 * at Stripe's Customer Portal.
 */
final class DonationMailAuthTest extends TestCase
{
    private const SECRET = 'test-donation-mail-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        putenv('CDCF_DONATION_MAIL_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv('CDCF_DONATION_MAIL_SECRET');
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function signed(string $body, ?string $signature = null): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $req->set_body($body);
        $req->set_header(
            'X-CDCF-Signature',
            $signature ?? hash_hmac('sha256', $body, self::SECRET)
        );
        return $req;
    }

    public function test_accepts_an_editor_with_a_valid_signature(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $this->assertTrue(cdcf_donation_permission_check($this->signed('{"a":1}')));
    }

    public function test_rejects_a_valid_signature_without_edit_posts(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->assertFalse(cdcf_donation_permission_check($this->signed('{"a":1}')));
    }

    public function test_rejects_an_editor_without_a_signature(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $req = new WP_REST_Request();
        $req->set_body('{"a":1}');

        $this->assertFalse(cdcf_donation_permission_check($req));
    }

    public function test_rejects_a_signature_over_a_different_body(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $sig = hash_hmac('sha256', '{"a":1}', self::SECRET);

        $this->assertFalse(cdcf_donation_permission_check($this->signed('{"a":2}', $sig)));
    }

    public function test_fails_closed_when_no_secret_is_configured(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $req = $this->signed('{"a":1}', hash_hmac('sha256', '{"a":1}', ''));
        putenv('CDCF_DONATION_MAIL_SECRET');

        $this->assertFalse(cdcf_donation_permission_check($req));
    }

    public function test_portal_url_must_be_a_stripe_billing_link(): void
    {
        $this->assertTrue(cdcf_donation_is_portal_url('https://billing.stripe.com/p/session/xyz'));
        $this->assertTrue(cdcf_donation_is_portal_url(''));
        $this->assertFalse(cdcf_donation_is_portal_url('https://evil.example/p/session/xyz'));
        $this->assertFalse(cdcf_donation_is_portal_url('http://billing.stripe.com/p/session/xyz'));
        $this->assertFalse(cdcf_donation_is_portal_url('https://billing.stripe.com.evil.example/x'));
    }

    public function test_acknowledgment_refuses_a_foreign_portal_link(): void
    {
        Functions\expect('wp_mail')->never();

        $req = new WP_REST_Request();
        foreach ([
            'event_id' => 'evt_1', 'livemode' => true, 'email' => 'd@example.org',
            'amount_cents' => 500, 'frequency' => 'monthly', 'locale' => 'en',
            'occurred_at' => 1, 'portal_url' => 'https://evil.example/phish',
        ] as $k => $v) {
            $req->set_param($k, $v);
        }

        $res = cdcf_rest_donation_acknowledgment($req);

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('invalid_portal_url', $res->get_error_code());
    }

    public function test_portal_link_mailer_refuses_a_foreign_link(): void
    {
        Functions\expect('wp_mail')->never();

        $req = new WP_REST_Request();
        $req->set_param('email', 'd@example.org');
        $req->set_param('locale', 'en');
        $req->set_param('portal_url', 'https://evil.example/phish');

        $res = cdcf_rest_donation_portal_link($req);

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('invalid_portal_url', $res->get_error_code());
    }
}
