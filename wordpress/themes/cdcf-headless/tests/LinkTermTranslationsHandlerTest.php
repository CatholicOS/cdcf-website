<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/link-term-translations handler.
 */
final class LinkTermTranslationsHandlerTest extends TestCase
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

    private function stubPolylangAndCoreTerm(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('pll_set_term_language')->justReturn(true);
        Functions\when('pll_save_term_translations')->justReturn(true);
        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\when('get_term')->alias(
            static fn(int $id, string $tax) => (object) ['term_id' => $id, 'taxonomy' => $tax]
        );
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
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
            static fn(string $name): bool => $name !== 'pll_set_term_language'
        );

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy'     => 'project_tag',
            'translations' => ['en' => 169, 'it' => 231],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('polylang_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_taxonomy_is_missing(): void
    {
        $this->stubPolylangAndCoreTerm();
        Functions\when('taxonomy_exists')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy'     => 'nonexistent_tax',
            'translations' => ['en' => 169, 'it' => 231],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_taxonomy', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_translations_is_not_array(): void
    {
        $this->stubPolylangAndCoreTerm();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy'     => 'project_tag',
            'translations' => 'not-an-array',
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_translations', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_fewer_than_two_translations_provided(): void
    {
        $this->stubPolylangAndCoreTerm();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy'     => 'project_tag',
            'translations' => ['en' => 169],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_translations', $response->get_error_code());
    }

    public function test_returns_400_when_any_referenced_term_does_not_exist(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('pll_set_term_language')->justReturn(true);
        Functions\when('pll_save_term_translations')->justReturn(true);
        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        // Term 169 exists; term 999 does not.
        Functions\when('get_term')->alias(
            static fn(int $id, string $tax) =>
                $id === 169 ? (object) ['term_id' => 169, 'taxonomy' => $tax] : null
        );
        Functions\expect('pll_set_term_language')->never();
        Functions\expect('pll_save_term_translations')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy'     => 'project_tag',
            'translations' => ['en' => 169, 'it' => 999],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_term', $response->get_error_code());
        $this->assertStringContainsString('999', $response->get_error_message());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_get_term_returns_wp_error(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('pll_set_term_language')->justReturn(true);
        Functions\when('pll_save_term_translations')->justReturn(true);
        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('get_term')->justReturn(new WP_Error('invalid_term', 'oops'));
        Functions\expect('pll_set_term_language')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy'     => 'project_tag',
            'translations' => ['en' => 169, 'it' => 231],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_term', $response->get_error_code());
    }

    public function test_returns_500_when_pll_save_term_translations_fails(): void
    {
        $this->stubPolylangAndCoreTerm();
        Functions\when('pll_save_term_translations')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy'     => 'project_tag',
            'translations' => ['en' => 169, 'it' => 231],
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('link_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_happy_path_sets_language_on_each_term_and_links_group(): void
    {
        $this->stubPolylangAndCoreTerm();

        $languageWrites = [];
        Functions\when('pll_set_term_language')->alias(
            function (int $term_id, string $lang) use (&$languageWrites): bool {
                $languageWrites[] = [$term_id, $lang];
                return true;
            }
        );

        $linkedGroup = null;
        Functions\when('pll_save_term_translations')->alias(
            function (array $map) use (&$linkedGroup): bool {
                $linkedGroup = $map;
                return true;
            }
        );

        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_link_term_translations($this->makeRequest([
            'taxonomy' => 'project_tag',
            // Mix string IDs to verify the (int) coercion.
            'translations' => ['en' => 169, 'it' => '231', 'es' => '241'],
        ]));

        $this->assertSame(
            [[169, 'en'], [231, 'it'], [241, 'es']],
            $languageWrites
        );
        $this->assertSame(
            ['en' => 169, 'it' => 231, 'es' => 241],
            $linkedGroup
        );
        $this->assertTrue($response['success']);
        $this->assertSame('project_tag', $response['taxonomy']);
        $this->assertSame(['en' => 169, 'it' => 231, 'es' => 241], $response['translations']);
    }
}
