<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Shared scaffolding for the three Community-page handlers
 * (community_channel, local_group, acad_collab). All three follow the
 * same translate-then-link-to-Community-page skeleton, so each test
 * here exercises an invariant the trio shares; concrete subclasses
 * only supply per-handler config via the abstract methods.
 *
 * Brain Monkey ordering: every stub Brain Monkey needs to eval-declare
 * must be set up BEFORE function_exists is wholesale overridden — its
 * FunctionStub constructor short-circuits when function_exists() says
 * the target already exists, leaving the symbol undefined at call
 * time. Hence stubCommonFunctions() + allowAllFunctionsToExist().
 */
abstract class CommunityHandlerTestBase extends TestCase
{
    /**
     * Invoke the handler under test with the given request. Each
     * subclass routes to its specific cdcf_rest_create_* function.
     */
    abstract protected function invokeHandler(WP_REST_Request $request): mixed;

    /**
     * The ACF relationship field name on the Community page that the
     * handler appends to (e.g. 'channels', 'local_groups',
     * 'academic_collaborations').
     */
    abstract protected function getRelationshipField(): string;

    /**
     * Build a minimal request payload with all required fields set to
     * sentinel values. Optional fields default to empty strings.
     */
    abstract protected function makeRequest(array $overrides = []): WP_REST_Request;

    /**
     * The insert_failed error message — slightly different wording per
     * handler ('community channel', 'local group', 'academic collaboration').
     */
    abstract protected function getInsertFailureMessage(): string;

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

    /**
     * Stub the side-effect-free helpers the handler reaches for on
     * the happy path. Must be called BEFORE allowAllFunctionsToExist.
     */
    protected function stubCommonFunctions(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('cdcf_enqueue_translation')->justReturn('redis');
    }

    protected function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    /**
     * Return a wp_insert_post stub that hands out monotonically rising
     * IDs starting from $base, so the EN post and its five translation
     * drafts each get distinct IDs.
     */
    protected function stubInsertingPostsFrom(int $base): void
    {
        $counter = $base - 1;
        Functions\when('wp_insert_post')->alias(function () use (&$counter): int {
            return ++$counter;
        });
    }

