<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/deploy-translation handler.
 *
 * Sibling of /cdcf/v1/translate (covered in TranslateHandlerTest).
 * Where /translate enqueues background work, /deploy-translation
 * writes pre-translated title/content directly to the target-language
 * post, creating it if absent (with parent propagation + Polylang
 * linking).
 */
final class DeployTranslationHandlerTest extends TestCase
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

    private function stubCommonFunctions(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('wp_update_post')->justReturn(1);
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
            'title'       => 'Titolo',
            'content'     => '<p>contenuto</p>',
        ];
        foreach (array_merge($defaults, $params) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    private function makeSourcePost(array $overrides = []): object
    {
        return (object) array_merge([
            'ID'          => 100,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'post_title'  => 'Source Title',
            'post_parent' => 0,
        ], $overrides);
    }

    // ─── Guards ───────────────────────────────────────────────────

    public function test_returns_400_when_source_id_missing(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);

        $response = cdcf_rest_deploy_translation($this->makeRequest(['source_id' => 0]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('missing_params', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_content_missing(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);

        $response = cdcf_rest_deploy_translation($this->makeRequest(['content' => '']));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('missing_params', $response->get_error_code());
    }

    public function test_returns_500_when_polylang_inactive(): void
    {
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_set_post_language'
        );

        $response = cdcf_rest_deploy_translation($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('polylang_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_404_when_source_post_not_found(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn(null);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_deploy_translation($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('not_found', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    // ─── Update-existing-translation path ─────────────────────────

    public function test_updates_existing_translation_post_when_one_already_exists(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost(['post_status' => 'publish']));
        // Existing translation already at id 500 for target 'it'.
        Functions\when('pll_get_post_translations')->justReturn(['en' => 100, 'it' => 500]);

        $updateArgs = null;
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updateArgs): int {
                $updateArgs = $args;
                return $args['ID'];
            }
        );
        Functions\expect('wp_insert_post')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_deploy_translation($this->makeRequest([
            'title'   => 'Titolo Aggiornato',
            'content' => '<p>nuovo contenuto</p>',
        ]));

        $this->assertSame(
            [
                'ID'           => 500,
                'post_title'   => 'Titolo Aggiornato',
                'post_content' => '<p>nuovo contenuto</p>',
                'post_status'  => 'publish',
            ],
            $updateArgs
        );
        $this->assertSame(500, $response['post_id']);
        $this->assertSame('Translation deployed.', $response['message']);
    }

    public function test_existing_update_falls_back_to_source_title_when_title_blank(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost(['post_title' => 'Source Title']));
        Functions\when('pll_get_post_translations')->justReturn(['en' => 100, 'it' => 500]);

        $updateArgs = null;
        Functions\when('wp_update_post')->alias(
            function (array $args) use (&$updateArgs): int {
                $updateArgs = $args;
                return $args['ID'];
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_rest_deploy_translation($this->makeRequest(['title' => '']));

        $this->assertSame('Source Title', $updateArgs['post_title']);
    }

    // ─── Create-new-translation path ──────────────────────────────

    public function test_creates_new_translation_when_none_exists_and_links_languages(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost([
            'post_type'   => 'post',
            'post_status' => 'publish',
        ]));
        // No existing translation for the target.
        Functions\when('pll_get_post_translations')->justReturn(['en' => 100]);

        $insertArgs = null;
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$insertArgs): int {
                $insertArgs = $args;
                return 999;
            }
        );

        $linkedGroup = null;
        Functions\when('pll_save_post_translations')->alias(
            function (array $map) use (&$linkedGroup): bool {
                $linkedGroup = $map;
                return true;
            }
        );
        Functions\expect('wp_update_post')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_deploy_translation($this->makeRequest());

        $this->assertSame(
            [
                'post_type'    => 'post',
                'post_status'  => 'publish',
                'post_title'   => 'Titolo',
                'post_content' => '<p>contenuto</p>',
            ],
            $insertArgs
        );
        $this->assertSame(['en' => 100, 'it' => 999], $linkedGroup);
        $this->assertSame(999, $response['post_id']);
    }

    public function test_returns_500_when_wp_insert_post_returns_zero(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post_translations')->justReturn(['en' => 100]);
        Functions\when('wp_insert_post')->justReturn(0);
        Functions\expect('pll_set_post_language')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_deploy_translation($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_wp_insert_post_returns_wp_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost());
        Functions\when('pll_get_post_translations')->justReturn(['en' => 100]);
        Functions\when('wp_insert_post')->justReturn(new WP_Error('db_insert', 'DB down'));
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_deploy_translation($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
    }

    public function test_propagates_post_parent_via_parent_translation(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost(['post_parent' => 50]));
        Functions\when('pll_get_post_translations')->justReturn(['en' => 100]);
        Functions\when('pll_get_post')->justReturn(250);  // parent translation

        $insertArgs = null;
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$insertArgs): int {
                $insertArgs = $args;
                return 999;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_rest_deploy_translation($this->makeRequest());

        $this->assertSame(250, $insertArgs['post_parent']);
    }

    public function test_omits_post_parent_when_parent_translation_missing(): void
    {
        $this->stubCommonFunctions();
        Functions\when('get_post')->justReturn($this->makeSourcePost(['post_parent' => 50]));
        Functions\when('pll_get_post_translations')->justReturn(['en' => 100]);
        Functions\when('pll_get_post')->justReturn(0);

        $insertArgs = null;
        Functions\when('wp_insert_post')->alias(
            function (array $args) use (&$insertArgs): int {
                $insertArgs = $args;
                return 999;
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_rest_deploy_translation($this->makeRequest());

        $this->assertArrayNotHasKey('post_parent', $insertArgs);
    }
}
