<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_process_translation() — the WP-Cron orchestrator that
 * pulls translatable strings from a source post, chunks oversized fields,
 * calls the OpenAI client, and writes the translated values back into the
 * target post (title / content / excerpt + ACF fields + featured image +
 * auto-publish gate).
 *
 * Each test stubs the WP + ACF + Polylang functions it actually consults,
 * plus cdcf_openai_translate() itself so the real HTTP path never fires.
 *
 * Brain Monkey ordering trap: declare every stub BEFORE the wholesale
 * function_exists override (otherwise FunctionStub short-circuits and the
 * eval-declared stub function is never created). See allowAllFunctionsToExist().
 */
final class ProcessTranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // error_log is noisy in the orchestrator (every branch logs).
        // Silence so PHPUnit output stays clean.
        Patchwork\redefine('error_log', static fn(string $msg): bool => true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build a stdClass mimicking the shape of WP_Post that get_post()
     * would return. The orchestrator reads post_title, post_content,
     * post_excerpt, post_type, post_status.
     */
    private function fakePost(array $overrides = []): stdClass
    {
        $post = new stdClass();
        $post->post_title   = 'Sample Title';
        $post->post_content = '<p>Sample content.</p>';
        $post->post_excerpt = 'Sample excerpt.';
        $post->post_type    = 'post';
        $post->post_status  = 'publish';
        foreach ($overrides as $k => $v) {
            $post->$k = $v;
        }
        return $post;
    }

    /**
     * Stub the side-effect-free WP helpers in the happy-path config.
     * Individual tests override what they need to drive specific branches.
     */
    private function stubCommonFunctions(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('get_option')->alias(
            static fn(string $opt) => $opt === 'cdcf_openai_api_key' ? 'sk-test' : null
        );
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_field_objects')->justReturn([]);
        Functions\when('get_field')->justReturn('');
        Functions\when('update_field')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_update_post')->justReturn(123);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('set_post_thumbnail')->justReturn(true);
        Functions\when('get_post_status')->justReturn('draft');
        Functions\when('pll_get_post')->justReturn(0);
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    // ─── Bail-out branches ────────────────────────────────────────────

    public function test_returns_wp_error_when_source_post_does_not_exist(): void
    {
        Functions\when('get_post')->justReturn(null);
        Functions\expect('cdcf_openai_translate')->never();
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('source_missing', $result->get_error_code());
    }

    public function test_returns_true_when_source_has_no_translatable_content(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\expect('cdcf_openai_translate')->never();
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertTrue($result);
    }

    public function test_returns_wp_error_when_openai_api_key_not_configured(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post')->justReturn($this->fakePost());
        Functions\expect('cdcf_openai_translate')->never();
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('no_api_key', $result->get_error_code());
    }

    // ─── Happy path (small single-batch post) ─────────────────────────

    public function test_happy_path_writes_translated_title_content_and_excerpt(): void
    {
        $this->stubCommonFunctions();
        // Draft source → auto-publish branch is skipped so there's only
        // one wp_update_post call to capture.
        Functions\when('get_post')->justReturn($this->fakePost(['post_status' => 'draft']));
        Functions\when('cdcf_openai_translate')->justReturn([
            'post_title'   => 'Titolo Tradotto',
            'post_content' => '<p>Contenuto tradotto.</p>',
            'post_excerpt' => 'Estratto tradotto.',
        ]);

        $updateArgs = null;
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updateArgs): int {
                $updateArgs = $args;
                return 99;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertTrue($result);
        $this->assertSame(99, $updateArgs['ID']);
        $this->assertSame('Titolo Tradotto', $updateArgs['post_title']);
        $this->assertSame('<p>Contenuto tradotto.</p>', $updateArgs['post_content']);
        $this->assertSame('Estratto tradotto.', $updateArgs['post_excerpt']);
    }

    public function test_openai_wp_error_surfaces_unchanged(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost());
        $apiError = new WP_Error('openai_error', 'upstream 500', ['status' => 500]);
        Functions\when('cdcf_openai_translate')->justReturn($apiError);
        Functions\expect('wp_update_post')->never();
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertSame($apiError, $result);
    }

    // ─── Chunked-field branch (large post_content) ────────────────────

    public function test_oversized_content_is_chunked_and_reassembled(): void
    {
        $this->stubCommonFunctions();

        // post_content over CDCF_TRANSLATION_CHUNK_CHARS with paragraph
        // boundaries — exactly what triggers the chunking branch.
        // Each paragraph alone (≥3000 chars) exceeds the cap when combined
        // with another, forcing one chunk per paragraph.
        $p1 = '<p>' . str_repeat('a', 3000) . '</p>';
        $p2 = '<p>' . str_repeat('b', 3000) . '</p>';
        $p3 = '<p>' . str_repeat('c', 3000) . '</p>';
        $longContent = $p1 . $p2 . $p3;

        Functions\when('get_post')->justReturn($this->fakePost([
            'post_title'   => '',
            'post_excerpt' => '',
            'post_content' => $longContent,
            'post_status'  => 'draft', // skip auto-publish branch
        ]));

        // First call (empty batch path is bypassed because chunked_fields
        // pulls post_content out; remaining strings empty → batch skipped).
        // Each chunked call returns a translated chunk with the same key.
        $chunkCalls = 0;
        Functions\when('cdcf_openai_translate')->alias(
            function (array $strings) use (&$chunkCalls): array {
                $chunkCalls++;
                $key = array_key_first($strings);
                return [$key => "[chunk-{$chunkCalls}]"];
            }
        );

        $updateArgs = null;
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updateArgs): int {
                if (isset($args['post_content'])) {
                    $updateArgs = $args; // capture the translation-write call
                }
                return 99;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertTrue($result);
        // Three paragraphs at 3000 chars each → 3 chunks → 3 OpenAI calls.
        $this->assertSame(3, $chunkCalls);
        // Translated parts concatenated in order.
        $this->assertSame('[chunk-1][chunk-2][chunk-3]', $updateArgs['post_content']);
    }

    public function test_falls_back_to_untranslated_chunk_when_model_omits_key(): void
    {
        $this->stubCommonFunctions();

        // Two-chunk content: first chunked call returns the expected key,
        // the second returns a wrong/missing key — orchestrator must fall
        // back to the untranslated chunk so the output still reassembles.
        $p1 = '<p>' . str_repeat('a', 2600) . '</p>';
        $p2 = '<p>' . str_repeat('b', 2600) . '</p>';

        Functions\when('get_post')->justReturn($this->fakePost([
            'post_title'   => '',
            'post_excerpt' => '',
            'post_content' => $p1 . $p2,
        ]));

        $chunkCalls = 0;
        Functions\when('cdcf_openai_translate')->alias(
            function (array $strings) use (&$chunkCalls): array {
                $chunkCalls++;
                $key = array_key_first($strings);
                if ($chunkCalls === 1) {
                    return [$key => '[ok-translated]'];
                }
                // Second call: model "hallucinated" — returns a JSON
                // object without the expected key.
                return ['wrong_key' => '[hallucinated]'];
            }
        );

        $updateArgs = null;
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updateArgs): int {
                if (isset($args['post_content'])) {
                    $updateArgs = $args;
                }
                return 99;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertTrue($result);
        // Second chunk falls back to its original (untranslated) content.
        $this->assertStringContainsString('[ok-translated]', $updateArgs['post_content']);
        $this->assertStringContainsString($p2, $updateArgs['post_content']);
    }

    public function test_chunked_call_wp_error_surfaces_immediately(): void
    {
        $this->stubCommonFunctions();

        $p1 = '<p>' . str_repeat('a', 2600) . '</p>';
        $p2 = '<p>' . str_repeat('b', 2600) . '</p>';

        Functions\when('get_post')->justReturn($this->fakePost([
            'post_title'   => '',
            'post_excerpt' => '',
            'post_content' => $p1 . $p2,
        ]));

        $chunkErr = new WP_Error('openai_error', 'still down', ['status' => 503]);
        Functions\when('cdcf_openai_translate')->justReturn($chunkErr);
        Functions\expect('wp_update_post')->never();
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertSame($chunkErr, $result);
    }

    // ─── Attachment + ACF + featured-image branches ───────────────────

    public function test_attachment_alt_text_is_translated_into_post_meta(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
            'post_type'    => 'attachment',
            'post_title'   => '',
            'post_content' => '',
            'post_excerpt' => '',
        ]));
        Functions\when('get_post_meta')->justReturn('Original alt');
        Functions\when('cdcf_openai_translate')->justReturn([
            'alt_text' => 'Testo alternativo',
        ]);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value) use (&$metaWrites): bool {
                $metaWrites[$key] = $value;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertTrue($result);
        $this->assertSame('Testo alternativo', $metaWrites['_wp_attachment_image_alt']);
    }

    public function test_translatable_acf_fields_are_collected_and_written(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
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
            'sidebar_image_id' => [
                'name'  => 'sidebar_image_id',
                'type'  => 'image', // NOT in CDCF_TRANSLATABLE_ACF_TYPES
                'value' => 42,
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
        $this->allowAllFunctionsToExist();

        $result = cdcf_process_translation(99, 100, 'it');

        $this->assertTrue($result);
        $this->assertSame(
            '<p>Descrizione tradotta.</p>',
            $fieldWrites['description']
        );
        // Non-translatable image field copies over from source (since
        // get_field returns '' on the target — see stubCommonFunctions).
        $this->assertSame(42, $fieldWrites['sidebar_image_id']);
    }

    public function test_featured_image_copied_using_translated_media_id(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost());
        Functions\when('cdcf_openai_translate')->justReturn(['post_title' => 'X']);
        // Source has a thumbnail (id 500), target doesn't (returns 0).
        $thumbnailCallCount = 0;
        Functions\when('get_post_thumbnail_id')->alias(
            function (int $post_id) use (&$thumbnailCallCount): int {
                $thumbnailCallCount++;
                return $thumbnailCallCount === 1 ? 500 : 0;
            }
        );
        // Polylang translates media 500 → 501 for the 'it' locale.
        Functions\when('pll_get_post')->justReturn(501);

        $thumbnailSet = null;
        Functions\when('set_post_thumbnail')->alias(
            function (int $post_id, int $thumb_id) use (&$thumbnailSet): bool {
                $thumbnailSet = [$post_id, $thumb_id];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_process_translation(99, 100, 'it');

        $this->assertSame([99, 501], $thumbnailSet);
    }

    public function test_published_source_triggers_auto_publish_when_target_is_draft(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
            'post_status' => 'publish',
            'post_type'   => 'post',
        ]));
        Functions\when('cdcf_openai_translate')->justReturn(['post_title' => 'X']);
        Functions\when('get_post_status')->justReturn('draft');

        $updates = [];
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updates): int {
                $updates[] = $args;
                return 99;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_process_translation(99, 100, 'it');

        // The final wp_update_post call promotes the target to publish.
        $publishCall = end($updates);
        $this->assertSame(99, $publishCall['ID']);
        $this->assertSame('publish', $publishCall['post_status']);
    }

    public function test_does_not_auto_publish_when_target_already_published(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->fakePost([
            'post_status' => 'publish',
        ]));
        Functions\when('cdcf_openai_translate')->justReturn(['post_title' => 'X']);
        Functions\when('get_post_status')->justReturn('publish');

        $updates = [];
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updates): int {
                $updates[] = $args;
                return 99;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_process_translation(99, 100, 'it');

        // Only one wp_update_post call — the translation write. No
        // status-promotion call should follow.
        foreach ($updates as $args) {
            $this->assertArrayNotHasKey('post_status', $args);
        }
    }
}
