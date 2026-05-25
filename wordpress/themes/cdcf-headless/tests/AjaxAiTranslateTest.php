<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the cdcf_ajax_ai_translate admin-ajax handler.
 *
 * The handler mirrors the /cdcf/v1/translate REST endpoint but uses
 * cookie + nonce auth and writes translations synchronously rather
 * than enqueuing. wp_send_json_success / wp_send_json_error normally
 * call wp_die() to terminate. To make them catchable in tests we stub
 * them to throw the typed CdcfAjaxSuccess / CdcfAjaxError exceptions
 * declared in tests/bootstrap.php; tests then catch the exception and
 * assert on the attached payload.
 *
 * Brain Monkey ordering: every stub Brain Monkey needs to eval-declare
 * must be set up BEFORE function_exists is wholesale overridden — the
 * FunctionStub constructor short-circuits when function_exists() says
 * the target already exists, leaving the symbol undefined at call time.
 */
final class AjaxAiTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        $_POST = [];
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * Stub the side-effect-free helpers the handler reaches for. Tests
     * override individual stubs to drive specific branches.
     *
     * Note: wp_send_json_success / wp_send_json_error are NOT stubbed
     * here — every test reaches an exit point eventually, and the
     * exception-throwing stubs are added by setExitToThrow().
     */
    private function stubCommonFunctions(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_field_objects')->justReturn([]);
        Functions\when('get_field')->justReturn('');
        Functions\when('update_field')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_update_post')->justReturn(true);
        Functions\when('get_post_status')->justReturn('draft');
        Functions\when('get_option')->alias(
            static fn(string $opt) => $opt === 'cdcf_openai_api_key' ? 'sk-test' : null
        );
    }

    /**
     * Convert wp_send_json_* termination into a catchable exception so
     * tests can assert on the payload the handler tried to return.
     */
    private function setExitToThrow(): void
    {
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
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    private function fakeSource(array $overrides = []): stdClass
    {
        $post = new stdClass();
        $post->ID             = 100;
        $post->post_title     = 'Sample Title';
        $post->post_content   = '<p>Sample content.</p>';
        $post->post_excerpt   = 'Sample excerpt.';
        $post->post_type      = 'post';
        $post->post_status    = 'publish';
        $post->post_mime_type = '';
        $post->post_author    = 7;
        foreach ($overrides as $k => $v) {
            $post->$k = $v;
        }
        return $post;
    }

    // ─── Guard clauses ────────────────────────────────────────────────

    public function test_returns_error_when_user_lacks_edit_posts(): void
    {
        $this->stubCommonFunctions();
        Functions\when('current_user_can')->justReturn(false);
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Insufficient permissions.', $e->data);
        }
    }

    public function test_returns_error_when_source_id_missing(): void
    {
        $this->stubCommonFunctions();
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Missing parameters.', $e->data);
        }
    }

    public function test_returns_error_when_target_lang_missing(): void
    {
        $this->stubCommonFunctions();
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Missing parameters.', $e->data);
        }
    }

    // ─── Auto-create branch (post_id=0) ───────────────────────────────

    public function test_returns_error_when_source_post_not_found_in_autocreate_branch(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn(null);
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Source post not found.', $e->data);
        }
    }

    public function test_returns_error_when_wp_insert_post_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource());
        Functions\when('wp_insert_post')->justReturn(0);
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Failed to create translation post.', $e->data);
        }
    }

    public function test_attachment_branch_uses_inherit_status_and_copies_mime(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/png',
            // Empty text fields + empty alt meta below means $strings
            // ends up empty and the handler takes the "media duplicated"
            // success short-circuit before reaching OpenAI.
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));

        $insertArgs = null;
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$insertArgs): int {
                $insertArgs = $args;
                return 800;
            }
        );
        // No translatable text on the attachment → handler takes the
        // "media duplicated" short-circuit success.
        Functions\when('get_post_meta')->justReturn('');
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess $e) {
            $this->assertSame('inherit', $insertArgs['post_status']);
            $this->assertSame('image/png', $insertArgs['post_mime_type']);
            // Translation inherits the source author, not the triggering user.
            $this->assertSame(7, $insertArgs['post_author']);
            $this->assertSame(800, $e->data['post_id']);
            $this->assertStringContainsString('Media duplicated', $e->data['message']);
        }
    }

    public function test_attachment_branch_copies_attached_file_and_metadata(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/png',
            // Empty translatable text → "media duplicated" success
            // after the file-pointer copy.
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\when('wp_insert_post')->justReturn(800);
        // Return non-empty for the two attachment-meta keys so the
        // update_post_meta side effects fire on the new post.
        Functions\when('get_post_meta')->alias(
            static fn(int $post_id, string $key) => match ($key) {
                '_wp_attached_file'       => '2026/05/file.png',
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
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        // Attachment has no translatable text but plumbing meta WAS
        // copied. Final response is the "media duplicated" success.
        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess $e) {
            $this->assertSame('2026/05/file.png', $metaWrites[0][2]);
            $this->assertSame('_wp_attached_file', $metaWrites[0][1]);
            $this->assertSame(800, $e->data['post_id']);
        }
    }

    public function test_autocreate_links_group_under_a_source_keyed_lock(): void
    {
        // A fake $wpdb that records GET_LOCK / RELEASE_LOCK and grants the
        // lock. Concurrent "Translate All" requests would otherwise
        // lost-update the shared Polylang group term.
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
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_type'    => 'attachment',
            'post_mime_type' => 'image/png',
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]); // empty source group
        $saved = null;
        Functions\when('pll_save_post_translations')->alias(
            function (array $t) use (&$saved): bool { $saved = $t; return true; }
        );
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess) {
            // Lock acquired then released, both keyed on the source group id.
            $this->assertCount(1, $wpdb->getVar);
            $this->assertStringContainsString('GET_LOCK', $wpdb->getVar[0]);
            $this->assertStringContainsString('cdcf_pll_link_100', $wpdb->getVar[0]);
            $this->assertCount(1, $wpdb->query);
            $this->assertStringContainsString('RELEASE_LOCK', $wpdb->query[0]);
            $this->assertStringContainsString('cdcf_pll_link_100', $wpdb->query[0]);
            // Group saved with source (en) + target (it) merged in.
            $this->assertSame(['en' => 100, 'it' => 800], $saved);
        }
    }

    public function test_autocreate_still_links_when_no_wpdb_available(): void
    {
        // Degraded path: no $wpdb (or DB lock unavailable) must NOT block the
        // linking — best-effort, so the translation still completes.
        unset($GLOBALS['wpdb']);

        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_type'    => 'attachment',
            'post_mime_type' => 'image/png',
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        $saved = null;
        Functions\when('pll_save_post_translations')->alias(
            function (array $t) use (&$saved): bool { $saved = $t; return true; }
        );
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess) {
            $this->assertSame(['en' => 100, 'it' => 800], $saved);
        }
    }

    public function test_autocreate_aborts_and_cleans_up_when_group_link_fails(): void
    {
        // pll_save_post_translations reporting false (real persistence
        // failure) must abort the flow and delete the just-created post,
        // rather than returning success for an orphaned translation.
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_type'    => 'attachment',
            'post_mime_type' => 'image/png',
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\when('wp_insert_post')->justReturn(800);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_save_post_translations')->justReturn(false); // link fails
        $deleted = null;
        Functions\when('wp_delete_post')->alias(
            function (int $id, bool $force) use (&$deleted): object {
                $deleted = [$id, $force];
                return (object) ['ID' => $id];
            }
        );
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Failed to link translation group.', $e->data);
            // The orphaned post is force-deleted.
            $this->assertSame([800, true], $deleted);
        }
    }

    // ─── Provided post_id path ────────────────────────────────────────

    public function test_skips_autocreate_when_post_id_provided(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource());
        Functions\expect('wp_insert_post')->never();
        Functions\when('cdcf_openai_translate')->justReturn([
            'post_title'   => 'Titolo',
            'post_content' => '<p>Contenuto.</p>',
            'post_excerpt' => 'Estratto.',
        ]);
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess $e) {
            $this->assertSame(555, $e->data['post_id']);
            $this->assertSame('Translation complete.', $e->data['message']);
        }
    }

    // ─── Translatable-content collection ──────────────────────────────

    public function test_returns_error_when_no_translatable_content_and_not_attachment(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\expect('cdcf_openai_translate')->never();
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('No translatable content found on the source post.', $e->data);
        }
    }

    // ─── OpenAI failure modes ─────────────────────────────────────────

    public function test_returns_error_when_api_key_not_configured(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post')->justReturn($this->fakeSource());
        Functions\expect('cdcf_openai_translate')->never();
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertStringContainsString('OpenAI API key not configured', $e->data);
        }
    }

    public function test_returns_error_when_openai_returns_wp_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource());
        Functions\when('cdcf_openai_translate')->justReturn(
            new WP_Error('openai_error', 'upstream 500')
        );
        Functions\expect('wp_update_post')->never();
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('upstream 500', $e->data);
        }
    }

    // ─── Happy path ───────────────────────────────────────────────────

    public function test_happy_path_writes_translated_title_content_and_excerpt(): void
    {
        $this->stubCommonFunctions();
        // Draft source so the auto-publish branch is skipped — we only
        // want to observe the translation-write call here.
        Functions\when('get_post')->justReturn($this->fakeSource(['post_status' => 'draft']));
        Functions\when('cdcf_openai_translate')->justReturn([
            'post_title'   => 'Titolo Tradotto',
            'post_content' => '<p>Contenuto tradotto.</p>',
            'post_excerpt' => 'Estratto tradotto.',
        ]);

        $updateArgs = null;
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updateArgs): int {
                $updateArgs = $args;
                return 555;
            }
        );
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess $e) {
            $this->assertSame(555, $updateArgs['ID']);
            $this->assertSame('Titolo Tradotto', $updateArgs['post_title']);
            $this->assertSame('<p>Contenuto tradotto.</p>', $updateArgs['post_content']);
            $this->assertSame('Estratto tradotto.', $updateArgs['post_excerpt']);
            $this->assertSame(555, $e->data['post_id']);
        }
    }

    public function test_writes_translated_alt_text_for_attachments_with_text(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_type'    => 'attachment',
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\when('get_post_meta')->justReturn('Original alt');
        Functions\when('cdcf_openai_translate')->justReturn(['alt_text' => 'Testo alternativo']);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value) use (&$metaWrites): bool {
                $metaWrites[$key] = $value;
                return true;
            }
        );
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess $e) {
            $this->assertSame('Testo alternativo', $metaWrites['_wp_attachment_image_alt']);
        }
    }

    public function test_writes_translated_acf_fields(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource([
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\when('get_field_objects')->justReturn([
            'description' => [
                'name'  => 'description',
                'type'  => 'wysiwyg',
                'value' => '<p>Source description.</p>',
            ],
        ]);
        Functions\when('cdcf_openai_translate')->justReturn([
            'acf_description' => '<p>Descrizione tradotta.</p>',
        ]);

        $fieldWrites = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$fieldWrites): bool {
                $fieldWrites[$field] = $value;
                return true;
            }
        );
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess) {
            $this->assertSame('<p>Descrizione tradotta.</p>', $fieldWrites['description']);
        }
    }

    public function test_published_source_triggers_auto_publish_when_target_is_draft(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakeSource(['post_status' => 'publish']));
        Functions\when('cdcf_openai_translate')->justReturn(['post_title' => 'X']);
        Functions\when('get_post_status')->justReturn('draft');

        $updates = [];
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updates): int {
                $updates[] = $args;
                return 555;
            }
        );
        $this->setExitToThrow();
        $this->allowAllFunctionsToExist();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess) {
            // The final wp_update_post call promotes the target to publish.
            $publishCall = end($updates);
            $this->assertSame(555, $publishCall['ID']);
            $this->assertSame('publish', $publishCall['post_status']);
        }
    }
}
