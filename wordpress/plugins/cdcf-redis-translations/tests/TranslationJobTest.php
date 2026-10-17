<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CDCF_Translation_Job — the Redis-Queue job class that
 * dispatches background translations to cdcf_process_translation()
 * in the cdcf-headless theme.
 *
 * The class extends Soderlind\RedisQueue\Jobs\Abstract_Base_Job from
 * the upstream redis-queue plugin; tests use the stub in tests/stubs/.
 *
 * Brain Monkey ordering: declare every stub Brain Monkey needs to
 * eval-declare BEFORE the wholesale function_exists override
 * (FunctionStub short-circuits otherwise and leaves symbols
 * undefined at call time).
 */
final class TranslationJobTest extends TestCase
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
        parent::tearDown();
    }

    public function test_job_type_identifier_is_cdcf_translation(): void
    {
        $job = new CDCF_Translation_Job([]);
        $this->assertSame('cdcf_translation', $job->get_job_type());
    }

    public function test_throws_when_post_id_missing(): void
    {
        $job = new CDCF_Translation_Job([
            'source_id'   => 100,
            'target_lang' => 'it',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required payload fields');

        $job->execute();
    }

    public function test_throws_when_source_id_missing(): void
    {
        $job = new CDCF_Translation_Job([
            'post_id'     => 200,
            'target_lang' => 'it',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required payload fields');

        $job->execute();
    }

    public function test_throws_when_target_lang_missing(): void
    {
        $job = new CDCF_Translation_Job([
            'post_id'   => 200,
            'source_id' => 100,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required payload fields');

        $job->execute();
    }

    public function test_throws_when_cdcf_process_translation_unavailable(): void
    {
        // function_exists('cdcf_process_translation') returns false →
        // job throws to signal a misconfigured environment (the headless
        // theme isn't active or hasn't loaded the worker pipeline).
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'cdcf_process_translation'
        );

        $job = new CDCF_Translation_Job([
            'post_id'     => 200,
            'source_id'   => 100,
            'target_lang' => 'it',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cdcf_process_translation() not available');

        $job->execute();
    }

    public function test_throws_when_cdcf_process_translation_returns_wp_error(): void
    {
        // The job MUST throw on WP_Error so redis-queue's retry_attempts +
        // retry_backoff engages. Without this, OpenAI timeouts silently
        // mark the job successful and the translation post never updates.
        Functions\when('cdcf_process_translation')->justReturn(
            new WP_Error('openai_error', 'upstream 500')
        );
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);

        $job = new CDCF_Translation_Job([
            'post_id'     => 200,
            'source_id'   => 100,
            'target_lang' => 'it',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cdcf_process_translation failed for post 200');

        $job->execute();
    }

    public function test_returns_success_envelope_when_translation_completes(): void
    {
        Functions\when('cdcf_process_translation')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);

        $job = new CDCF_Translation_Job([
            'post_id'     => 200,
            'source_id'   => 100,
            'target_lang' => 'it',
        ]);

        $result = $job->execute();

        // Abstract_Base_Job::success() wraps the supplied data:
        //   ['success' => true, 'data' => [...]]
        $this->assertSame(
            [
                'success' => true,
                'data'    => ['post_id' => 200, 'target_lang' => 'it'],
            ],
            $result
        );
    }

    public function test_passes_payload_values_into_cdcf_process_translation(): void
    {
        $calls = null;
        Functions\when('cdcf_process_translation')->alias(
            function (int $post_id, int $source_id, string $target_lang) use (&$calls): bool {
                $calls = [$post_id, $source_id, $target_lang];
                return true;
            }
        );
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);

        $job = new CDCF_Translation_Job([
            'post_id'     => 200,
            'source_id'   => 100,
            'target_lang' => 'it',
        ]);
        $job->execute();

        $this->assertSame([200, 100, 'it'], $calls);
    }

    /**
     * redis-queue re-enqueues a retry as a new job whose own attempt
     * count starts again at zero; the payload has to carry the count.
     */
    public function test_the_attempt_count_carries_over_into_the_retried_job(): void
    {
        $payload = ['post_id' => 200, 'source_id' => 100, 'target_lang' => 'it'];
        $error   = new \RuntimeException('OpenAI timed out');

        $first = new CDCF_Translation_Job($payload);
        $this->assertTrue($first->should_retry($error, 1));

        // The queue stores get_payload() for the retry and rebuilds the job from it.
        $retry = new CDCF_Translation_Job($first->get_payload());
        $this->assertFalse($retry->should_retry($error, 1));
    }

    public function test_the_retry_delay_follows_the_carried_attempt_count(): void
    {
        $job = new CDCF_Translation_Job(['post_id' => 200, '_cdcf_attempt' => 1]);
        $job->should_retry(new \RuntimeException('again'), 1);

        $this->assertSame(120, $job->get_retry_delay(1));
    }

    public function test_a_first_failure_retries_after_the_first_backoff(): void
    {
        $job = new CDCF_Translation_Job(['post_id' => 200]);
        $job->should_retry(new \RuntimeException('once'), 1);

        $this->assertSame(30, $job->get_retry_delay(1));
    }
}
