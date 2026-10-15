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
        foreach ($this->logs as $line) {
            $this->assertStringNotContainsString('redis down', $line, 'exception text is never logged');
        }
    }

    public function test_falls_back_inline_when_enqueue_returns_false(): void
    {
        // The plugin's enqueue() never throws: every failure returns false.
        $manager = new class {
            public function enqueue($job) { return false; }
        };
        Functions\when('redis_queue')->justReturn((object) ['queue_manager' => $manager]);
        $old = $this->submission();

        $this->assertSame('sent', cdcf_review_send_submitter_email(42, 'link'));
        $this->assertCount(1, $this->mails);
        $this->assertFalse(cdcf_review_token_matches(42, $old), 'the inline email carried the new link');
    }

    public function test_a_job_queued_before_the_payload_change_still_activates_its_link(): void
    {
        $this->submission();
        $token = cdcf_review_new_token();
        $job = new CDCF_Review_Email_Job([
            'to' => 'sam@example.org', 'subject' => 'S', 'body' => 'B',
            'token' => ['project_id' => 42, 'hash' => $token['hash'], 'issued_at' => $token['issued_at']],
        ]);

        $this->assertTrue($job->execute()['success']);
        $this->assertTrue(cdcf_review_token_matches(42, $token['token']));
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

    /** Post meta for project 42: a submitter address and an existing, working link. */
    private array $meta = [];

    private function submission(): string
    {
        $old = str_repeat('a', 64);
        $this->meta = [
            '_submission_submitter_email'      => 'sam@example.org',
            CDCF_REVIEW_TOKEN_HASH_META        => hash('sha256', $old),
            CDCF_REVIEW_TOKEN_ISSUED_META      => '1.000000',
        ];
        Functions\when('get_post_meta')->alias(fn(int $id, string $key, bool $single = false) => $this->meta[$key] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$key] = $value;
            return true;
        });
        Functions\when('is_email')->alias(fn($e) => str_contains((string) $e, '@'));
        Functions\when('get_post_field')->justReturn('Liturgy Helper');
        Functions\when('cdcf_review_post_language')->justReturn('de');
        return $old;
    }

    private function linkToken(string $body): string
    {
        $this->assertSame(1, preg_match('/[?&]t=([0-9a-f]{64})/', $body, $m));
        return $m[1];
    }

    public function test_submitter_email_uses_the_submission_language_and_a_fresh_link(): void
    {
        $this->noQueue();
        $old = $this->submission();

        $this->assertSame('sent', cdcf_review_send_submitter_email(42, 'question', ['question' => 'Welche Lizenz?']));
        $this->assertSame('sam@example.org', $this->mails[0]['to']);
        $this->assertStringContainsString('Liturgy Helper', $this->mails[0]['subject']);
        $this->assertStringContainsString('lang=de&t=', $this->mails[0]['body']);
        $this->assertStringContainsString('Welche Lizenz?', $this->mails[0]['body']);

        $new = $this->linkToken($this->mails[0]['body']);
        $this->assertNotSame($old, $new);
        $this->assertTrue(cdcf_review_token_matches(42, $new), 'the emailed link works once sent');
        $this->assertFalse(cdcf_review_token_matches(42, $old));
    }

    public function test_a_failed_inline_email_keeps_the_submitters_working_link(): void
    {
        $this->noQueue();
        $old = $this->submission();
        Functions\when('wp_mail')->justReturn(false);

        $this->assertSame('failed', cdcf_review_send_submitter_email(42, 'question', ['question' => 'Q?']));
        $this->assertTrue(cdcf_review_token_matches(42, $old));
    }

    public function test_a_queued_submitter_email_stores_no_address_link_or_token(): void
    {
        $this->submission();
        $manager = new class {
            public array $jobs = [];
            public function enqueue($job) { $this->jobs[] = $job; return 'job-1'; }
        };
        Functions\when('redis_queue')->justReturn((object) ['queue_manager' => $manager]);

        $this->assertSame('queued', cdcf_review_send_submitter_email(42, 'question', ['question' => 'Q?']));
        // The redis-queue plugin keeps job records in MySQL indefinitely, so
        // the payload must hold nothing secret.
        $payload = $manager->jobs[0]->get_payload();
        $this->assertSame(['submitter' => ['project_id' => 42, 'template' => 'question', 'vars' => ['question' => 'Q?']]], $payload);
        $this->assertStringNotContainsString('sam@example.org', serialize($payload));
        $this->assertSame([], $this->mails, 'nothing is sent until the job runs');
    }

    public function test_the_queued_job_mints_the_link_and_commits_it_only_once_sent(): void
    {
        $old = $this->submission();
        $job = new CDCF_Review_Email_Job(['submitter' => ['project_id' => 42, 'template' => 'link', 'vars' => []]]);

        Functions\when('wp_mail')->justReturn(false);
        try {
            $job->execute();
            $this->fail('a failed send must throw so the queue retries');
        } catch (RuntimeException $e) {
            $this->assertTrue(cdcf_review_token_matches(42, $old), 'a failed attempt keeps the working link');
        }

        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = compact('to', 'subject', 'body');
            return true;
        });
        $this->assertTrue($job->execute()['success']);
        $this->assertSame('sam@example.org', $this->mails[0]['to']);
        $new = $this->linkToken($this->mails[0]['body']);
        $this->assertTrue(cdcf_review_token_matches(42, $new));
        $this->assertFalse(cdcf_review_token_matches(42, $old));
    }

    public function test_a_queued_job_for_a_submission_without_an_address_does_nothing(): void
    {
        $this->submission();
        $this->meta['_submission_submitter_email'] = '';
        $job = new CDCF_Review_Email_Job(['submitter' => ['project_id' => 42, 'template' => 'link', 'vars' => []]]);

        $this->assertTrue($job->execute()['success']);
        $this->assertSame([], $this->mails);
    }

    public function test_submitter_email_is_skipped_without_a_valid_address(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_email')->justReturn(false);
        Functions\expect('cdcf_review_new_token')->never();

        $this->assertSame('no_email', cdcf_review_send_submitter_email(42, 'link'));
        $this->assertSame([], $this->mails);
    }

    public function test_queue_factory_rebuilds_an_email_job_from_its_payload(): void
    {
        $payload = ['to' => 'a@b.c', 'subject' => 'S', 'body' => 'B'];
        $job = cdcf_review_create_email_job(null, 'cdcf_review_email', $payload);

        $this->assertInstanceOf(CDCF_Review_Email_Job::class, $job);
        $this->assertSame($payload, $job->get_payload());
    }

    public function test_queue_factory_passes_other_job_types_through_unchanged(): void
    {
        $other = new stdClass();

        $this->assertSame($other, cdcf_review_create_email_job($other, 'cdcf_translation', ['x' => 1]));
    }
}
