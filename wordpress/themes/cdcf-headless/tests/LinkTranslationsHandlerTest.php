<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/link-translations handler.
 */
final class LinkTranslationsHandlerTest extends TestCase
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

    private function stubPolylangAndCorePost(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('get_post')->alias(static fn(int $id) => (object) ['ID' => $id]);
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    private function makeRequest(array $params): WP_REST_Request
    {
        $req = new WP_REST_Request();
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_returns_500_when_polylang_inactive(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_set_post_language'
        );

        $response = cdcf_rest_link_translations($this->makeRequest([
            'translations' => ['en' => 1, 'it' => 2],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('polylang_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_translations_is_not_array(): void
    {
        $this->stubPolylangAndCorePost();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_translations($this->makeRequest([
            'translations' => 'not-an-array',
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_translations', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_fewer_than_two_translations_provided(): void
    {
        $this->stubPolylangAndCorePost();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_translations($this->makeRequest([
            'translations' => ['en' => 1],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_translations', $response->get_error_code());
    }

    public function test_returns_400_when_any_referenced_post_does_not_exist(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        // Post 99 exists, post 100 does not.
        Functions\when('get_post')->alias(
            static fn(int $id) => $id === 99 ? (object) ['ID' => 99] : null
        );
        Functions\expect('pll_set_post_language')->never();
        Functions\expect('pll_save_post_translations')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_translations($this->makeRequest([
            'translations' => ['en' => 99, 'it' => 100],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_post', $response->get_error_code());
        $this->assertStringContainsString('100', $response->get_error_message());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_pll_save_post_translations_fails(): void
    {
        $this->stubPolylangAndCorePost();
        Functions\when('pll_save_post_translations')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_translations($this->makeRequest([
            'translations' => ['en' => 10, 'it' => 11],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('link_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_happy_path_sets_language_on_each_post_and_links_group(): void
    {
        $this->stubPolylangAndCorePost();

        $languageWrites = [];
        Functions\when('pll_set_post_language')->alias(
            function (int $post_id, string $lang) use (&$languageWrites): bool {
                $languageWrites[] = [$post_id, $lang];
                return true;
            }
        );

        $linkedGroup = null;
        Functions\when('pll_save_post_translations')->alias(
            function (array $map) use (&$linkedGroup): bool {
                $linkedGroup = $map;
                return true;
            }
        );

        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_translations($this->makeRequest([
            // Mix string IDs to verify the (int) coercion.
            'translations' => ['en' => 10, 'it' => '11', 'es' => '12'],
        ]));

        $this->assertSame(
            [[10, 'en'], [11, 'it'], [12, 'es']],
            $languageWrites
        );
        $this->assertSame(
            ['en' => 10, 'it' => 11, 'es' => 12],
            $linkedGroup
        );
        $this->assertTrue($response['success']);
        $this->assertSame(['en' => 10, 'it' => 11, 'es' => 12], $response['translations']);
    }
}
