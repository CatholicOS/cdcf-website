<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewMailerTest extends TestCase
{
    private array $mails = [];
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->mails = [];
        $this->logs = [];
        Patchwork\redefine('error_log', function (string $msg): bool {
            $this->logs[] = $msg;
            return true;
        });
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = compact('to', 'subject', 'body');
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function noQueue(): void
    {
        Patchwork\redefine('function_exists', fn(string $name) => $name === 'redis_queue' ? false : Patchwork\relay());
    }

    public function test_status_url_points_at_the_open_route_with_lang_and_token(): void
    {
        $base = defined('CDCF_FRONTEND_URL') ? rtrim((string) CDCF_FRONTEND_URL, '/') : 'http://localhost:3000';

        $this->assertSame(
            $base . '/api/submission-status/42/open?lang=it&t=abc123',
            cdcf_review_status_url(42, 'it', 'abc123')
        );
    }

    public function test_sends_inline_when_the_queue_plugin_is_absent(): void
    {
        $this->noQueue();

        $this->assertSame('sent', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertSame([['to' => 'sam@example.org', 'subject' => 'S', 'body' => 'B']], $this->mails);
    }

    public function test_queues_a_job_when_the_queue_plugin_is_active(): void
    {
        $manager = new class {
            public array $jobs = [];
            public function enqueue($job) { $this->jobs[] = $job; return 'job-1'; }
        };
        Functions\when('redis_queue')->justReturn((object) ['queue_manager' => $manager]);

        $this->assertSame('queued', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertCount(1, $manager->jobs);
        $this->assertInstanceOf(CDCF_Review_Email_Job::class, $manager->jobs[0]);
        $this->assertSame(['to' => 'sam@example.org', 'subject' => 'S', 'body' => 'B'], $manager->jobs[0]->get_payload());
        $this->assertSame([], $this->mails);
    }

    public function test_falls_back_inline_when_enqueue_throws(): void
    {
        $manager = new class {
            public function enqueue($job) { throw new RuntimeException('redis down'); }
        };
        Functions\when('redis_queue')->justReturn((object) ['queue_manager' => $manager]);

        $this->assertSame('sent', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertCount(1, $this->mails);
    }

    public function test_reports_failure_without_logging_the_address(): void
    {
        $this->noQueue();
        Functions\when('wp_mail')->justReturn(false);

        $this->assertSame('failed', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertNotEmpty($this->logs);
        foreach ($this->logs as $line) {
            $this->assertStringNotContainsString('sam@example.org', $line);
        }
    }

    public function test_job_sends_and_throws_on_failure_so_the_queue_retries(): void
    {
        $job = new CDCF_Review_Email_Job(['to' => 'sam@example.org', 'subject' => 'S', 'body' => 'B']);
        $this->assertSame('cdcf_review_email', $job->get_job_type());
        $this->assertTrue($job->execute()['success']);

        Functions\when('wp_mail')->justReturn(false);
        $this->expectException(RuntimeException::class);
        $job->execute();
    }

    public function test_submitter_email_rotates_the_token_and_uses_the_submission_language(): void
    {
        $this->noQueue();
        Functions\when('get_post_meta')->justReturn('sam@example.org');
        Functions\when('is_email')->alias(fn($e) => str_contains((string) $e, '@'));
        Functions\when('get_post_field')->justReturn('Liturgy Helper');
        Functions\when('cdcf_review_post_language')->justReturn('de');
        Functions\expect('cdcf_review_issue_token')->once()->with(42)->andReturn('tok');

        $this->assertSame('sent', cdcf_review_send_submitter_email(42, 'question', ['question' => 'Welche Lizenz?']));
        $this->assertSame('sam@example.org', $this->mails[0]['to']);
        $this->assertStringContainsString('Liturgy Helper', $this->mails[0]['subject']);
        $this->assertStringContainsString('lang=de&t=tok', $this->mails[0]['body']);
        $this->assertStringContainsString('Welche Lizenz?', $this->mails[0]['body']);
    }

    public function test_submitter_email_is_skipped_without_a_valid_address(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_email')->justReturn(false);
        Functions\expect('cdcf_review_issue_token')->never();

        $this->assertSame('no_email', cdcf_review_send_submitter_email(42, 'link'));
        $this->assertSame([], $this->mails);
    }
}
