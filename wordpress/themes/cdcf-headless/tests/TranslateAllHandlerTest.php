<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the atomic /cdcf/v1/translate-all path
 * (includes/handlers/translate-all.php).
 *
 * The key invariant under test: pll_save_post_translations() is called
 * EXACTLY ONCE per cdcf_enqueue_all_translations() call, with the FULL
 * {source_lang→source_id, …, target_lang→post_id} map populated. This is
 * the structural fix for the read-modify-write race that orphaned 2-3 of
 * 5 translations on the prior per-language fan-out path (media 1385, 1409).
 */
final class TranslateAllHandlerTest extends TestCase
{
    /** @var array<int, array<string,int>> */
    private array $saveCalls = [];

    /** @var array<string, mixed> */
    private array $metaStore = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->saveCalls = [];
        $this->metaStore = [];

        // Capture every pll_save_post_translations call so we can assert the
        // single-call invariant and inspect the payload it was given.
        Functions\when('pll_save_post_translations')->alias(
            function (array $translations): bool {
                $this->saveCalls[] = $translations;
                return true;
            }
        );

        // No-op stubs for everything the helper touches but the test doesn't
        // need to assert against directly.
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('pll_languages_list')->justReturn(['en', 'it', 'es', 'fr', 'pt', 'de']);
        Functions\when('cdcf_enqueue_translation')->justReturn('redis');
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('spawn_cron')->justReturn(null);
        Functions\when('cdcf_translation_status_set_enqueued')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value): bool {
                $this->metaStore[(string) $post_id][$key] = $value;
                return true;
            }
        );
        Functions\when('delete_post_meta')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    private function makeSource(array $overrides = []): stdClass
    {
        $source = new stdClass();
        $source->ID          = 1409;
        $source->post_title  = 'Stephanie Quesnelle';
        $source->post_type   = 'attachment';
        $source->post_status = 'inherit';
        $source->post_author = 7;
        $source->post_parent = 0;
        $source->post_mime_type = 'image/jpeg';
        foreach ($overrides as $k => $v) {
            $source->$k = $v;
        }
        return $source;
    }

    // ─── The core invariant ──────────────────────────────────────────

    public function test_pll_save_post_translations_is_called_exactly_once_with_full_map(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        // No prior translations for any lang — every call creates a new draft.
        Functions\when('pll_get_post')->justReturn(0);

        // Sequential insert IDs so we can verify the map.
        $nextId = 1410;
        Functions\when('wp_insert_post')->alias(function () use (&$nextId): int {
            return $nextId++;
        });
        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertIsArray($result);
        // The single-call structural fix:
        $this->assertCount(
            1,
            $this->saveCalls,
            'pll_save_post_translations must be called EXACTLY once — this is the structural fix for the orphan-translations race.'
        );
        // The full map:
        $this->assertSame(
            ['en' => 1409, 'it' => 1410, 'es' => 1411, 'fr' => 1412, 'pt' => 1413, 'de' => 1414],
            $this->saveCalls[0]
        );
        // And the return payload reflects the queued targets:
        $this->assertSame(['it', 'es', 'fr', 'pt', 'de'], array_keys($result['post_ids']));
        $this->assertSame(['it', 'es', 'fr', 'pt', 'de'], $result['queued']);
    }

    public function test_preserves_prior_links_when_some_languages_already_exist(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        // Source already has IT linked from an earlier session — that
        // link must survive (not get overwritten with a fresh draft).
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1409, 'it' => 9999]);
        Functions\when('pll_get_post')->alias(
            static fn(int $source_id, string $lang): int => $lang === 'it' ? 9999 : 0
        );

        $nextId = 1500;
        Functions\when('wp_insert_post')->alias(function () use (&$nextId): int {
            return $nextId++;
        });
        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertCount(1, $this->saveCalls);
        $saved = $this->saveCalls[0];
        // IT keeps its pre-existing post id; the other four get fresh drafts.
        $this->assertSame(9999, $saved['it']);
        $this->assertSame(1409, $saved['en']);
        $this->assertContains($saved['es'], [1500, 1501, 1502, 1503]);
        $this->assertSame(9999, $result['post_ids']['it']);
    }

    // ─── Error paths ─────────────────────────────────────────────────

    public function test_missing_source_id_returns_400(): void
    {
        $this->allowAllFunctionsToExist();
        $result = cdcf_enqueue_all_translations(0);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_source', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertSame([], $this->saveCalls, 'Must not touch the Polylang group when input is invalid.');
    }

    public function test_unknown_source_post_returns_404(): void
    {
        Functions\when('get_post')->justReturn(null);
        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(99999);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
        $this->assertSame(404, $result->get_error_data()['status']);
        $this->assertSame([], $this->saveCalls);
    }

    public function test_polylang_missing_returns_500_without_touching_anything(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => !in_array($name, ['pll_set_post_language', 'pll_languages_list'], true)
        );

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('polylang_missing', $result->get_error_code());
        $this->assertSame([], $this->saveCalls);
    }

    public function test_save_failure_rolls_back_newly_created_posts(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post')->justReturn(0);

        $nextId = 2000;
        Functions\when('wp_insert_post')->alias(function () use (&$nextId): int {
            return $nextId++;
        });

        // Capture every wp_delete_post call so we can assert the rollback.
        $deleted = [];
        Functions\when('wp_delete_post')->alias(
            function (int $id, bool $force = false) use (&$deleted): bool {
                $deleted[] = [$id, $force];
                return true;
            }
        );

        // Override the save stub to fail.
        Functions\when('pll_save_post_translations')->alias(
            function (array $translations): bool {
                $this->saveCalls[] = $translations;
                return false;
            }
        );

        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('link_failed', $result->get_error_code());
        // Five drafts created (2000..2004), all five must be force-deleted.
        $this->assertCount(5, $deleted);
        foreach ($deleted as [$id, $force]) {
            $this->assertGreaterThanOrEqual(2000, $id);
            $this->assertLessThanOrEqual(2004, $id);
            $this->assertTrue($force, 'Rollback must force-delete (skip trash).');
        }
    }

    public function test_single_language_create_failure_does_not_block_the_others(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post')->justReturn(0);

        // Make the FR insert fail; others succeed.
        $calls = 0;
        Functions\when('wp_insert_post')->alias(function () use (&$calls) {
            $calls++;
            // 1=it, 2=es, 3=fr (fails), 4=pt, 5=de — matches pll_languages_list order
            if ($calls === 3) {
                return new WP_Error('insert_failed', 'simulated');
            }
            return 3000 + $calls;
        });

        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertIsArray($result);
        $this->assertCount(1, $this->saveCalls, 'Still one atomic save — the survivors are linked.');
        // FR absent from the saved group + errors array carries the reason.
        $this->assertArrayNotHasKey('fr', $this->saveCalls[0]);
        $this->assertNotEmpty($result['errors']);
        $this->assertMatchesRegularExpression('/\[fr\]/', $result['errors'][0]);
        // The four survivors are still queued.
        $this->assertSame(['it', 'es', 'pt', 'de'], $result['queued']);
    }
}
