<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/donation-cancellation handler: a confirmation to
 * the donor who cancelled, and a notice with their reason to the Foundation.
 */
final class DonationCancellationHandlerTest extends TestCase
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $mails = [];

    /** Reset Brain Monkey and stub the WordPress functions every test needs. */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->mails = [];
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_option')->justReturn('admin@example.org');
        Functions\when('wp_date')->alias(
            fn(string $fmt, int $ts) => gmdate($fmt, $ts)
        );
    }

    /** Tear Brain Monkey and Mockery down after each test. */
    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /** Record every wp_mail call; each returns `$result`. */
    private function captureMail(bool $result = true): void
    {
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) use ($result) {
            $this->mails[] = [$to, $subject, $body];
            return $result;
        });
    }

    /** A REST request carrying the default fields, with any overrides applied. */
    private function request(array $overrides = []): WP_REST_Request
    {
        $params = array_merge([
            'event_id'         => 'evt_c',
            'livemode'         => true,
            'kind'             => 'scheduled',
            'subscription_id'  => 'sub_1',
            'email'            => 'donor@example.org',
            'donor_name'       => 'Jane Donor',
            'locale'           => 'en',
            'amount_cents'     => 2500,
            'project_title'    => 'BibleGet',
            'project_title_en' => 'BibleGet',
            'started_at'       => 1704067200, // 2024-01-01
            'ends_at'          => 1767225600, // 2026-01-01
            'occurred_at'      => 1764547200,
            'feedback'         => 'too_expensive',
            'comment'          => 'Tight month',
        ], $overrides);

        $req = new WP_REST_Request();
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }
        return $req;
    }

    /** The mail sent to `$to`; fails the test when there is none. */
    private function mailTo(string $to): array
    {
        foreach ($this->mails as $mail) {
            if ($mail[0] === $to) {
                return $mail;
            }
        }
        $this->fail("No mail was sent to {$to}");
    }

    /** A scheduled cancellation confirms the end date to the donor. */
    public function test_confirms_a_scheduled_cancellation_to_the_donor(): void
    {
        $this->captureMail();

        $res = cdcf_rest_donation_cancellation($this->request());

        $this->assertSame(['success' => true], $res);
        [, $subject, $body] = $this->mailTo('donor@example.org');
        $this->assertSame('Your monthly gift has been cancelled', $subject);
        $this->assertStringContainsString('$25.00', $body);
        $this->assertStringContainsString('for BibleGet', $body);
        $this->assertStringContainsString('2026-01-01', $body);
        $this->assertStringContainsString("—\nCatholic Digital Commons", $body);
    }

    /** The donor's copy is in their language. */
    public function test_writes_to_the_donor_in_their_language(): void
    {
        $this->captureMail();

        cdcf_rest_donation_cancellation($this->request(['locale' => 'it']));

        [, $subject, $body] = $this->mailTo('donor@example.org');
        $this->assertSame('Il tuo dono mensile è stato annullato', $subject);
        $this->assertStringContainsString('non verrà rinnovato', $body);
    }

    /** The Foundation hears who cancelled, what they gave and why. */
    public function test_notifies_the_foundation_with_the_reason(): void
    {
        $this->captureMail();

        cdcf_rest_donation_cancellation($this->request());

        [, $subject, $body] = $this->mailTo('admin@example.org');
        $this->assertStringContainsString('$25.00', $subject);
        $this->assertStringContainsString('Jane Donor <donor@example.org>', $body);
        $this->assertStringContainsString('Designation: BibleGet', $body);
        $this->assertStringContainsString('Giving since: 2024-01-01', $body);
        $this->assertStringContainsString('Last day: 2026-01-01', $body);
        $this->assertStringContainsString('Reason: Too expensive', $body);
        $this->assertStringContainsString('Comment: Tight month', $body);
        $this->assertStringContainsString(
            'https://dashboard.stripe.com/subscriptions/sub_1',
            $body
        );
    }

    /** The notice goes to CDCF_DONATION_NOTIFY_INBOX when it is defined. */
    public function test_sends_the_notice_to_the_configured_inbox(): void
    {
        $this->captureMail();
        Functions\when('cdcf_donation_notify_inbox')->justReturn('info@example.org');

        cdcf_rest_donation_cancellation($this->request());

        $this->assertSame('info@example.org', $this->mailTo('info@example.org')[0]);
        $this->assertCount(2, $this->mails);
    }

    /** A gift ended by failed payments is reported to the Foundation only. */
    public function test_reports_failed_payments_to_the_foundation_only(): void
    {
        $this->captureMail();

        cdcf_rest_donation_cancellation($this->request([
            'kind' => 'payment_failed', 'feedback' => '', 'comment' => '',
        ]));

        $this->assertCount(1, $this->mails);
        [$to, , $body] = $this->mails[0];
        $this->assertSame('admin@example.org', $to);
        $this->assertStringContainsString('payments failed', $body);
        $this->assertStringContainsString('Reason: none given', $body);
    }

    /** An immediate cancellation tells the donor there are no more charges. */
    public function test_confirms_an_immediate_cancellation(): void
    {
        $this->captureMail();

        cdcf_rest_donation_cancellation($this->request(['kind' => 'immediate']));

        [, , $body] = $this->mailTo('donor@example.org');
        $this->assertStringContainsString('You will not be charged again', $body);
    }

    /** With no donor address, only the Foundation is told. */
    public function test_skips_the_donor_when_there_is_no_address(): void
    {
        $this->captureMail();

        cdcf_rest_donation_cancellation($this->request(['email' => '']));

        $this->assertCount(1, $this->mails);
        $this->assertSame('admin@example.org', $this->mails[0][0]);
    }

    /** An undesignated gift is to the general fund. */
    public function test_names_the_general_fund_for_an_undesignated_gift(): void
    {
        $this->captureMail();

        cdcf_rest_donation_cancellation($this->request([
            'project_title' => '', 'project_title_en' => '',
        ]));

        [, , $donorBody] = $this->mailTo('donor@example.org');
        $this->assertStringNotContainsString(' for ', strtok($donorBody, "\n"));
        [, , $notice] = $this->mailTo('admin@example.org');
        $this->assertStringContainsString('Designation: General fund', $notice);
    }

    /** A test-mode event sends both mails to the test inbox, with test links. */
    public function test_test_mode_redirects_both_mails_to_the_test_inbox(): void
    {
        $this->captureMail();
        Functions\when('cdcf_donation_test_inbox')->justReturn('qa@example.org');

        cdcf_rest_donation_cancellation($this->request(['livemode' => false]));

        $this->assertCount(2, $this->mails);
        foreach ($this->mails as [$to]) {
            $this->assertSame('qa@example.org', $to);
        }
        $this->assertStringContainsString(
            'https://dashboard.stripe.com/test/subscriptions/sub_1',
            $this->mails[1][2]
        );
    }

    /** Without a test inbox, a test-mode event sends nothing. */
    public function test_test_mode_without_an_inbox_sends_nothing(): void
    {
        Functions\expect('wp_mail')->never();
        Functions\when('cdcf_donation_test_inbox')->justReturn('');

        $res = cdcf_rest_donation_cancellation($this->request(['livemode' => false]));

        $this->assertSame(['success' => true, 'skipped' => 'test_mode_no_inbox'], $res);
    }

    /** A redelivered event sends nothing twice. */
    public function test_a_second_delivery_sends_nothing(): void
    {
        Functions\when('get_transient')->justReturn(1);
        Functions\expect('wp_mail')->never();

        $res = cdcf_rest_donation_cancellation($this->request());

        $this->assertSame(['success' => true], $res);
    }

    /** A failed mail clears only its own guard, so the retry resends just it. */
    public function test_returns_500_and_clears_only_the_failed_guard(): void
    {
        $this->captureMail(false);
        $cleared = [];
        Functions\when('delete_transient')->alias(function ($key) use (&$cleared) {
            $cleared[] = $key;
            return true;
        });

        $res = cdcf_rest_donation_cancellation($this->request());

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame(500, $res->get_error_data()['status']);
        $this->assertSame(
            [
                'cdcf_stripe_evt_' . hash('sha256', 'evt_c:donor'),
                'cdcf_stripe_evt_' . hash('sha256', 'evt_c:foundation'),
            ],
            $cleared
        );
    }

    /** An unknown kind is refused rather than guessed at. */
    public function test_refuses_an_unknown_kind(): void
    {
        Functions\expect('wp_mail')->never();

        $res = cdcf_rest_donation_cancellation($this->request(['kind' => 'paused']));

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame(400, $res->get_error_data()['status']);
    }
}
