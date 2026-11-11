<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/donation-acknowledgment handler.
 */
final class DonationAcknowledgmentHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        // wp_mail and delete_transient are stubbed per test: a when() here
        // would win over a later expect(), so its call count never registers.
        Functions\when('date_i18n')->alias(
            fn(string $fmt, int $ts) => gmdate($fmt, $ts)
        );
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
            'event_id'      => 'evt_123',
            'livemode'      => true,
            'email'         => 'donor@example.org',
            'donor_name'    => 'Jane Donor',
            'amount_cents'  => 5000,
            'currency'      => 'usd',
            'frequency'     => 'once',
            'locale'        => 'en',
            'project_title' => 'BibleGet',
            'occurred_at'   => 1755777600,
            'portal_url'    => '',
        ], $overrides);

        $req = new WP_REST_Request();
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }
        return $req;
    }

    public function test_sends_one_email_on_the_happy_path(): void
    {
        Functions\expect('wp_mail')
            ->once()
            ->with(
                'donor@example.org',
                Mockery::type('string'),
                Mockery::type('string')
            )
            ->andReturn(true);

        $res = cdcf_rest_donation_acknowledgment($this->request());

        $this->assertSame(['success' => true], $res);
    }

    public function test_body_carries_the_english_substantiation_sentence(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['locale' => 'it']));

        // Never translated: it substantiates a US tax deduction.
        $this->assertStringContainsString(
            'No goods or services were provided in exchange for this contribution.',
            $captured
        );
    }

    public function test_body_is_localized_around_the_english_sentence(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['locale' => 'it']));

        $this->assertStringContainsString('Grazie', $captured);
    }

    public function test_falls_back_to_english_for_an_unknown_locale(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['locale' => 'ja']));

        $this->assertStringContainsString('Thank you', $captured);
    }

    public function test_formats_the_amount_as_dollars_not_cents(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['amount_cents' => 12345]));

        $this->assertStringContainsString('$123.45', $captured);
    }

    public function test_omits_the_designation_line_for_an_undesignated_gift(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['project_title' => '']));

        $this->assertStringNotContainsString('Designated', $captured);
    }

    public function test_includes_the_portal_link_for_a_recurring_gift(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request([
            'frequency'  => 'monthly',
            'portal_url' => 'https://billing.stripe.com/p/session/xyz',
        ]));

        $this->assertStringContainsString(
            'https://billing.stripe.com/p/session/xyz',
            $captured
        );
    }

    public function test_second_delivery_of_the_same_event_sends_nothing(): void
    {
        Functions\when('get_transient')->justReturn(1);
        Functions\expect('wp_mail')->never();

        $res = cdcf_rest_donation_acknowledgment($this->request());

        $this->assertSame(['success' => true, 'duplicate' => true], $res);
    }

    public function test_test_mode_redirects_mail_to_the_configured_inbox(): void
    {
        if (!defined('CDCF_DONATION_TEST_INBOX')) {
            define('CDCF_DONATION_TEST_INBOX', 'qa@example.org');
        }

        Functions\expect('wp_mail')
            ->once()
            ->with('qa@example.org', Mockery::type('string'), Mockery::type('string'))
            ->andReturn(true);

        $res = cdcf_rest_donation_acknowledgment($this->request(['livemode' => false]));

        $this->assertSame(['success' => true], $res);
    }

    public function test_returns_500_and_clears_the_guard_when_mail_fails(): void
    {
        Functions\when('wp_mail')->justReturn(false);
        // Without clearing the guard, Stripe's retry would be swallowed as
        // a duplicate and the acknowledgment lost forever.
        Functions\expect('delete_transient')->once();

        $res = cdcf_rest_donation_acknowledgment($this->request());

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('mail_failed', $res->get_error_code());
    }
}
