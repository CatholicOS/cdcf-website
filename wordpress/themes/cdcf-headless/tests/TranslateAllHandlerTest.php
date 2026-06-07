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

    // ─── cdcf_create_or_reuse_translation_draft direct branches ──────

    public function test_helper_reuses_existing_translation_without_inserting(): void
    {
        Functions\when('pll_get_post')->justReturn(7777);
        // Insert path must not run when a reuse is available — assert with
        // expect() so the test fails loudly if the function ever regresses.
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $source = $this->makeSource();
        $result = cdcf_create_or_reuse_translation_draft($source, 'it');

        $this->assertSame(['post_id' => 7777, 'reused' => true, 'errors' => []], $result);
    }

    public function test_helper_propagates_post_parent_via_parent_translation(): void
    {
        $source = $this->makeSource(['post_parent' => 100]);

        // Capture insert args so we can assert post_parent is the parent's
        // translation, not the source's parent id.
        $inserted = null;
        Functions\when('wp_insert_post')->alias(function (array $args) use (&$inserted): int {
            $inserted = $args;
            return 5000;
        });
        // pll_get_post: 0 for the target-lang lookup of the source (not yet
        // translated); 200 for the parent's it translation.
        Functions\when('pll_get_post')->alias(
            static fn(int $id, string $lang): int => ($id === 100 && $lang === 'it') ? 200 : 0
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_create_or_reuse_translation_draft($source, 'it');

        $this->assertSame(5000, $result['post_id']);
        $this->assertFalse($result['reused']);
        $this->assertSame(200, $inserted['post_parent']);
    }

    public function test_helper_uses_draft_status_and_no_attachment_plumbing_for_non_attachment(): void
    {
        $inserted = null;
        Functions\when('wp_insert_post')->alias(function (array $args) use (&$inserted): int {
            $inserted = $args;
            return 6000;
        });
        Functions\when('pll_get_post')->justReturn(0);
        // get_post_meta must not run for non-attachment — assert with expect.
        Functions\expect('get_post_meta')->never();
        $this->allowAllFunctionsToExist();

        $source = $this->makeSource([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_mime_type' => '',
        ]);
        $result = cdcf_create_or_reuse_translation_draft($source, 'it');

        $this->assertSame(6000, $result['post_id']);
        $this->assertSame('draft', $inserted['post_status']);
        $this->assertArrayNotHasKey('post_mime_type', $inserted);
    }

    public function test_helper_returns_wp_error_when_insert_fails(): void
    {
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(new WP_Error('boom', 'simulated'));
        $this->allowAllFunctionsToExist();

        $result = cdcf_create_or_reuse_translation_draft($this->makeSource(), 'it');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('insert_failed', $result->get_error_code());
        $this->assertSame(500, $result->get_error_data()['status']);
    }

    public function test_helper_records_attachment_plumbing_failures_but_keeps_post(): void
    {
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(8000);
        // Both attachment meta lookups return non-empty so the plumbing runs.
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key): mixed => match ($key) {
                '_wp_attached_file' => '2026/06/file.jpg',
                '_wp_attachment_metadata' => ['w' => 1, 'h' => 2],
                default => '',
            }
        );
        // Force BOTH writes to "fail" (return false) so both error branches
        // run. Override the array-store stub from setUp().
        Functions\when('update_post_meta')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $result = cdcf_create_or_reuse_translation_draft($this->makeSource(), 'it');

        $this->assertSame(8000, $result['post_id']);
        $this->assertFalse($result['reused']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('_wp_attached_file', $result['errors'][0]);
        $this->assertStringContainsString('_wp_attachment_metadata', $result['errors'][1]);
    }

    // ─── cdcf_enqueue_all_translations: remaining error paths ────────

    public function test_returns_no_source_lang_error_when_both_lookups_empty(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        // Both pll_get_post_language and pll_default_language fall through.
        Functions\when('pll_get_post_language')->justReturn('');
        Functions\when('pll_default_language')->justReturn('');
        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('no_source_lang', $result->get_error_code());
        $this->assertSame([], $this->saveCalls);
    }

    public function test_returns_no_targets_error_when_only_source_lang_configured(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        // Only English configured — no targets to translate to.
        Functions\when('pll_languages_list')->justReturn(['en']);
        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('no_targets', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertSame([], $this->saveCalls);
    }

    public function test_returns_all_creates_failed_when_every_language_insert_fails(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(new WP_Error('boom', 'simulated'));
        $this->allowAllFunctionsToExist();

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('all_creates_failed', $result->get_error_code());
        $this->assertSame([], $this->saveCalls, 'Never reaches the link step when no draft was created.');
    }

    public function test_falls_back_to_wp_cron_when_redis_enqueue_helper_missing(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post')->justReturn(0);
        $nextId = 9100;
        Functions\when('wp_insert_post')->alias(function () use (&$nextId): int {
            return $nextId++;
        });
        // Pretend the cdcf-redis-translations plugin isn't loaded — the
        // function should fall back to wp_schedule_single_event + spawn_cron.
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'cdcf_enqueue_translation'
        );
        $scheduled = [];
        Functions\when('wp_schedule_single_event')->alias(
            function (int $ts, string $hook, array $args) use (&$scheduled): bool {
                $scheduled[] = [$hook, $args];
                return true;
            }
        );

        $result = cdcf_enqueue_all_translations(1409);

        $this->assertIsArray($result);
        $this->assertSame('wp-cron', $result['queue']);
        $this->assertCount(5, $scheduled, 'One wp-cron event per target lang.');
        foreach ($scheduled as [$hook, $_]) {
            $this->assertSame('cdcf_async_translate', $hook);
        }
    }

    // ─── Admin-ajax wrapper ──────────────────────────────────────────

    public function test_ajax_wrapper_rejects_users_without_edit_posts(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_send_json_success')->alias(
            static function (mixed $data = null): never {
                throw new CdcfAjaxSuccess($data);
            }
        );
        Functions\when('wp_send_json_error')->alias(
            static function (mixed $data = null): never {
                throw new CdcfAjaxError($data);
            }
        );
        // Must bail before calling the core function.
        Functions\expect('cdcf_enqueue_all_translations')->never();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 1409];
        try {
            cdcf_ajax_ai_translate_all();
            $this->fail('Expected CdcfAjaxError.');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Insufficient permissions.', $e->data);
        }
    }

    public function test_ajax_wrapper_surfaces_wp_error_as_json_error(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post')->justReturn(null); // makes the core return not_found
        Functions\when('wp_send_json_success')->alias(
            static function (mixed $data = null): never {
                throw new CdcfAjaxSuccess($data);
            }
        );
        Functions\when('wp_send_json_error')->alias(
            static function (mixed $data = null): never {
                throw new CdcfAjaxError($data);
            }
        );
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 99999];
        try {
            cdcf_ajax_ai_translate_all();
            $this->fail('Expected CdcfAjaxError.');
        } catch (CdcfAjaxError $e) {
            $this->assertStringContainsString('not found', $e->data);
        }
    }

    public function test_ajax_wrapper_sends_success_payload_on_happy_path(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post')->justReturn(0);
        $nextId = 7100;
        Functions\when('wp_insert_post')->alias(function () use (&$nextId): int {
            return $nextId++;
        });
        Functions\when('wp_send_json_success')->alias(
            static function (mixed $data = null): never {
                throw new CdcfAjaxSuccess($data);
            }
        );
        Functions\when('wp_send_json_error')->alias(
            static function (mixed $data = null): never {
                throw new CdcfAjaxError($data);
            }
        );
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 1409];
        try {
            cdcf_ajax_ai_translate_all();
            $this->fail('Expected CdcfAjaxSuccess.');
        } catch (CdcfAjaxSuccess $e) {
            $this->assertSame('Translations queued.', $e->data['message']);
            $this->assertSame(['it', 'es', 'fr', 'pt', 'de'], array_keys($e->data['post_ids']));
        }
    }

    // ─── REST wrapper ────────────────────────────────────────────────

    public function test_rest_wrapper_returns_202_payload_on_happy_path(): void
    {
        Functions\when('get_post')->justReturn($this->makeSource());
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post')->justReturn(0);
        $nextId = 7200;
        Functions\when('wp_insert_post')->alias(function () use (&$nextId): int {
            return $nextId++;
        });
        $this->allowAllFunctionsToExist();

        $req = new WP_REST_Request();
        $req->set_param('source_id', 1409);

        $response = cdcf_rest_translate_all($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(202, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('Translations queued.', $data['message']);
        $this->assertSame(['it', 'es', 'fr', 'pt', 'de'], array_keys($data['post_ids']));
    }

    public function test_rest_wrapper_propagates_wp_error_from_core(): void
    {
        Functions\when('get_post')->justReturn(null);
        $this->allowAllFunctionsToExist();

        $req = new WP_REST_Request();
        $req->set_param('source_id', 99999);

        $result = cdcf_rest_translate_all($req);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
        $this->assertSame(404, $result->get_error_data()['status']);
    }
}
