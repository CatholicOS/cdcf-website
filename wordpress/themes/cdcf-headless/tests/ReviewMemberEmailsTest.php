<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewMemberEmailsTest extends TestCase
{
    private array $mails = [];
    private array $sent = [];
    private array $users = [];
    private ?array $event = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->mails = $this->sent = [];
        $this->users = [
            7 => ['email' => 'tech@example.org', 'name' => 'Dr. T', 'locale' => 'it_IT'],
            8 => ['email' => 'board@example.org', 'name' => 'Fr. A', 'locale' => 'en_US'],
            9 => ['email' => 'eccl@example.org', 'name' => 'Sr. E', 'locale' => 'xx'],
        ];
        $this->event = ['id' => 3, 'project_id' => 40, 'type' => 'decision_recorded', 'actor_user_id' => 8, 'entry_id' => null, 'data' => ['decision' => 'cdcf'], 'created_at' => 'x'];
        Functions\when('cdcf_review_member_user_ids')->justReturn([7, 8, 9]);
        Functions\when('cdcf_review_can')->alias(fn(int $uid) => isset($this->users[$uid]));
        Functions\when('cdcf_review_get_event')->alias(fn(int $id) => $id === 3 ? $this->event : null);
        Functions\when('get_userdata')->alias(function (int $uid) {
            if (!isset($this->users[$uid])) {
                return false;
            }
            $u = new WP_User($uid);
            $u->display_name = $this->users[$uid]['name'];
            $u->user_email = $this->users[$uid]['email'];
            return $u;
        });
        Functions\when('get_user_locale')->alias(fn(int $uid) => $this->users[$uid]['locale'] ?? 'en_US');
        Functions\when('is_email')->alias(static fn($e) => is_string($e) && str_contains($e, '@'));
        Functions\when('get_post_field')->justReturn('Liturgy Helper');
        Functions\when('get_post')->alias(static fn($id) => (object) ['ID' => $id]);
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = compact('to', 'subject', 'body');
            return true;
        });
        Functions\when('cdcf_review_enqueue_email')->justReturn(false);
    }

    private function base(): string
    {
        return defined('CDCF_FRONTEND_URL') ? rtrim((string) CDCF_FRONTEND_URL, '/') : 'http://localhost:3000';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_key_events_email_every_member_but_the_actor(): void
    {
        $this->assertSame([7, 9], cdcf_review_member_email_recipients('decision_recorded', 8, null));
        $this->assertSame([7, 8, 9], cdcf_review_member_email_recipients('submission_received', null, null));
        $this->assertSame([], cdcf_review_member_email_recipients('review_posted', 7, 5));
    }

    public function test_a_reply_emails_the_review_author_unless_they_wrote_it(): void
    {
        Functions\when('cdcf_review_reply_parent_author')->justReturn(7);

        $this->assertSame([7], cdcf_review_member_email_recipients('reply_posted', 8, 5));
        $this->assertSame([], cdcf_review_member_email_recipients('reply_posted', 7, 5));
    }

    public function test_a_reply_to_a_former_members_review_emails_nobody(): void
    {
        Functions\when('cdcf_review_reply_parent_author')->justReturn(55);

        $this->assertSame([], cdcf_review_member_email_recipients('reply_posted', 8, 5));
    }

    public function test_listener_sends_each_recipient_in_their_language(): void
    {
        cdcf_review_email_members_on_event(3, 40, 'decision_recorded', 8, null, ['decision' => 'cdcf']);

        $this->assertSame(['tech@example.org', 'eccl@example.org'], array_column($this->mails, 'to'));
        $this->assertSame('Decisione registrata: Liturgy Helper', $this->mails[0]['subject']);
        $this->assertStringContainsString('Fr. A ha registrato la decisione', $this->mails[0]['body']);
        $this->assertStringContainsString($this->base() . '/it/review/40', $this->mails[0]['body']);
        $this->assertSame('Decision recorded: Liturgy Helper', $this->mails[1]['subject']);
        $this->assertStringContainsString($this->base() . '/review/40', $this->mails[1]['body']);
    }

    public function test_backfilled_overrides_are_never_emailed(): void
    {
        cdcf_review_email_members_on_event(3, 40, 'admin_override', null, null, ['decision' => 'cdcf', 'backfill' => true]);

        $this->assertSame([], $this->mails);
    }

    public function test_queued_job_holds_only_ids(): void
    {
        $payloads = [];
        Functions\when('cdcf_review_enqueue_email')->alias(function (array $p) use (&$payloads): bool {
            $payloads[] = $p;
            return true;
        });

        $this->assertSame('queued', cdcf_review_send_member_email(7, 3));
        $this->assertSame([['member' => ['user_id' => 7, 'event_id' => 3]]], $payloads);
        $this->assertSame([], $this->mails);
    }

    public function test_reply_link_points_at_the_entry(): void
    {
        $this->event = ['id' => 3, 'project_id' => 40, 'type' => 'reply_posted', 'actor_user_id' => 8, 'entry_id' => 5, 'data' => [], 'created_at' => 'x'];

        $this->assertSame('sent', cdcf_review_deliver_member_email(7, 3));
        $this->assertStringContainsString($this->base() . '/it/review/40#entry-5', $this->mails[0]['body']);
    }

    public function test_send_time_checks_skip_without_failing(): void
    {
        $this->assertSame('skipped', cdcf_review_deliver_member_email(7, 999), 'event gone');
        $this->assertSame('skipped', cdcf_review_deliver_member_email(55, 3), 'no longer a member');
        $this->users[9]['email'] = 'not-an-address';
        $this->assertSame('skipped', cdcf_review_deliver_member_email(9, 3), 'no valid address');
        $this->event['type'] = 'review_posted';
        $this->assertSame('skipped', cdcf_review_deliver_member_email(7, 3), 'type not emailed');
        $this->assertSame([], $this->mails);
    }

    public function test_a_project_no_longer_there_sends_nothing(): void
    {
        Functions\when('get_post')->justReturn(null);

        $this->assertSame('skipped', cdcf_review_deliver_member_email(7, 3));
        $this->assertSame([], $this->mails);
    }

    public function test_a_deleted_actor_is_named_generically(): void
    {
        $this->event['actor_user_id'] = 55;

        cdcf_review_deliver_member_email(8, 3);

        $this->assertStringContainsString('Someone recorded the decision', $this->mails[0]['body']);
    }

    public function test_mail_failure_is_failed_and_logs_no_address(): void
    {
        Functions\when('wp_mail')->justReturn(false);
        $logs = [];
        Patchwork\redefine('error_log', function (string $m) use (&$logs): bool {
            $logs[] = $m;
            return true;
        });

        $this->assertSame('failed', cdcf_review_deliver_member_email(7, 3));
        $this->assertNotEmpty($logs);
        $this->assertStringNotContainsString('tech@example.org', implode("\n", $logs));
    }

    public function test_a_throwing_mailer_never_fails_the_request_and_the_rest_still_send(): void
    {
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            if ($to === 'tech@example.org') {
                throw new RuntimeException('smtp down for tech@example.org');
            }
            $this->mails[] = compact('to', 'subject', 'body');
            return true;
        });
        $logs = [];
        Patchwork\redefine('error_log', function (string $m) use (&$logs): bool {
            $logs[] = $m;
            return true;
        });

        cdcf_review_email_members_on_event(3, 40, 'decision_recorded', 8, null, ['decision' => 'cdcf']);

        $this->assertSame(['eccl@example.org'], array_column($this->mails, 'to'));
        $this->assertNotEmpty($logs);
        $this->assertStringNotContainsString('tech@example.org', implode("\n", $logs), 'exception text is never logged');
    }

    public function test_user_language(): void
    {
        $this->assertSame('it', cdcf_review_user_language(7));
        $this->assertSame('en', cdcf_review_user_language(9));
    }
}
