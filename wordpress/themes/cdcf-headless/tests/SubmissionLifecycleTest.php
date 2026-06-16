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
        // Phase 0 (PR #208) calls get_post_thumbnail_id on the source;
        // tests that don't care about featured-image translation get a
        // default of 0 (no thumbnail = Phase 0 is a no-op).
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        // Source-language detection (PR following #227): the function
        // resolves source_lang via pll_get_post_language and the target
        // set via pll_languages_list rather than hardcoding 'en'. Default
        // both to the EN-source shape so existing EN-source tests stay
        // green; tests for non-EN sources override these.
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_languages_list')->justReturn(['en', 'it', 'es', 'fr', 'pt', 'de']);
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
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_languages_list')->justReturn(['en', 'it', 'es', 'fr', 'pt', 'de']);
        Functions\when('get_post')->justReturn($this->fakePost(['ID' => 42]));
        Functions\when('wp_insert_post')->justReturn(200);
        // Phase 0 (PR #208): no thumbnail on the source → skip attachment
        // translation. Not exercising that path in this wp-cron-fallback test.
        Functions\when('get_post_thumbnail_id')->justReturn(0);
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

    public function test_enqueue_translations_calls_pll_save_exactly_once_with_full_map(): void
    {
        // Regression guard for the lost-update race observed on
        // production 2026-06-08 (Interior Castle App publish): the old
        // shape called pll_save_post_translations 5x with progressively
        // larger maps, racing against Polylang's post-update hooks that
        // fire when the worker auto-publishes a sibling mid-loop. The
        // atomic shape saves exactly once with the complete map.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
            'ID'         => 42,
            'post_title' => 'New Project',
        ]));

        $counter = 199;
        Functions\when('wp_insert_post')->alias(
            function () use (&$counter): int {
                $counter++;
                return $counter;
            }
        );

        $saves = [];
        Functions\when('pll_save_post_translations')->alias(
            function (array $map) use (&$saves): bool {
                $saves[] = $map;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'project');

        $this->assertCount(1, $saves, 'pll_save_post_translations must be called exactly once');
        $this->assertSame(
            ['en' => 42, 'it' => 200, 'es' => 201, 'fr' => 202, 'pt' => 203, 'de' => 204],
            $saves[0],
            'the single save must carry the complete 6-language map'
        );
    }

    public function test_enqueue_translations_uses_dynamic_source_language_for_non_english_source(): void
    {
        // Regression guard for the hardcoded-EN bug observed on production
        // 2026-06-16: community_project 1534 was submitted in Spanish; the
        // publish hook created siblings for IT/FR/PT/DE only (NOT EN — the
        // user's expected target) and the Polylang group came out empty
        // because pll_save_post_translations was handed {en: 1534, ...} —
        // the source post was mis-keyed as the EN translation, so Polylang
        // rejected the malformed map.
        //
        // The function must derive source_lang from pll_get_post_language
        // and target_langs from pll_languages_list, treating any of the 6
        // languages as a possible submission source.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post_language')->justReturn('es');
        Functions\when('get_post')->justReturn($this->fakePost([
            'ID'         => 42,
            'post_title' => 'Enciclopedia Católica',
            'post_type'  => 'community_project',
        ]));

        $counter = 199;
        Functions\when('wp_insert_post')->alias(
            function () use (&$counter): int {
                $counter++;
                return $counter;
            }
        );

        $saves = [];
        Functions\when('pll_save_post_translations')->alias(
            function (array $map) use (&$saves): bool {
                $saves[] = $map;
                return true;
            }
        );

        $enqueued = [];
        Functions\when('cdcf_enqueue_translation')->alias(
            function (int $post_id, int $source_id, string $lang) use (&$enqueued): string {
                $enqueued[$lang] = $post_id;
                return 'redis';
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'community_project');

        $this->assertCount(1, $saves, 'pll_save_post_translations must be called exactly once');
        $this->assertSame(
            ['es' => 42, 'en' => 200, 'it' => 201, 'fr' => 202, 'pt' => 203, 'de' => 204],
            $saves[0],
            'the source post must be keyed under its actual language (es), and the 5 freshly-created siblings must cover the other 5 languages — INCLUDING en, which the old hardcoded ["it","es","fr","pt","de"] target list silently dropped.'
        );
        $this->assertSame(
            ['en' => 200, 'it' => 201, 'fr' => 202, 'pt' => 203, 'de' => 204],
            $enqueued,
            'every non-source language must be enqueued for translation, and the source language (es) must NOT be re-enqueued against itself.'
        );
    }

    public function test_enqueue_translations_skips_save_and_enqueue_when_all_langs_already_linked(): void
    {
        // Pre-seed already covers every target lang — no new drafts to
        // create, so nothing new to atomically save and nothing to enqueue.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost(['ID' => 42]));
        Functions\when('pll_get_post_translations')->justReturn([
            'it' => 50, 'es' => 51, 'fr' => 52, 'pt' => 53, 'de' => 54,
        ]);
        Functions\expect('wp_insert_post')->never();
        Functions\expect('pll_save_post_translations')->never();
        Functions\expect('cdcf_enqueue_translation')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'project');

        $this->assertTrue(true);
    }

    public function test_enqueue_translations_rolls_back_drafts_when_atomic_save_fails(): void
    {
        // pll_save_post_translations can return false (Polylang inactive
        // post-creation, term-save error, etc.) — drafts that were just
        // created must be force-deleted to avoid orphan rows in the DB,
        // and no translation jobs should be enqueued for posts that no
        // longer have a Polylang group.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
            'ID'         => 42,
            'post_title' => 'New Project',
        ]));

        $counter = 199;
        Functions\when('wp_insert_post')->alias(
            function () use (&$counter): int {
                $counter++;
                return $counter;
            }
        );
        Functions\when('pll_save_post_translations')->justReturn(false);

        $deleted = [];
        Functions\when('wp_delete_post')->alias(
            function (int $id, bool $force) use (&$deleted): array {
                $deleted[] = [$id, $force];
                return [];
            }
        );
        Functions\expect('cdcf_enqueue_translation')->never();
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_for_submission(42, 'project');

        // All 5 just-created drafts (it, es, fr, pt, de = ids 200-204)
        // force-deleted (second arg = true).
        $this->assertSame(
            [[200, true], [201, true], [202, true], [203, true], [204, true]],
            $deleted
        );
    }

    // ─── cdcf_format_lang_map ─────────────────────────────────────────

    public function test_format_lang_map_empty_array_returns_curly_braces(): void
    {
        $this->assertSame('{}', cdcf_format_lang_map([]));
    }

    public function test_format_lang_map_single_entry(): void
    {
        $this->assertSame('{en:42}', cdcf_format_lang_map(['en' => 42]));
    }

    public function test_format_lang_map_six_languages_preserves_input_order(): void
    {
        // The diagnostic log lines rely on stable key order (en first when
        // pre-seeding, target langs in target_langs order otherwise) — assert
        // PHP's insertion-order array iteration carries through.
        $this->assertSame(
            '{en:1508, it:1521, es:1522, fr:1523, pt:1524, de:1525}',
            cdcf_format_lang_map(['en' => 1508, 'it' => 1521, 'es' => 1522, 'fr' => 1523, 'pt' => 1524, 'de' => 1525])
        );
    }

    public function test_format_lang_map_coerces_string_ids_to_int(): void
    {
        // ACF and some Polylang return paths hand back string-numeric IDs;
        // the helper coerces them so the log line is uniform.
        $this->assertSame('{en:10, it:11}', cdcf_format_lang_map(['en' => '10', 'it' => '11']));
    }

    // ─── cdcf_ensure_attachment_translations + cdcf_create_attachment_translation ───

    /**
     * Build a fake attachment WP_Post-ish object with the fields the
     * production code reads (post_type, post_title, post_excerpt,
     * post_content, post_mime_type, guid). Mirrors the bootstrap.php
     * WP_Post stub by using stdClass.
     */
    private function fakeAttachment(array $overrides = []): stdClass
    {
        $a = new stdClass();
        $a->ID             = 1510;
        $a->post_type      = 'attachment';
        $a->post_title     = 'icon';
        $a->post_excerpt   = 'icon caption';
        $a->post_content   = 'icon description';
        $a->post_mime_type = 'image/webp';
        $a->guid           = 'https://cms.example.test/wp-content/uploads/2026/06/icon.webp';
        foreach ($overrides as $k => $v) {
            $a->$k = $v;
        }
        return $a;
    }

    public function test_ensure_attachment_translations_bails_when_polylang_inactive(): void
    {
        $this->stubCommonFunctions();
        Functions\when('function_exists')->alias(
            // pll_set_post_language gated out — bail at the entry guard.
            static fn(string $name): bool => $name !== 'pll_set_post_language'
        );
        Functions\expect('wp_insert_post')->never();

        $result = cdcf_ensure_attachment_translations(1510, ['it', 'es', 'fr', 'pt', 'de']);

        $this->assertSame([], $result);
    }

    public function test_ensure_attachment_translations_bails_when_source_isnt_attachment(): void
    {
        $this->stubCommonFunctions();
        // get_post returns a regular post, not an attachment.
        Functions\when('get_post')->justReturn($this->fakePost(['ID' => 42, 'post_type' => 'page']));
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $result = cdcf_ensure_attachment_translations(42, ['it', 'es', 'fr', 'pt', 'de']);

        $this->assertSame([], $result);
    }

    public function test_ensure_attachment_translations_skips_langs_already_linked(): void
    {
        // Source 1510 already has an IT and ES sibling — only fr/pt/de
        // should get newly created.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeAttachment());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 1510, 'it' => 1516, 'es' => 1517,
        ]);

        $counter = 1599;
        $inserts = 0;
        Functions\when('wp_insert_post')->alias(function () use (&$counter, &$inserts): int {
            $counter++;
            $inserts++;
            return $counter;
        });
        Functions\when('cdcf_openai_translate')->justReturn(['title' => 'icona']);
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('wp_get_attachment_metadata')->justReturn([]);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        $this->allowAllFunctionsToExist();

        $result = cdcf_ensure_attachment_translations(1510, ['it', 'es', 'fr', 'pt', 'de']);

        // 3 newly-created (fr/pt/de = 1600/1601/1602), 2 pre-seeded.
        $this->assertSame(3, $inserts);
        $this->assertSame([
            'en' => 1510, 'it' => 1516, 'es' => 1517,
            'fr' => 1600, 'pt' => 1601, 'de' => 1602,
        ], $result);
    }

    public function test_ensure_attachment_translations_skips_source_lang_in_target_list(): void
    {
        // If the source is EN and 'en' appears in target_langs (caller bug),
        // it must be silently skipped, not duplicated.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeAttachment());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1510]);

        $inserts = 0;
        Functions\when('wp_insert_post')->alias(function () use (&$inserts): int {
            $inserts++;
            return 1600 + $inserts;
        });
        Functions\when('cdcf_openai_translate')->justReturn(['title' => 'translated']);
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('wp_get_attachment_metadata')->justReturn([]);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        $this->allowAllFunctionsToExist();

        cdcf_ensure_attachment_translations(1510, ['en', 'it', 'es', 'fr', 'pt', 'de']);

        // 5 inserts (it/es/fr/pt/de) — en skipped via source-lang check.
        $this->assertSame(5, $inserts);
    }

    public function test_ensure_attachment_translations_atomic_save_then_returns_full_group(): void
    {
        // Happy path: 5 missing langs, OpenAI translates each, wp_insert_post
        // succeeds 5 times, ONE atomic pll_save_post_translations call,
        // returned group has 6 entries.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeAttachment());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1510]);

        $counter = 1599;
        Functions\when('wp_insert_post')->alias(function () use (&$counter): int {
            $counter++;
            return $counter;
        });
        Functions\when('cdcf_openai_translate')->justReturn(['title' => 'icona']);
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('wp_get_attachment_metadata')->justReturn(['width' => 96]);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);

        $saves = [];
        Functions\when('pll_save_post_translations')->alias(
            function (array $map) use (&$saves): bool {
                $saves[] = $map;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_ensure_attachment_translations(1510, ['it', 'es', 'fr', 'pt', 'de']);

        // Exactly ONE atomic save — regression guard for the lost-update race.
        $this->assertCount(1, $saves);
        $this->assertSame(
            ['en' => 1510, 'it' => 1600, 'es' => 1601, 'fr' => 1602, 'pt' => 1603, 'de' => 1604],
            $result
        );
    }

    public function test_ensure_attachment_translations_rolls_back_on_atomic_save_failure(): void
    {
        // pll_save_post_translations returns false → force-delete all
        // just-created siblings + return empty. Mirrors PR #203's
        // rollback shape so a failed call leaves no orphan attachments.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeAttachment());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1510]);

        $counter = 1599;
        Functions\when('wp_insert_post')->alias(function () use (&$counter): int {
            $counter++;
            return $counter;
        });
        Functions\when('cdcf_openai_translate')->justReturn(['title' => 'x']);
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('wp_get_attachment_metadata')->justReturn([]);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(false);

        $deleted = [];
        Functions\when('wp_delete_post')->alias(
            function (int $id, bool $force) use (&$deleted): array {
                $deleted[] = [$id, $force];
                return [];
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_ensure_attachment_translations(1510, ['it', 'es', 'fr', 'pt', 'de']);

        $this->assertSame([], $result);
        $this->assertSame(
            [[1600, true], [1601, true], [1602, true], [1603, true], [1604, true]],
            $deleted,
            'all 5 just-created attachments must be force-deleted on rollback'
        );
    }

    public function test_create_attachment_translation_uses_openai_translated_strings(): void
    {
        $this->stubCommonFunctions();
        $captured_insert = null;
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$captured_insert): int {
                $captured_insert = $args;
                return 1600;
            }
        );
        // Verify OpenAI got source strings (sans empty) and returns target translations.
        Functions\when('cdcf_openai_translate')->alias(
            static fn(array $strings, string $src, string $tgt) => array_combine(
                array_keys($strings),
                array_map(static fn($v) => $tgt . ':' . $v, $strings)
            )
        );
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_wp_attachment_image_alt' ? 'icon alt' : ''
        );
        Functions\when('wp_get_attachment_metadata')->justReturn([]);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);

        $alt_writes = [];
        Functions\when('update_post_meta')->alias(
            function (int $id, string $key, $value) use (&$alt_writes): bool {
                if ($key === '_wp_attachment_image_alt') {
                    $alt_writes[] = [$id, $value];
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_create_attachment_translation($this->fakeAttachment(), 'en', 'it');

        $this->assertSame(1600, $result);
        // OpenAI helper receives the LOCALE NAME (CDCF_LOCALE_NAMES['it']
        // = "Italian"), not the slug, so prefixed strings reflect that.
        $this->assertSame('Italian:icon',             $captured_insert['post_title']);
        $this->assertSame('Italian:icon caption',     $captured_insert['post_excerpt']);
        $this->assertSame('Italian:icon description', $captured_insert['post_content']);
        $this->assertSame('attachment',               $captured_insert['post_type']);
        $this->assertSame('image/webp',               $captured_insert['post_mime_type']);
        // Alt-text lives in meta, not the posts table.
        $this->assertSame([[1600, 'Italian:icon alt']], $alt_writes);
    }

    public function test_create_attachment_translation_falls_back_to_source_on_openai_error(): void
    {
        // OpenAI error → use source strings verbatim. A sibling with
        // source-language metadata is still better than no sibling at
        // all (the latter regresses to the EN-image fallback we're
        // fixing in the first place).
        $this->stubCommonFunctions();
        $captured_insert = null;
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$captured_insert): int {
                $captured_insert = $args;
                return 1600;
            }
        );
        Functions\when('cdcf_openai_translate')->justReturn(
            new WP_Error('openai_error', 'rate limit', ['status' => 429])
        );
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_attachment_metadata')->justReturn([]);
        Functions\when('wp_update_attachment_metadata')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        $this->allowAllFunctionsToExist();

        $result = cdcf_create_attachment_translation($this->fakeAttachment(), 'en', 'it');

        $this->assertSame(1600, $result);
        // Source strings verbatim — not "it:..."-prefixed.
        $this->assertSame('icon',             $captured_insert['post_title']);
        $this->assertSame('icon caption',     $captured_insert['post_excerpt']);
        $this->assertSame('icon description', $captured_insert['post_content']);
    }

    public function test_create_attachment_translation_returns_null_on_wp_insert_post_failure(): void
    {
        $this->stubCommonFunctions();
        Functions\when('wp_insert_post')->justReturn(
            new WP_Error('insert_failed', 'database error')
        );
        Functions\when('cdcf_openai_translate')->justReturn(['title' => 'icona']);
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('wp_update_attachment_metadata')->never();
        Functions\expect('update_post_meta')->never();
        $this->allowAllFunctionsToExist();

        $result = cdcf_create_attachment_translation($this->fakeAttachment(), 'en', 'it');

        $this->assertNull($result);
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

    public function test_publish_hook_defers_enqueue_to_shutdown_for_public_submission(): void
    {
        // The publish hook MUST defer cdcf_enqueue_translations_for_submission to
        // the `shutdown` action — running it synchronously inside
        // transition_post_status puts our pll_save_post_translations call inside
        // Polylang's own nested save_post chain for the source post + each
        // freshly-inserted draft, which silently drops the multi-post group save.
        //
        // Observed in production 2026-06-16: FamilyGraph submission 1381 was
        // published with EN/IT/ES/FR/PT/DE siblings created and worker-translated,
        // but the Polylang group came out empty on all six posts AND Phase 0's
        // attachment translation siblings were never created. Re-running the
        // identical pll_save_post_translations call from outside the save chain
        // (via /cdcf/v1/link-translations) persisted on the first attempt.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0); // self-source
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_submission_submitter_email'
                ? 'user@example.com'
                : ''
        );

        $shutdownCallback = null;
        Functions\when('add_action')->alias(
            function (string $hook, callable $cb) use (&$shutdownCallback): void {
                if ($hook === 'shutdown') {
                    $shutdownCallback = $cb;
                }
            }
        );

        $enqueueCall = null;
        Functions\when('cdcf_enqueue_translations_for_submission')->alias(
            function (int $en_id, string $type) use (&$enqueueCall): void {
                $enqueueCall = [$en_id, $type];
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_enqueue_translations_on_publish('publish', 'draft', $this->fakePost());

        // Synchronously: NOT called.
        $this->assertNull(
            $enqueueCall,
            'cdcf_enqueue_translations_for_submission must NOT be invoked synchronously inside transition_post_status — it must be deferred to the `shutdown` action so Polylang\'s save_post chain settles first.'
        );

        // A shutdown callback was registered.
        $this->assertNotNull(
            $shutdownCallback,
            'cdcf_enqueue_translations_on_publish must call add_action(\'shutdown\', $callback) to defer the enqueue out of the post-save chain.'
        );

        // Driving the deferred callback fires the enqueue with the source post's args.
        $shutdownCallback();
        $this->assertSame([100, 'project'], $enqueueCall);
    }

    // ─── cdcf_link_referral_on_publish ────────────────────────────────

    /**
     * Stage the happy-path mocks for cdcf_link_referral_on_publish.
     * Returns a stdClass with `->update` initialized to null; the
     * update_field stub fills it with [field, value, parent_id] when
     * called. An object is returned (not an array) so the closure's
     * mutation is visible to the caller — array returns would be
     * copy-on-write and the test would only see null.
     *
     * @param array<string, int> $page_translations  e.g. ['en' => 500, 'it' => 501, ...]
     * @param array<int>         $current_relationship  current IDs on the parent's field
     */
    private function stageLinkReferralHappyPath(
        string $post_lang,
        array $page_translations,
        array $current_relationship,
    ): stdClass {
        $captured = new stdClass();
        $captured->update = null;
        Functions\when('pll_get_post')->justReturn(0); // self-source for is_public_submission
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_referral_submitter_email'
                ? 'referrer@example.com'
                : ''
        );
        Functions\when('pll_get_post_language')->justReturn($post_lang);
        Functions\when('pll_get_post_translations')->justReturn($page_translations);
        $candidatePage = new stdClass();
        $candidatePage->ID = 500;
        Functions\when('get_pages')->justReturn([$candidatePage]);
        Functions\when('get_field')->justReturn($current_relationship);
        Functions\when('update_field')->alias(
            function (string $field, array $value, int $parent_id) use ($captured): bool {
                $captured->update = [$field, $value, $parent_id];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();
        return $captured;
    }

    public function test_link_referral_bails_on_same_status_transition(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('get_pages')->never();
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        cdcf_link_referral_on_publish(
            'publish',
            'publish',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertTrue(true);
    }

    public function test_link_referral_bails_on_non_publish_transition(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('get_pages')->never();
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        cdcf_link_referral_on_publish(
            'draft',
            'pending',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertTrue(true);
    }

    public function test_link_referral_bails_on_unsupported_post_types(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('get_pages')->never();
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        // 'project' and 'community_channel' aren't in the map.
        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'project'])
        );
        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_channel'])
        );

        $this->assertTrue(true);
    }

    public function test_link_referral_bails_on_admin_create_flow_without_submitter_meta(): void
    {
        // Admin-create flow: post has no submitter meta. The /local-group
        // create endpoint already linked the post inline; this hook must
        // not double-link.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('get_post_meta')->justReturn('');
        Functions\expect('get_pages')->never();
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'local_group'])
        );

        $this->assertTrue(true);
    }

    public function test_link_referral_bails_when_polylang_inactive(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_referral_submitter_email'
                ? 'referrer@example.com'
                : ''
        );
        // pll_get_post_language and pll_get_post_translations both undeclared.
        Functions\when('function_exists')->alias(
            static fn(string $name): bool =>
                $name !== 'pll_get_post_language' && $name !== 'pll_get_post_translations'
        );
        Functions\expect('get_pages')->never();
        Functions\expect('update_field')->never();

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertTrue(true);
    }

    public function test_link_referral_bails_when_post_has_no_language(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_referral_submitter_email'
                ? 'referrer@example.com'
                : ''
        );
        Functions\when('pll_get_post_language')->justReturn(false);
        Functions\expect('get_pages')->never();
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertTrue(true);
    }

    public function test_link_referral_bails_when_no_candidate_pages_found(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === '_referral_submitter_email'
                ? 'referrer@example.com'
                : ''
        );
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('get_pages')->justReturn([]);
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertTrue(true);
    }

    public function test_link_referral_bails_when_no_parent_page_in_post_language(): void
    {
        // post_lang='de' but the parent's translation map has no 'de' entry.
        $this->stubCommonFunctions();
        $captured = $this->stageLinkReferralHappyPath(
            post_lang: 'de',
            page_translations: ['en' => 500, 'it' => 501],
            current_relationship: [],
        );

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertNull($captured->update);
    }

    public function test_link_referral_is_idempotent_when_post_already_linked(): void
    {
        $this->stubCommonFunctions();
        // Post ID 100 is already in the EN parent's relationship — second
        // fire of the hook (e.g. on re-publish) must not append again.
        $captured = $this->stageLinkReferralHappyPath(
            post_lang: 'en',
            page_translations: ['en' => 500],
            current_relationship: [99, 100, 101],
        );

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertNull($captured->update);
    }

    public function test_link_referral_appends_community_project_to_projects_page(): void
    {
        $this->stubCommonFunctions();
        $captured = $this->stageLinkReferralHappyPath(
            post_lang: 'en',
            page_translations: ['en' => 500, 'it' => 501],
            current_relationship: [42],
        );

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertSame(['community_projects', [42, 100], 500], $captured->update);
    }

    public function test_link_referral_appends_local_group_to_community_page(): void
    {
        $this->stubCommonFunctions();
        // Post lang is 'it' → must link into the IT parent page (501),
        // not the EN one (500). Verifies the per-language routing.
        $captured = $this->stageLinkReferralHappyPath(
            post_lang: 'it',
            page_translations: ['en' => 500, 'it' => 501],
            current_relationship: [],
        );

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'local_group'])
        );

        $this->assertSame(['local_groups', [100], 501], $captured->update);
    }

    public function test_link_referral_treats_non_array_current_field_as_empty(): void
    {
        // get_field can return false/null when the field has never been
        // saved. The handler must treat that as an empty list, not crash.
        $this->stubCommonFunctions();
        $captured = $this->stageLinkReferralHappyPath(
            post_lang: 'en',
            page_translations: ['en' => 500],
            current_relationship: [],
        );
        Functions\when('get_field')->justReturn(false);

        cdcf_link_referral_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_project'])
        );

        $this->assertSame(['community_projects', [100], 500], $captured->update);
    }
}