    /**
     * Configure a single Community page (English) that resolves
     * cleanly via pll_get_post_language, with translations for all
     * six languages and the relationship field starting empty.
     */
    protected function stubCommunityPageHappy(int $en_page_id, array $translations): void
    {
        Functions\when('get_pages')->justReturn([(object) ['ID' => $en_page_id]]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn($translations);
        Functions\when('get_field')->justReturn([]);
    }

    // ─── Guard clauses ────────────────────────────────────────────

    public function test_returns_500_when_polylang_inactive(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_set_post_language'
        );

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('polylang_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_acf_inactive(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'update_field'
        );

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('acf_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_wp_insert_post_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('wp_insert_post')->justReturn(0);
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
        $this->assertSame($this->getInsertFailureMessage(), $response->get_error_message());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_wp_insert_post_returns_wp_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('wp_insert_post')->justReturn(new WP_Error('db_insert', 'DB down'));
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
    }

    // ─── Happy path + Community-page resolution ───────────────────

    public function test_links_to_community_page_per_language_on_happy_path(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(100);
        $this->stubCommunityPageHappy(50, [
            'en' => 50, 'it' => 51, 'es' => 52, 'fr' => 53, 'pt' => 54, 'de' => 55,
        ]);

        $linked = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $page_id) use (&$linked): bool {
                // Only the relationship-field call goes through; the
                // ACF setter calls on the EN post (description/url/etc)
                // also flow here but with a different field name. We
                // track only the ones targeting the Community page.
                if ($field === $this->getRelationshipField()) {
                    $linked[] = [$field, $value, $page_id];
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(202, $response->get_status());

        $field = $this->getRelationshipField();
        $this->assertSame(
            [
                [$field, [100], 50],
                [$field, [101], 51],
                [$field, [102], 52],
                [$field, [103], 53],
                [$field, [104], 54],
                [$field, [105], 55],
            ],
            $linked
        );
        $this->assertSame([], $response->get_data()['errors']);
    }

    public function test_records_error_when_no_community_page_exists(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(200);
        Functions\when('get_pages')->justReturn([]);

        $touched = false;
        $field = $this->getRelationshipField();
        Functions\when('update_field')->alias(
            function (string $name) use ($field, &$touched): bool {
                if ($name === $field) {
                    $touched = true;
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertFalse($touched, 'relationship field must not be written when no Community page exists');
        $this->assertContains(
            'No Community page found with templates/community.php template.',
            $response->get_data()['errors']
        );
    }

    public function test_falls_back_to_pll_get_post_when_no_language_match(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(300);

        // get_pages returns a page whose language reports as 'it' — the
        // loop fails to find an EN match, so the handler falls back to
        // pll_get_post() to resolve the EN translation.
        Functions\when('get_pages')->justReturn([(object) ['ID' => 70]]);
        Functions\when('pll_get_post_language')->justReturn('it');
        Functions\when('pll_get_post')->justReturn(75);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 75, 'it' => 76, 'es' => 77, 'fr' => 78, 'pt' => 79, 'de' => 80,
        ]);
        Functions\when('get_field')->justReturn([]);

        $field = $this->getRelationshipField();
        $linkedPages = [];
        Functions\when('update_field')->alias(
            function (string $name, $value, int $page_id) use ($field, &$linkedPages): bool {
                if ($name === $field) {
                    $linkedPages[] = $page_id;
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertSame([75, 76, 77, 78, 79, 80], $linkedPages);
        $this->assertSame([], $response->get_data()['errors']);
    }

    public function test_records_error_when_no_english_community_page_resolvable(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(400);

        Functions\when('get_pages')->justReturn([(object) ['ID' => 70]]);
        Functions\when('pll_get_post_language')->justReturn('it');
        Functions\when('pll_get_post')->justReturn(0);

        $touched = false;
        $field = $this->getRelationshipField();
        Functions\when('update_field')->alias(
            function (string $name) use ($field, &$touched): bool {
                if ($name === $field) {
                    $touched = true;
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertFalse($touched);
        $this->assertContains(
            'Could not find the English Community page.',
            $response->get_data()['errors']
        );
    }

    public function test_skips_languages_without_community_page_translation(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(500);
        // Only EN + ES + DE translations of the Community page exist;
        // the other three should be skipped with per-language errors.
        $this->stubCommunityPageHappy(60, [
            'en' => 60, 'es' => 62, 'de' => 65,
        ]);

        $field = $this->getRelationshipField();
        $linkedPages = [];
        Functions\when('update_field')->alias(
            function (string $name, $value, int $page_id) use ($field, &$linkedPages): bool {
                if ($name === $field) {
                    $linkedPages[] = $page_id;
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        // Three languages got linked, three errors recorded.
        $this->assertSame([60, 62, 65], $linkedPages);
        $errors = $response->get_data()['errors'];
        $this->assertContains('it: No Community page translation found.', $errors);
        $this->assertContains('fr: No Community page translation found.', $errors);
        $this->assertContains('pt: No Community page translation found.', $errors);
    }

    public function test_skips_update_when_post_already_present_in_relationship(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(600);

        Functions\when('get_pages')->justReturn([(object) ['ID' => 90]]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 90, 'it' => 91, 'es' => 92, 'fr' => 93, 'pt' => 94, 'de' => 95,
        ]);
        // EN already lists 600 — handler should skip update_field for that page.
        Functions\when('get_field')->alias(
            static fn(string $f, int $id) => $id === 90 ? [600] : []
        );

        $field = $this->getRelationshipField();
        $linkedPages = [];
        Functions\when('update_field')->alias(
            function (string $name, $value, int $page_id) use ($field, &$linkedPages): bool {
                if ($name === $field) {
                    $linkedPages[] = $page_id;
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest());

        // EN (90) skipped because 600 was already in the relationship;
        // the other five languages get linked.
        $this->assertSame([91, 92, 93, 94, 95], $linkedPages);
    }

    public function test_translation_insert_failure_records_error_and_continues(): void
    {
        $this->stubCommonFunctions();
        // First call (EN) returns 700; second (it) fails returning 0;
        // remaining translations succeed.
        $calls = 0;
        $ids = [700, 0, 701, 702, 703, 704];
        Functions\when('wp_insert_post')->alias(function () use (&$calls, $ids): int {
            return $ids[$calls++];
        });
        $this->stubCommunityPageHappy(60, [
            'en' => 60, 'it' => 61, 'es' => 62, 'fr' => 63, 'pt' => 64, 'de' => 65,
        ]);
        Functions\when('update_field')->justReturn(true);
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $data = $response->get_data();
        $this->assertContains('it: Failed to create translation post.', $data['errors']);
        $this->assertArrayNotHasKey('it', $data['translations']);
        $this->assertSame(700, $data['translations']['en']);
        $this->assertSame(701, $data['translations']['es']);
    }
}
