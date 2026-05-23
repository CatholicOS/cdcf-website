<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the submission-lifecycle helpers + transition_post_status
 * callbacks in includes/admin/submission-lifecycle.php:
 *
 *   - cdcf_get_source_post_id()                — Polylang resolution
 *   - cdcf_is_public_submission()              — submitter-meta probe
 *   - cdcf_enqueue_translations_for_submission()  — sibling-post creation + queue dispatch
 *   - cdcf_repend_submission_on_untrash()      — restored-from-trash → pending
 *   - cdcf_enqueue_translations_on_publish()   — publish → enqueue translations
 *
 * Brain Monkey ordering: every stub Brain Monkey needs to eval-declare
 * must be set up BEFORE function_exists is wholesale overridden — the
 * FunctionStub constructor short-circuits when function_exists() says
 * the target already exists, leaving the symbol undefined at call time.
 */
final class SubmissionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Suppress error_log noise from cdcf_enqueue_translations_for_submission's
        // skip-and-log branches. error_log is in patchwork.json's
        // redefinable-internals so this works.
        Patchwork\redefine('error_log', static fn(string $msg): bool => true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function stubCommonFunctions(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('cdcf_enqueue_translation')->justReturn('redis');
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    private function fakePost(array $overrides = []): stdClass
    {
        $post = new stdClass();
        $post->ID         = 100;
        $post->post_title = 'Submission';
        $post->post_type  = 'project';
        foreach ($overrides as $k => $v) {
            $post->$k = $v;
        }
        return $post;
    }

    // ─── cdcf_get_source_post_id ──────────────────────────────────────

    public function test_get_source_post_id_returns_input_when_polylang_inactive(): void
    {
        // function_exists('pll_get_post') returns false → no resolution.
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_get_post'
        );

        $this->assertSame(42, cdcf_get_source_post_id(42));
    }

    public function test_get_source_post_id_returns_pll_resolved_id(): void
    {
        Functions\when('pll_get_post')->justReturn(7);
        $this->allowAllFunctionsToExist();

        $this->assertSame(7, cdcf_get_source_post_id(42));
    }

    public function test_get_source_post_id_falls_back_to_input_when_pll_returns_zero(): void
    {
        // Polylang returns 0 when no English translation exists. The
        // handler must fall back to the given post ID so meta reads
        // still hit a real post.
        Functions\when('pll_get_post')->justReturn(0);
        $this->allowAllFunctionsToExist();

        $this->assertSame(42, cdcf_get_source_post_id(42));
    }

    // ─── cdcf_is_public_submission ────────────────────────────────────

    public function test_is_public_submission_true_when_submission_email_present(): void
    {
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_submission_submitter_email'
                ? 'user@example.com'
                : ''
        );
        $this->allowAllFunctionsToExist();

        $this->assertTrue(cdcf_is_public_submission(42));
    }

    public function test_is_public_submission_true_when_referral_email_present(): void
    {
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_referral_submitter_email'
                ? 'referrer@example.com'
                : ''
        );
        $this->allowAllFunctionsToExist();

        $this->assertTrue(cdcf_is_public_submission(42));
    }

    public function test_is_public_submission_false_when_no_submitter_meta(): void
    {
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('get_post_meta')->justReturn('');
        $this->allowAllFunctionsToExist();

        $this->assertFalse(cdcf_is_public_submission(42));
    }

    public function test_is_public_submission_resolves_translation_to_source_via_polylang(): void
    {
        // Called with translation ID 99; source is 42; meta on 42 says
        // public submission. The function should return true.
        Functions\when('pll_get_post')->alias(
            static fn(int $id, string $lang) => $id === 99 && $lang === 'en' ? 42 : 0
        );
        $metaCalls = [];
        Functions\when('get_post_meta')->alias(
            function (int $id, string $key) use (&$metaCalls): string {
                $metaCalls[] = $id;
                return $id === 42 && $key === '_submission_submitter_email'
                    ? 'user@example.com'
                    : '';
            }
        );
        $this->allowAllFunctionsToExist();

        $this->assertTrue(cdcf_is_public_submission(99));
        // Meta reads should target the resolved source (42), not the
        // translation (99).
        $this->assertContains(42, $metaCalls);
        $this->assertNotContains(99, $metaCalls);
    }

    // ─── cdcf_enqueue_translations_for_submission ─────────────────────

    public function test_enqueue_translations_bails_when_polylang_inactive(): void
    {
        $this->stubCommonFunctions();
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_set_post_language'
        );
        Functions\expect('wp_insert_post')->never();

        cdcf_enqueue_translations_for_submission(42, 'project');

        // Assertion-free expectation; pin a sanity check so PHPUnit
        // doesn't flag the test as risky.
        $this->assertTrue(true);
    }

    public function test_enqueue_translations_bails_when_source_post_not_found(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn(null);
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'project');

        $this->assertTrue(true);
    }

    public function test_enqueue_translations_creates_5_siblings_and_enqueues_each(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
            'ID'         => 42,
            'post_title' => 'New Project',
        ]));

        $counter = 199;
        $inserted = [];
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$counter, &$inserted): int {
                $counter++;
                $inserted[] = $args;
                return $counter;
            }
        );

        $enqueued = [];
        Functions\when('cdcf_enqueue_translation')->alias(
            function (int $trans_id, int $en_id, string $lang) use (&$enqueued): string {
                $enqueued[] = [$trans_id, $en_id, $lang];
                return 'redis';
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'project');

        // Five draft sibling posts (it/es/fr/pt/de) created, all of post_type=project.
        $this->assertCount(5, $inserted);
        foreach ($inserted as $args) {
            $this->assertSame('project', $args['post_type']);
            $this->assertSame('draft', $args['post_status']);
            $this->assertSame('New Project', $args['post_title']);
        }
        // Each enqueue call carries the matching language.
        $this->assertSame(
            [
                [200, 42, 'it'], [201, 42, 'es'], [202, 42, 'fr'],
                [203, 42, 'pt'], [204, 42, 'de'],
            ],
            $enqueued
        );
    }

    public function test_enqueue_translations_skips_languages_already_linked(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost(['ID' => 42]));
        // 'it' and 'es' translations already exist via Polylang.
        Functions\when('pll_get_post_translations')->justReturn([
            'it' => 50, 'es' => 51,
        ]);

        $inserted = [];
        Functions\when('wp_insert_post')->alias(
            function () use (&$inserted): int {
                $inserted[] = true;
                return 200 + count($inserted);
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'project');

        // Three new siblings (fr/pt/de) — it+es skipped via the
        // !empty($translations[$lang]) guard.
        $this->assertCount(3, $inserted);
    }

    /**
     * Runs in a separate process so cdcf_enqueue_translation is NOT
     * already eval-declared by an earlier test's stubCommonFunctions().
     * Brain Monkey caches its eval-declared functions in PHP's symbol
     * table for the rest of the process, which would make the
     * "function_exists returns false" branch unreachable here otherwise.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_enqueue_translations_falls_back_to_wp_cron_when_redis_helper_missing(): void
    {
        // Don't call stubCommonFunctions — it would eval-declare
        // cdcf_enqueue_translation, defeating the function_exists guard
        // we're trying to exercise. Stub each WP helper manually here.
        Patchwork\redefine('error_log', static fn(string $msg): bool => true);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('get_post')->justReturn($this->fakePost(['ID' => 42]));
        Functions\when('wp_insert_post')->justReturn(200);
        Functions\expect('wp_schedule_single_event')->times(5)->andReturn(true);
        Functions\expect('spawn_cron')->times(5)->andReturnNull();
        // Deliberately do NOT stub cdcf_enqueue_translation — it must
        // remain undeclared so function_exists returns false and the
        // handler takes the wp-cron branch.

        cdcf_enqueue_translations_for_submission(42, 'project');

        $this->assertTrue(true);
    }

    public function test_enqueue_translations_skips_failed_inserts_and_continues(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost(['ID' => 42]));
        // Third insert (fr) fails; others succeed.
        $calls = 0;
        $ids = [200, 201, 0, 202, 203];
        Functions\when('wp_insert_post')->alias(function () use (&$calls, $ids): int {
            return $ids[$calls++];
        });

        $enqueued = [];
        Functions\when('cdcf_enqueue_translation')->alias(
            function (int $trans_id, int $en_id, string $lang) use (&$enqueued): string {
                $enqueued[] = $lang;
                return 'redis';
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'project');

        // The failed language (fr) was skipped; the rest still enqueued.
        $this->assertSame(['it', 'es', 'pt', 'de'], $enqueued);
    }

    // ─── cdcf_repend_submission_on_untrash ────────────────────────────

    public function test_repend_ignores_status_transitions_that_arent_trash_to_draft(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_update_post')->never();
        $this->allowAllFunctionsToExist();

        cdcf_repend_submission_on_untrash('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_repend_ignores_non_project_or_local_group_post_types(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_update_post')->never();
        $this->allowAllFunctionsToExist();

        cdcf_repend_submission_on_untrash(
            'draft',
            'trash',
            $this->fakePost(['post_type' => 'page'])
        );

        $this->assertTrue(true);
    }

    public function test_repend_ignores_posts_without_submitter_meta(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('wp_update_post')->never();
        $this->allowAllFunctionsToExist();

        cdcf_repend_submission_on_untrash('draft', 'trash', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_repend_updates_status_to_pending_for_submission_post(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_submission_submitter_email'
                ? 'user@example.com'
                : ''
        );
        Functions\when('remove_action')->justReturn(true);

        $update = null;
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$update): int {
                $update = $args;
                return 100;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_repend_submission_on_untrash('draft', 'trash', $this->fakePost());

        $this->assertSame(['ID' => 100, 'post_status' => 'pending'], $update);
    }

    // ─── cdcf_enqueue_translations_on_publish ─────────────────────────

    public function test_publish_hook_ignores_same_status_transitions(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('cdcf_enqueue_translations_for_submission')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_on_publish('publish', 'publish', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_publish_hook_ignores_non_publish_transitions(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('cdcf_enqueue_translations_for_submission')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_on_publish('draft', 'pending', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_publish_hook_ignores_unsupported_post_types(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('cdcf_enqueue_translations_for_submission')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_on_publish(
            'publish',
            'pending',
            $this->fakePost(['post_type' => 'page'])
        );

        $this->assertTrue(true);
    }

    public function test_publish_hook_skips_translation_siblings(): void
    {
        // When the worker promotes a translation sibling to publish, this
        // hook fires again. cdcf_get_source_post_id resolves to a different
        // ID (the EN source), so the equality check trips and we bail.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42); // post 100 → EN source 42
        Functions\expect('cdcf_enqueue_translations_for_submission')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_publish_hook_skips_non_public_submission_posts(): void
    {
        // Source post but no submitter meta — this is a regular admin-created post.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0); // no translation → source resolves to self
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('cdcf_enqueue_translations_for_submission')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_publish_hook_enqueues_translations_for_public_submission(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0); // self-source
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_submission_submitter_email'
                ? 'user@example.com'
                : ''
        );

        $enqueueCall = null;
        Functions\when('cdcf_enqueue_translations_for_submission')->alias(
            function (int $en_id, string $type) use (&$enqueueCall): void {
                $enqueueCall = [$en_id, $type];
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_on_publish('publish', 'draft', $this->fakePost());

        $this->assertSame([100, 'project'], $enqueueCall);
    }
}
