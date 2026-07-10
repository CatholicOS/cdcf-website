<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/translate handler.
 *
 * The handler is an enqueue endpoint: it creates / resolves the
 * target translation post in Polylang and hands off to either the
 * cdcf-redis-translations queue worker or WP Cron. It does NOT call
 * OpenAI directly — that work happens later in the queue worker via
 * cdcf_openai_translate().
 *
 * Brain Monkey ordering: every stub Brain Monkey needs to eval-declare
 * must be set up BEFORE function_exists is wholesale overridden — its
 * FunctionStub constructor short-circuits when function_exists() says
 * the target already exists, leaving the symbol undefined at call
 * time. Hence stubCommonFunctions() + allowAllFunctionsToExist().
 */
final class TranslateHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // cdcf_enqueue_post_translation now stamps _cdcf_translation_status
        // = 'enqueued' on the target post (cleared via delete_post_meta
        // for prior completed_at/error). Stub once here so each individual
        // happy-/sad-path test doesn't have to know about that side
        // effect; tests that care can still expect()-override.
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function stubCommonFunctions(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('cdcf_enqueue_translation')->justReturn('redis');
        // The reparent backfill sweep only runs for hierarchical types; these
        // tests exercise flat posts, so it must no-op.
        Functions\when('is_post_type_hierarchical')->justReturn(false);
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    private function makeRequest(array $params = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'source_id'   => 100,
            'target_lang' => 'it',
            'post_id'     => 0,
        ];
        foreach (array_merge($defaults, $params) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    private function makeSourcePost(array $overrides = []): object
    {
        return (object) array_merge([
            'ID'             => 100,
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'post_title'     => 'Original Post',
            'post_parent'    => 0,
            'post_mime_type' => '',
            'post_author'    => 7,
        ], $overrides);
    }

    // ─── Guard clauses ────────────────────────────────────────────

    public function test_returns_400_when_source_id_missing(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);

        $response = cdcf_rest_translate($this->makeRequest(['source_id' => 0]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('missing_params', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_target_lang_missing(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);

        $response = cdcf_rest_translate($this->makeRequest(['target_lang' => '']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('missing_params', $response->get_error_code());
    }

    public function test_returns_500_when_polylang_inactive(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_set_post_language'
        );

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('polylang_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    // ─── Direct post_id path (skip resolution) ────────────────────

    public function test_with_provided_post_id_validates_and_enqueues(): void
    {
        $this->stubCommonFunctions();
        // Provided post_id is validated (exists + is the source's translation
        // in this language) but no new post is created.
        Functions\when('get_post')->justReturn((object) ['ID' => 555]);
        Functions\when('pll_get_post')->justReturn(555); // canonical translation == provided id
        Functions\expect('wp_insert_post')->never();

        $enqueueCalls = [];
        Functions\when('cdcf_enqueue_translation')->alias(
            function (int $post_id, int $source_id, string $target_lang) use (&$enqueueCalls): string {
                $enqueueCalls[] = [$post_id, $source_id, $target_lang];
                return 'redis';
            }
        );
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest(['post_id' => 555]));

        $this->assertSame([[555, 100, 'it']], $enqueueCalls);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(202, $response->get_status());
        $this->assertSame(555, $response->get_data()['post_id']);
        $this->assertSame('redis', $response->get_data()['queue']);
        $this->assertSame('Translation queued.', $response->get_data()['message']);
    }

    public function test_returns_invalid_post_when_provided_post_id_missing(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn(null); // post_id refers to nothing
        Functions\expect('cdcf_enqueue_translation')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest(['post_id' => 555]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_post', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_returns_invalid_post_when_provided_post_id_is_not_the_translation(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn((object) ['ID' => 555]);
        // The source's actual translation for this language is a different post.
        Functions\when('pll_get_post')->justReturn(999);
        Functions\expect('cdcf_enqueue_translation')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest(['post_id' => 555]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_post', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    // ─── Source-post resolution path ──────────────────────────────

    public function test_returns_404_when_source_post_not_found(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn(null);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('not_found', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_reuses_existing_translation_when_pll_get_post_returns_id(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        // Polylang already has a translation at post 700.
        Functions\when('pll_get_post')->justReturn(700);
        Functions\expect('wp_insert_post')->never();

        $enqueueCalls = [];
        Functions\when('cdcf_enqueue_translation')->alias(
            function (int $post_id, int $source_id, string $target_lang) use (&$enqueueCalls): string {
                $enqueueCalls[] = [$post_id, $source_id, $target_lang];
                return 'redis';
            }
        );
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertSame([[700, 100, 'it']], $enqueueCalls);
        $this->assertSame(700, $response->get_data()['post_id']);
    }

    public function test_creates_new_translation_post_and_links_languages(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost([
            'post_title' => 'Original Post',
        ]));
        Functions\when('pll_get_post')->justReturn(0);

        $inserted = null;
        Functions\when('wp_insert_post')->alias(function (array $args) use (&$inserted): int {
            $inserted = $args;
            return 800;
        });

        $savedTranslations = null;
        Functions\when('pll_save_post_translations')->alias(
            function (array $map) use (&$savedTranslations): bool {
                $savedTranslations = $map;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertSame(
            [
                'post_type'   => 'post',
                'post_status' => 'draft',
                'post_title'  => 'Original Post',
                'post_author' => 7, // inherited from the source, not the caller
            ],
            $inserted
        );
        $this->assertSame(['en' => 100, 'it' => 800], $savedTranslations);
        $this->assertSame(800, $response->get_data()['post_id']);
    }

    public function test_returns_500_when_wp_insert_post_returns_zero(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(0);
        Functions\expect('pll_set_post_language')->never();
        Functions\expect('cdcf_enqueue_translation')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_wp_insert_post_returns_wp_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(new WP_Error('db_insert', 'DB down'));
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
    }

    // ─── Parent propagation ───────────────────────────────────────

    public function test_propagates_post_parent_via_parent_translation(): void
    {
        $this->stubCommonFunctions();
        $source = $this->makeSourcePost(['post_parent' => 50]);
        Functions\when('get_post')->justReturn($source);
        // pll_get_post is called twice — once for the existing-translation
        // check (returns 0) and once for the parent (returns 250).
        $calls = 0;
        Functions\when('pll_get_post')->alias(function () use (&$calls): int {
            $calls++;
            return $calls === 1 ? 0 : 250;
        });

        $inserted = null;
        Functions\when('wp_insert_post')->alias(function (array $args) use (&$inserted): int {
            $inserted = $args;
            return 800;
        });
        $this->allowAllFunctionsToExist();

        cdcf_rest_translate($this->makeRequest());

        $this->assertSame(250, $inserted['post_parent']);
    }

    public function test_omits_post_parent_when_translation_not_found(): void
    {
        $this->stubCommonFunctions();
        $source = $this->makeSourcePost(['post_parent' => 50]);
        Functions\when('get_post')->justReturn($source);
        // Both lookups return 0 — no existing translation, no parent translation.
        Functions\when('pll_get_post')->justReturn(0);

        $inserted = null;
        Functions\when('wp_insert_post')->alias(function (array $args) use (&$inserted): int {
            $inserted = $args;
            return 800;
        });
        $this->allowAllFunctionsToExist();

        cdcf_rest_translate($this->makeRequest());

        $this->assertArrayNotHasKey('post_parent', $inserted);
    }

    // ─── Group linking: lock + failure cleanup ───────────────────

    public function test_links_new_translation_under_a_source_keyed_lock(): void
    {
        // $wpdb present → linking is serialized by GET_LOCK / RELEASE_LOCK
        // keyed on the source group (prevents the concurrent-"Translate All"
        // lost-update). Sibling concurrency is what this guards.
        $wpdb = new class {
            public array $getVar = [];
            public array $query  = [];
            public function prepare(string $q, ...$args): string {
                foreach ($args as $a) {
                    $q = preg_replace('/%[sd]/', (string) $a, $q, 1);
                }
                return $q;
            }
            public function get_var(string $q): int { $this->getVar[] = $q; return 1; }
            public function query(string $q): int { $this->query[] = $q; return 1; }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertSame(800, $response->get_data()['post_id']);
        $this->assertCount(1, $wpdb->getVar);
        $this->assertStringContainsString('GET_LOCK', $wpdb->getVar[0]);
        $this->assertStringContainsString('cdcf_pll_link_100', $wpdb->getVar[0]);
        $this->assertCount(1, $wpdb->query);
        $this->assertStringContainsString('RELEASE_LOCK', $wpdb->query[0]);

        unset($GLOBALS['wpdb']);
    }

    public function test_returns_500_and_deletes_orphan_when_link_fails(): void
    {
        // pll_save_post_translations reporting false must abort and delete the
        // just-created post rather than leave it orphaned (not in any group).
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('pll_save_post_translations')->justReturn(false);
        $deleted = null;
        Functions\when('wp_delete_post')->alias(function (int $id, bool $force) use (&$deleted): object {
            $deleted = [$id, $force];
            return (object) ['ID' => $id];
        });
        Functions\expect('cdcf_enqueue_translation')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('link_failed', $response->get_error_code());
        $this->assertSame([800, true], $deleted);
    }

    // ─── Attachment-type handling ─────────────────────────────────

    public function test_attachment_copies_post_mime_type_and_uses_inherit_status(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/png',
        ]));
        Functions\when('pll_get_post')->justReturn(0);

        $inserted = null;
        Functions\when('wp_insert_post')->alias(function (array $args) use (&$inserted): int {
            $inserted = $args;
            return 800;
        });
        // Attachment meta lookups return empty so the conditional copy
        // branches are exercised. Record every update_post_meta call so we
        // can assert no _wp_attached_file / _wp_attachment_metadata write
        // happened. (Bare ->never() no longer applies — the post-enqueue
        // status meta legitimately writes _cdcf_translation_status here.)
        Functions\when('get_post_meta')->justReturn('');
        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value) use (&$metaWrites): bool {
                $metaWrites[] = [$post_id, $key, $value];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_rest_translate($this->makeRequest());

        $this->assertSame('inherit', $inserted['post_status']);
        $this->assertSame('image/png', $inserted['post_mime_type']);
        $attachmentMetaKeys = array_filter(
            $metaWrites,
            static fn(array $w): bool => in_array($w[1], ['_wp_attached_file', '_wp_attachment_metadata'], true)
        );
        $this->assertSame([], $attachmentMetaKeys);
    }

    public function test_attachment_copies_wp_attached_file_and_metadata(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/png',
        ]));
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        // Both meta lookups return non-empty values, exercising the
        // update_post_meta side effects.
        Functions\when('get_post_meta')->alias(
            static fn(int $post_id, string $key) => match ($key) {
                '_wp_attached_file'      => '2026/05/file.png',
                '_wp_attachment_metadata' => ['width' => 800, 'height' => 600],
                default => '',
            }
        );

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value) use (&$metaWrites): bool {
                $metaWrites[] = [$post_id, $key, $value];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertSame(
            [
                [800, '_wp_attached_file',      '2026/05/file.png'],
                [800, '_wp_attachment_metadata', ['width' => 800, 'height' => 600]],
                // Status meta the meta-box UI polls to flip "Queued" →
                // "Done"/"Failed" — written by cdcf_translation_status_set_enqueued().
                [800, '_cdcf_translation_status', 'enqueued'],
            ],
            $metaWrites
        );
        // Happy path → errors[] stays empty (#109).
        $this->assertSame([], $response->get_data()['errors']);
    }

    public function test_records_error_when_attached_file_meta_write_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/png',
        ]));
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('get_post_meta')->alias(
            static fn(int $post_id, string $key) => match ($key) {
                '_wp_attached_file'      => '2026/05/file.png',
                '_wp_attachment_metadata' => '',  // empty → skip the second write
                default => '',
            }
        );
        // _wp_attached_file write fails — error recorded but translation
        // post is still created and enqueued.
        Functions\when('update_post_meta')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertContains(
            'Failed to copy _wp_attached_file to translation post.',
            $response->get_data()['errors']
        );
        // post_id still set + 202 returned — translation IS queued, the
        // metadata copy is best-effort.
        $this->assertSame(800, $response->get_data()['post_id']);
        $this->assertSame(202, $response->get_status());
    }

    public function test_records_error_when_attachment_metadata_write_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/png',
        ]));
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('get_post_meta')->alias(
            static fn(int $post_id, string $key) => match ($key) {
                '_wp_attached_file'      => '',  // empty → skip the first write
                '_wp_attachment_metadata' => ['width' => 800, 'height' => 600],
                default => '',
            }
        );
        Functions\when('update_post_meta')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertContains(
            'Failed to copy _wp_attachment_metadata to translation post.',
            $response->get_data()['errors']
        );
    }

    // ─── Enqueue paths ────────────────────────────────────────────

    public function test_happy_path_returns_redis_queue_on_enqueue_success(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('cdcf_enqueue_translation')->justReturn('redis');
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertSame('redis', $response->get_data()['queue']);
    }

    /**
     * The creation path must sweep for orphaned child translations once the
     * new parent translation exists (cdcf_reparent_orphaned_child_translations
     * is unit-tested on its own — this pins the WIRING for hierarchical types).
     */
    public function test_creation_path_sweeps_for_orphaned_child_translations(): void
    {
        $this->stubCommonFunctions();
        Functions\when('is_post_type_hierarchical')->justReturn(true);
        Functions\when('get_post')->justReturn($this->makeSourcePost(['post_type' => 'page']));
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\expect('get_posts')->once()->andReturn([]); // the sweep ran
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertSame(202, $response->get_status());
    }

    /**
     * Runs in a separate process so cdcf_enqueue_translation has NOT
     * been eval-declared by an earlier test's stubCommonFunctions().
     * See TeamMemberHandlerTest for the same pattern + rationale.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_falls_back_to_wp_cron_when_enqueue_helper_missing(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('is_post_type_hierarchical')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->once()->andReturn(true);
        Functions\expect('spawn_cron')->once()->andReturnNull();
        // Deliberately do NOT stub cdcf_enqueue_translation — it must
        // remain undeclared so function_exists returns false and the
        // handler takes the wp-cron branch.

        $response = cdcf_rest_translate($this->makeRequest());

        $this->assertSame('wp-cron', $response->get_data()['queue']);
    }
}
