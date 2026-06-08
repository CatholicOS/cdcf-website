<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the project-tag propagation hook + helpers in
 * includes/admin/term-propagation.php:
 *
 *   - cdcf_propagate_project_tags_on_publish()  — transition_post_status hook
 *   - cdcf_get_or_create_translated_term()      — find or create + Polylang-link
 *   - cdcf_translate_term_name()                — OpenAI single-term wrapper
 *
 * Brain Monkey ordering: every stub Brain Monkey needs to eval-declare
 * must be set up BEFORE function_exists is wholesale overridden — the
 * FunctionStub constructor short-circuits when function_exists() says
 * the target already exists, leaving the symbol undefined at call time.
 */
final class TermPropagationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Suppress error_log noise from the handlers' failure branches.
        Patchwork\redefine('error_log', static fn(string $msg): bool => true);
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

    private function fakePost(array $overrides = []): stdClass
    {
        $post = new stdClass();
        $post->ID         = 100;
        $post->post_title = 'A Project';
        $post->post_type  = 'community_project';
        foreach ($overrides as $k => $v) {
            $post->$k = $v;
        }
        return $post;
    }

    private function fakeTerm(int $id, string $name): stdClass
    {
        $term = new stdClass();
        $term->term_id = $id;
        $term->name    = $name;
        return $term;
    }

    /**
     * Set up Brain Monkey stubs needed across propagate tests:
     * stub the Polylang functions cdcf_get_source_post_id needs, plus
     * is_wp_error (the function under test calls is_wp_error on the
     * wp_get_object_terms result).
     *
     * Caller is responsible for setting pll_get_post / pll_get_post_language
     * / wp_get_object_terms / etc. to the per-test values.
     */
    private function stubCommonFunctions(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('pll_get_post_language')->justReturn('it');
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('pll_set_term_language')->justReturn(true);
        Functions\when('pll_save_term_translations')->justReturn(true);
        Functions\when('pll_get_term_translations')->justReturn([]);
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('wp_set_object_terms')->justReturn([]);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 999]);
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('cdcf_openai_translate')->justReturn(['term' => 'translated']);
        Functions\when('sanitize_title')->alias(static fn(string $s): string => strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $s)));
        Functions\when('get_post_meta')->justReturn('');
    }

    // ─── cdcf_propagate_project_tags_on_publish ───────────────────────

    public function test_propagate_bails_on_same_status_transition(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'publish', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_on_non_publish_transition(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('draft', 'pending', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_on_unsupported_post_type(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'page'])
        );
        cdcf_propagate_project_tags_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'community_channel'])
        );

        $this->assertTrue(true);
    }

    public function test_propagate_bails_when_pll_get_post_language_missing(): void
    {
        $this->stubCommonFunctions();
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_get_post_language'
        );
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_when_post_has_no_language(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post_language')->justReturn(false);
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_when_post_is_en_source(): void
    {
        // EN source posts already have their terms — nothing to copy.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_when_polylang_term_functions_missing(): void
    {
        $this->stubCommonFunctions();
        // pll_get_term gated out — fail-closed.
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_get_term'
        );
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_when_source_resolves_to_self(): void
    {
        // pll_get_post returns 0 → cdcf_get_source_post_id falls back to the
        // input id, which equals $post->ID → no EN sibling to copy from.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(0);
        Functions\expect('wp_get_object_terms')->never();
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_when_source_has_no_terms(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42); // source ID 42, post ID 100
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_bails_when_wp_get_object_terms_returns_wp_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42);
        Functions\when('wp_get_object_terms')->justReturn(new WP_Error('term_query_failed', 'oops'));
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_reuses_existing_polylang_sibling_terms(): void
    {
        // When EN→IT sibling already exists, we should reuse it and
        // never hit OpenAI / wp_insert_term.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42);
        Functions\when('wp_get_object_terms')->justReturn([
            $this->fakeTerm(169, 'confession'),
            $this->fakeTerm(171, 'examen'),
        ]);
        // Stub pll_get_term to return the matching IT sibling for each.
        Functions\when('pll_get_term')->alias(
            static fn(int $en_id, string $lang) =>
                $lang === 'it' ? ['169' => 500, '171' => 501][(string) $en_id] ?? 0 : 0
        );
        Functions\expect('cdcf_openai_translate')->never();
        Functions\expect('wp_insert_term')->never();

        $captured = new stdClass();
        $captured->call = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $post_id, array $term_ids, string $taxonomy, bool $append) use ($captured): array {
                $captured->call = [$post_id, $term_ids, $taxonomy, $append];
                return [];
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertSame([100, [500, 501], 'project_tag', false], $captured->call);
    }

    public function test_propagate_creates_new_term_via_openai_and_assigns(): void
    {
        // No existing sibling → OpenAI translates → wp_insert_term creates → assigned.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42);
        Functions\when('wp_get_object_terms')->justReturn([
            $this->fakeTerm(169, 'confession'),
        ]);
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('cdcf_openai_translate')->justReturn(['term' => 'confessione']);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 770]);

        $assignment = new stdClass();
        $assignment->call = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $post_id, array $term_ids, string $taxonomy, bool $append) use ($assignment): array {
                $assignment->call = [$post_id, $term_ids, $taxonomy, $append];
                return [];
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertSame([100, [770], 'project_tag', false], $assignment->call);
    }

    public function test_propagate_skips_terms_whose_translation_fails(): void
    {
        // 3 EN terms; OpenAI fails on the middle one (returns empty
        // string for 'examen'). The other 2 still get propagated.
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42);
        Functions\when('wp_get_object_terms')->justReturn([
            $this->fakeTerm(169, 'confession'),
            $this->fakeTerm(171, 'examen'),
            $this->fakeTerm(173, 'reconciliation'),
        ]);
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('cdcf_openai_translate')->alias(
            static fn(array $strings) => $strings['term'] === 'examen'
                ? ['term' => '']
                : ['term' => 'translated-' . $strings['term']]
        );
        $insertCounter = 800;
        Functions\when('wp_insert_term')->alias(
            function () use (&$insertCounter): array {
                $insertCounter++;
                return ['term_id' => $insertCounter];
            }
        );

        $assignment = new stdClass();
        $assignment->call = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $post_id, array $term_ids) use ($assignment): array {
                $assignment->call = $term_ids;
                return [];
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        // 801 = first insert (confession), 802 = second insert (reconciliation).
        // 'examen' was skipped before reaching wp_insert_term.
        $this->assertSame([801, 802], $assignment->call);
    }

    public function test_propagate_makes_no_assignment_when_all_terms_fail(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42);
        Functions\when('wp_get_object_terms')->justReturn([
            $this->fakeTerm(169, 'confession'),
        ]);
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('cdcf_openai_translate')->justReturn(new WP_Error('openai_error', 'fail'));
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish('publish', 'draft', $this->fakePost());

        $this->assertTrue(true);
    }

    public function test_propagate_also_handles_project_post_type(): void
    {
        // Same flow for `project` posts (scope per AskUserQuestion answer #2).
        $this->stubCommonFunctions();
        Functions\when('pll_get_post')->justReturn(42);
        Functions\when('wp_get_object_terms')->justReturn([
            $this->fakeTerm(169, 'confession'),
        ]);
        Functions\when('pll_get_term')->justReturn(770);
        Functions\expect('cdcf_openai_translate')->never();

        $assignment = new stdClass();
        $assignment->call = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $post_id, array $term_ids) use ($assignment): array {
                $assignment->call = [$post_id, $term_ids];
                return [];
            }
        );
        $this->allowAllFunctionsToExist();

        cdcf_propagate_project_tags_on_publish(
            'publish',
            'draft',
            $this->fakePost(['post_type' => 'project'])
        );

        $this->assertSame([100, [770]], $assignment->call);
    }

    // ─── cdcf_get_or_create_translated_term ───────────────────────────

    public function test_get_or_create_returns_existing_sibling_id(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_term')->justReturn(500);
        Functions\expect('cdcf_openai_translate')->never();
        Functions\expect('wp_insert_term')->never();
        $this->allowAllFunctionsToExist();

        $this->assertSame(500, cdcf_get_or_create_translated_term($this->fakeTerm(169, 'confession'), 'it'));
    }

    public function test_get_or_create_returns_null_when_openai_translation_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('cdcf_openai_translate')->justReturn(new WP_Error('openai_error', 'fail'));
        Functions\expect('wp_insert_term')->never();
        $this->allowAllFunctionsToExist();

        $this->assertNull(cdcf_get_or_create_translated_term($this->fakeTerm(169, 'confession'), 'it'));
    }

    public function test_get_or_create_adopts_existing_term_on_slug_collision(): void
    {
        // wp_insert_term returns a term_exists WP_Error with the colliding
        // term's ID in data. The handler should adopt that ID and still
        // Polylang-link it.
        $this->stubCommonFunctions();
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('cdcf_openai_translate')->justReturn(['term' => 'confessione']);
        Functions\when('wp_insert_term')->justReturn(
            new WP_Error('term_exists', 'A term with this slug exists', 880)
        );

        $polyCall = new stdClass();
        $polyCall->set_lang = null;
        $polyCall->save_translations = null;
        Functions\when('pll_set_term_language')->alias(
            function (int $term_id, string $lang) use ($polyCall): bool {
                $polyCall->set_lang = [$term_id, $lang];
                return true;
            }
        );
        Functions\when('pll_save_term_translations')->alias(
            function (array $translations) use ($polyCall): bool {
                $polyCall->save_translations = $translations;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_get_or_create_translated_term($this->fakeTerm(169, 'confession'), 'it');

        $this->assertSame(880, $result);
        $this->assertSame([880, 'it'], $polyCall->set_lang);
        $this->assertSame(['en' => 169, 'it' => 880], $polyCall->save_translations);
    }

    public function test_get_or_create_links_new_term_into_polylang_group(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('cdcf_openai_translate')->justReturn(['term' => 'esame']);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 802]);
        Functions\when('pll_get_term_translations')->justReturn(['fr' => 905]);

        $captured = new stdClass();
        $captured->set_lang = null;
        $captured->save_translations = null;
        Functions\when('pll_set_term_language')->alias(
            function (int $term_id, string $lang) use ($captured): bool {
                $captured->set_lang = [$term_id, $lang];
                return true;
            }
        );
        Functions\when('pll_save_term_translations')->alias(
            function (array $translations) use ($captured): bool {
                $captured->save_translations = $translations;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $result = cdcf_get_or_create_translated_term($this->fakeTerm(171, 'examen'), 'it');

        $this->assertSame(802, $result);
        $this->assertSame([802, 'it'], $captured->set_lang);
        // EN root + the pre-existing FR sibling + the newly-created IT sibling.
        $this->assertSame(['fr' => 905, 'en' => 171, 'it' => 802], $captured->save_translations);
    }

    public function test_get_or_create_returns_null_on_non_recoverable_wp_insert_term_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('pll_get_term')->justReturn(0);
        Functions\when('cdcf_openai_translate')->justReturn(['term' => 'confessione']);
        Functions\when('wp_insert_term')->justReturn(
            new WP_Error('invalid_taxonomy', 'no such taxonomy')
        );
        Functions\expect('pll_set_term_language')->never();
        $this->allowAllFunctionsToExist();

        $this->assertNull(cdcf_get_or_create_translated_term($this->fakeTerm(169, 'confession'), 'it'));
    }

    // ─── cdcf_translate_term_name ─────────────────────────────────────

    public function test_translate_term_name_returns_null_when_helper_missing(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('get_option')->justReturn('sk-test-key');
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'cdcf_openai_translate'
        );

        $this->assertNull(cdcf_translate_term_name('confession', 'it'));
    }

    public function test_translate_term_name_returns_null_when_api_key_missing(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('cdcf_openai_translate')->justReturn(['term' => 'never-reached']);
        Functions\when('get_option')->justReturn(''); // missing key
        $this->allowAllFunctionsToExist();

        $this->assertNull(cdcf_translate_term_name('confession', 'it'));
    }

    public function test_translate_term_name_returns_null_on_openai_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_openai_translate')->justReturn(
            new WP_Error('openai_error', 'rate limit', ['status' => 429])
        );
        $this->allowAllFunctionsToExist();

        $this->assertNull(cdcf_translate_term_name('confession', 'it'));
    }

    public function test_translate_term_name_returns_null_on_empty_response(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_openai_translate')->justReturn(['term' => '   ']);
        $this->allowAllFunctionsToExist();

        $this->assertNull(cdcf_translate_term_name('confession', 'it'));
    }

    public function test_translate_term_name_returns_translated_string_on_success(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_openai_translate')->alias(
            static fn(array $strings, string $source, string $target) => $source === 'English' && $target === 'Italian'
                ? ['term' => 'confessione']
                : ['term' => 'wrong']
        );
        $this->allowAllFunctionsToExist();

        $this->assertSame('confessione', cdcf_translate_term_name('confession', 'it'));
    }
}
