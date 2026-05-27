<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the cdcf_ajax_ai_translate admin-ajax handler.
 *
 * The handler is now a thin, cookie+nonce-authenticated wrapper that
 * delegates to the shared cdcf_enqueue_post_translation() (the same core
 * /cdcf/v1/translate uses) and maps the result to wp_send_json_*. The
 * create/link/enqueue logic and its branches are covered by
 * TranslateHandlerTest; the worker translation by ProcessTranslationTest.
 *
 * wp_send_json_success / wp_send_json_error normally wp_die(); we stub them
 * to throw the typed CdcfAjaxSuccess / CdcfAjaxError (tests/bootstrap.php) so
 * the handler's exit points are catchable and the payload assertable.
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
        parent::tearDown();
    }

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

    public function test_returns_error_when_user_lacks_edit_posts(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        // Must bail before touching the enqueue core.
        Functions\expect('cdcf_enqueue_post_translation')->never();
        $this->setExitToThrow();

        $_POST = ['source_id' => 100, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Insufficient permissions.', $e->data);
        }
    }

    public function test_enqueues_and_returns_queued_payload(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $forwarded = null;
        Functions\when('cdcf_enqueue_post_translation')->alias(
            function (int $source_id, string $target_lang, int $post_id) use (&$forwarded): array {
                $forwarded = [$source_id, $target_lang, $post_id];
                return ['post_id' => 800, 'queue' => 'redis', 'errors' => []];
            }
        );
        $this->setExitToThrow();

        $_POST = ['source_id' => 100, 'target_lang' => 'it', 'post_id' => 0];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess $e) {
            // Params forwarded to the shared enqueue core.
            $this->assertSame([100, 'it', 0], $forwarded);
            // Response signals "queued", not "complete".
            $this->assertSame('Translation queued.', $e->data['message']);
            $this->assertSame(800, $e->data['post_id']);
            $this->assertSame('redis', $e->data['queue']);
        }
    }

    public function test_maps_enqueue_wp_error_to_json_error(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('cdcf_enqueue_post_translation')->justReturn(
            new WP_Error('not_found', 'Source post not found.', ['status' => 404])
        );
        $this->setExitToThrow();

        $_POST = ['source_id' => 999, 'target_lang' => 'it'];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxError');
        } catch (CdcfAjaxError $e) {
            $this->assertSame('Source post not found.', $e->data);
        }
    }

    public function test_forwards_provided_post_id_for_retranslation(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        $forwarded = null;
        Functions\when('cdcf_enqueue_post_translation')->alias(
            function (int $source_id, string $target_lang, int $post_id) use (&$forwarded): array {
                $forwarded = [$source_id, $target_lang, $post_id];
                return ['post_id' => $post_id, 'queue' => 'redis', 'errors' => []];
            }
        );
        $this->setExitToThrow();

        $_POST = ['source_id' => 100, 'target_lang' => 'es', 'post_id' => 555];

        try {
            cdcf_ajax_ai_translate();
            $this->fail('expected CdcfAjaxSuccess');
        } catch (CdcfAjaxSuccess $e) {
            $this->assertSame([100, 'es', 555], $forwarded);
            $this->assertSame(555, $e->data['post_id']);
        }
    }
}
