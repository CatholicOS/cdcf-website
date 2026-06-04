<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the permalink → Next.js frontend rewrite:
 *   - cdcf_frontend_path_for()   (pure type→path mapping)
 *   - cdcf_frontend_permalink()  (post_link/page_link/post_type_link filter)
 *
 * CDCF_FRONTEND_URL is not defined in the test process, so the filter falls
 * back to http://localhost:3000. Brain Monkey's when() auto-declares each
 * stubbed function, so function_exists() inside the code reflects exactly which
 * ones are stubbed (used for the is_graphql_request bail).
 */
final class FrontendPostPermalinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Default: nothing is the static front page.
        Functions\when('get_option')->justReturn(0);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makePost(array $overrides = []): object
    {
        return (object) array_merge([
            'ID'          => 17,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'post_name'   => 'welcome-to-the-cdcf',
        ], $overrides);
    }

    // ─── posts ────────────────────────────────────────────────────

    public function test_published_english_post_maps_to_blog_slug_without_locale_prefix(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');

        $this->assertSame('/blog/welcome-to-the-cdcf', cdcf_frontend_path_for($this->makePost()));
    }

    public function test_non_default_locale_gets_a_language_prefix(): void
    {
        Functions\when('pll_get_post_language')->justReturn('it');

        $this->assertSame('/it/blog/welcome-to-the-cdcf', cdcf_frontend_path_for($this->makePost()));
    }

    // ─── pages ────────────────────────────────────────────────────

    public function test_page_uses_its_hierarchical_uri(): void
    {
        Functions\when('pll_get_post_language')->justReturn('pt');
        Functions\when('get_page_uri')->justReturn('governanca/governanca-de-ia');

        $this->assertSame(
            '/pt/governanca/governanca-de-ia',
            cdcf_frontend_path_for($this->makePost(['post_type' => 'page', 'post_name' => 'governanca-de-ia']))
        );
    }

    public function test_static_front_page_maps_to_the_locale_root(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('get_option')->justReturn(17); // page_on_front === this ID

        $this->assertSame('/', cdcf_frontend_path_for($this->makePost(['post_type' => 'page'])));
    }

    public function test_non_default_locale_front_page_maps_to_the_locale_prefix(): void
    {
        Functions\when('pll_get_post_language')->justReturn('de');
        Functions\when('get_option')->justReturn(17);

        $this->assertSame('/de', cdcf_frontend_path_for($this->makePost(['post_type' => 'page'])));
    }

    // ─── CPTs ─────────────────────────────────────────────────────

    public function test_cpt_path_uses_the_registered_rewrite_slug(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('get_post_type_object')->justReturn(
            (object) ['rewrite' => ['slug' => 'academic-collaborations']]
        );

        $this->assertSame(
            '/academic-collaborations/notre-dame',
            cdcf_frontend_path_for($this->makePost(['post_type' => 'acad_collab', 'post_name' => 'notre-dame']))
        );
    }

    public function test_cpt_falls_back_to_post_type_key_when_no_rewrite_slug(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('get_post_type_object')->justReturn((object) ['rewrite' => true]);

        $this->assertSame(
            '/project/my-project',
            cdcf_frontend_path_for($this->makePost(['post_type' => 'project', 'post_name' => 'my-project']))
        );
    }

    // ─── opt-outs ─────────────────────────────────────────────────

    public function test_returns_null_for_unroutable_post_types(): void
    {
        $this->assertNull(cdcf_frontend_path_for($this->makePost(['post_type' => 'team_member'])));
        $this->assertNull(cdcf_frontend_path_for($this->makePost(['post_type' => 'sponsor'])));
    }

    public function test_returns_null_for_unpublished_or_slugless_posts(): void
    {
        $this->assertNull(cdcf_frontend_path_for($this->makePost(['post_status' => 'draft'])));
        Functions\when('pll_get_post_language')->justReturn('en');
        $this->assertNull(cdcf_frontend_path_for($this->makePost(['post_name' => ''])));
    }

    // ─── filter wrapper ───────────────────────────────────────────

    public function test_filter_rewrites_published_post_permalink_to_frontend(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('is_graphql_request')->justReturn(false);

        $this->assertSame(
            'http://localhost:3000/blog/welcome-to-the-cdcf',
            cdcf_frontend_permalink('https://cms.example.org/welcome-to-the-cdcf/', $this->makePost())
        );
    }

    public function test_filter_resolves_a_post_id_argument_via_get_post(): void
    {
        // page_link passes a post ID, not an object.
        Functions\when('is_graphql_request')->justReturn(false);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('get_page_uri')->justReturn('about');
        Functions\when('get_post')->justReturn($this->makePost(['post_type' => 'page', 'post_name' => 'about']));

        $this->assertSame(
            'http://localhost:3000/about',
            cdcf_frontend_permalink('https://cms.example.org/about/', 42)
        );
    }

    public function test_filter_bails_out_when_get_post_returns_null(): void
    {
        // page_link can pass a post id whose post no longer exists
        // (e.g. trashed mid-request). The filter must return $link verbatim.
        Functions\when('is_graphql_request')->justReturn(false);
        Functions\when('get_post')->justReturn(null);

        $original = 'https://cms.example.org/?p=999';
        $this->assertSame($original, cdcf_frontend_permalink($original, 999));
    }

    public function test_filter_leaves_permalink_untouched_during_graphql_requests(): void
    {
        // Stubbing is_graphql_request both declares it (function_exists true)
        // and makes it report a GraphQL request — the frontend's `uri` field,
        // derived from the permalink, must stay unmodified.
        Functions\when('is_graphql_request')->justReturn(true);

        $original = 'https://cms.example.org/welcome-to-the-cdcf/';
        $this->assertSame($original, cdcf_frontend_permalink($original, $this->makePost()));
    }

    public function test_filter_leaves_unroutable_types_untouched(): void
    {
        Functions\when('is_graphql_request')->justReturn(false);
        $original = 'https://cms.example.org/?team_member=jane';
        $this->assertSame(
            $original,
            cdcf_frontend_permalink($original, $this->makePost(['post_type' => 'team_member']))
        );
    }

    // ─── cdcf_build_frontend_preview_url ──────────────────────────

    public function test_preview_url_carries_id_type_slug_secret_lang(): void
    {
        Functions\when('pll_get_post_language')->justReturn('it');
        // No CDCF_PREVIEW_SECRET / WP_PREVIEW_SECRET in the test process: the
        // helper still emits an empty `secret` param so failures show as 401
        // at the frontend rather than as a silently-missing query arg.
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });

        $url = cdcf_build_frontend_preview_url(
            $this->makePost(['ID' => 99, 'post_type' => 'page', 'post_name' => 'about'])
        );

        $this->assertStringStartsWith('http://localhost:3000/api/preview?', $url);
        $this->assertStringContainsString('id=99', $url);
        $this->assertStringContainsString('type=page', $url);
        $this->assertStringContainsString('slug=about', $url);
        $this->assertStringContainsString('lang=it', $url);
    }

    /**
     * Brain Monkey's Functions\when() auto-declares the target function in the
     * PHP process, and PHP can't undeclare a function — so once ANY earlier
     * test stubs pll_get_post_language, the helper's function_exists() check
     * stays true for the rest of the process. Run this case in an isolated
     * process so the lang-fallback (": ''" arm) is reachable.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_preview_url_emits_empty_lang_when_polylang_is_not_installed(): void
    {
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });

        $url = cdcf_build_frontend_preview_url(
            $this->makePost(['ID' => 7, 'post_type' => 'page', 'post_name' => 'no-polylang'])
        );

        // lang param is present but empty (no Polylang → no value to fill).
        $this->assertStringContainsString('lang=', $url);
        $this->assertStringContainsString('id=7', $url);
    }

    /**
     * Constants can't be defined twice in the same PHP process, so the
     * defined-CDCF_FRONTEND_URL / defined-CDCF_PREVIEW_SECRET truthy arms
     * of the helper need an isolated process to be observable. PHPUnit's
     * @runInSeparateProcess takes care of forking the runtime.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_preview_url_uses_defined_constants_when_present(): void
    {
        define('CDCF_FRONTEND_URL', 'https://frontend.example.org');
        define('CDCF_PREVIEW_SECRET', 'shh-it-is-a-secret');

        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });

        $url = cdcf_build_frontend_preview_url(
            $this->makePost(['ID' => 42, 'post_name' => 'hello'])
        );

        $this->assertStringStartsWith('https://frontend.example.org/api/preview?', $url);
        $this->assertStringContainsString('secret=shh-it-is-a-secret', $url);
    }

    public function test_preview_url_works_for_never_published_draft_with_empty_slug(): void
    {
        // A brand-new draft has no post_name yet; the URL still emits id so
        // the frontend can resolve by database id.
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });

        $url = cdcf_build_frontend_preview_url(
            $this->makePost(['ID' => 1377, 'post_status' => 'auto-draft', 'post_name' => ''])
        );

        $this->assertStringContainsString('id=1377', $url);
    }

    // ─── cdcf_should_redirect_to_preview ──────────────────────────

    public function test_should_redirect_for_draft_post_and_page(): void
    {
        $this->assertTrue(cdcf_should_redirect_to_preview(
            $this->makePost(['post_status' => 'draft'])
        ));
        $this->assertTrue(cdcf_should_redirect_to_preview(
            $this->makePost(['post_type' => 'page', 'post_status' => 'auto-draft'])
        ));
        $this->assertTrue(cdcf_should_redirect_to_preview(
            $this->makePost(['post_type' => 'page', 'post_status' => 'pending'])
        ));
    }

    public function test_should_not_redirect_for_published_or_trashed_or_unsupported_types(): void
    {
        // Published: handled by the existing path mapper, not the redirect.
        $this->assertFalse(cdcf_should_redirect_to_preview(
            $this->makePost(['post_status' => 'publish'])
        ));
        // Trashed: not surfaced with editor "View" links.
        $this->assertFalse(cdcf_should_redirect_to_preview(
            $this->makePost(['post_status' => 'trash'])
        ));
        // CPTs: frontend /api/preview only allows post/page; redirecting a
        // project/acad_collab draft would 400 there.
        $this->assertFalse(cdcf_should_redirect_to_preview(
            $this->makePost(['post_type' => 'project', 'post_status' => 'draft'])
        ));
        $this->assertFalse(cdcf_should_redirect_to_preview(
            $this->makePost(['post_type' => 'acad_collab', 'post_status' => 'draft'])
        ));
        // Untyped/typeless input from a misbehaving caller.
        $this->assertFalse(cdcf_should_redirect_to_preview('not an object'));
    }

    // ─── cdcf_frontend_permalink (draft branch) ───────────────────

    public function test_filter_routes_draft_post_through_admin_post_redirect(): void
    {
        Functions\when('is_graphql_request')->justReturn(false);
        Functions\when('admin_url')->alias(function ($path) {
            return 'https://cms.example.org/wp-admin/' . ltrim($path, '/');
        });

        $link = cdcf_frontend_permalink(
            'https://cms.example.org/?p=1377',
            $this->makePost(['ID' => 1377, 'post_status' => 'draft', 'post_name' => ''])
        );

        $this->assertSame(
            'https://cms.example.org/wp-admin/admin-post.php?action=cdcf_preview_redirect&id=1377',
            $link
        );
    }

    public function test_filter_does_not_route_published_post_through_redirect(): void
    {
        // Verify the draft branch doesn't accidentally catch published posts.
        Functions\when('is_graphql_request')->justReturn(false);
        Functions\when('pll_get_post_language')->justReturn('en');

        $this->assertSame(
            'http://localhost:3000/blog/welcome-to-the-cdcf',
            cdcf_frontend_permalink('https://cms.example.org/welcome-to-the-cdcf/', $this->makePost())
        );
    }

    public function test_filter_does_not_redirect_drafts_of_unsupported_cpts(): void
    {
        // Draft project/acad_collab fall through to the default permalink —
        // no by-id preview route on the frontend for those types.
        Functions\when('is_graphql_request')->justReturn(false);

        $original = 'https://cms.example.org/?project=foo';
        $this->assertSame(
            $original,
            cdcf_frontend_permalink(
                $original,
                $this->makePost(['post_type' => 'project', 'post_status' => 'draft', 'post_name' => 'foo'])
            )
        );
    }

    // ─── cdcf_redirect_to_frontend_preview (admin-post handler) ───
    //
    // The handler ends with wp_redirect()+exit and uses wp_die() for the
    // error branches. We stub wp_die/wp_redirect to throw typed exceptions
    // so each branch is observable without actually exiting PHPUnit, and
    // we restore $_GET around each case.

    private function stubHandlerTerminators(): void
    {
        Functions\when('wp_die')->alias(function ($message = '', $title = '', $args = []) {
            throw new \RuntimeException(
                'wp_die:' . (int)($args['response'] ?? 500) . ':' . (string)$message
            );
        });
        Functions\when('wp_redirect')->alias(function ($url) {
            throw new \RuntimeException('wp_redirect:' . $url);
        });
        Functions\when('absint')->alias(fn($v) => abs((int)$v));
    }

    public function test_handler_400s_on_missing_or_invalid_id(): void
    {
        $this->stubHandlerTerminators();
        $_GET = [];

        try {
            cdcf_redirect_to_frontend_preview();
            $this->fail('Expected wp_die for missing id.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:400:', $e->getMessage());
        }

        $_GET = ['id' => '0'];
        try {
            cdcf_redirect_to_frontend_preview();
            $this->fail('Expected wp_die for zero id.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:400:', $e->getMessage());
        }

        $_GET = [];
    }

    public function test_handler_404s_when_post_not_found(): void
    {
        $this->stubHandlerTerminators();
        Functions\when('get_post')->justReturn(null);
        $_GET = ['id' => '1377'];

        try {
            cdcf_redirect_to_frontend_preview();
            $this->fail('Expected wp_die for missing post.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:404:', $e->getMessage());
        } finally {
            $_GET = [];
        }
    }

    public function test_handler_403s_when_user_lacks_edit_post(): void
    {
        // Use a PUBLISHED post (i.e. one that would ALSO fail the
        // eligibility check) to verify the ordering invariant: the 403 must
        // come from the capability check, not from the eligibility check
        // that runs after it. If the order were ever reversed (eligibility
        // first), this same fixture would produce a 400 and the test would
        // fail. Also verify current_user_can was invoked with the expected
        // capability and id, not some looser check.
        $this->stubHandlerTerminators();
        Functions\when('get_post')->justReturn($this->makePost(['ID' => 1377, 'post_status' => 'publish']));
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 1377)
            ->andReturn(false);
        $_GET = ['id' => '1377'];

        try {
            cdcf_redirect_to_frontend_preview();
            $this->fail('Expected wp_die for missing capability.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:403:', $e->getMessage());
        } finally {
            $_GET = [];
        }
    }

    public function test_handler_400s_when_post_is_not_eligible_for_preview(): void
    {
        // A capable user trying to redirect a published post (or a CPT)
        // through the preview endpoint is rejected — the frontend allowlist
        // wouldn't accept it either, and we want the WP-side contract clear.
        $this->stubHandlerTerminators();
        Functions\when('get_post')->justReturn($this->makePost(['ID' => 5, 'post_status' => 'publish']));
        Functions\when('current_user_can')->justReturn(true);
        $_GET = ['id' => '5'];

        try {
            cdcf_redirect_to_frontend_preview();
            $this->fail('Expected wp_die for ineligible post.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:400:', $e->getMessage());
            $this->assertStringContainsString('not eligible', $e->getMessage());
        } finally {
            $_GET = [];
        }
    }

    public function test_handler_redirects_to_frontend_preview_url_on_happy_path(): void
    {
        $this->stubHandlerTerminators();
        Functions\when('get_post')->justReturn(
            $this->makePost(['ID' => 1377, 'post_status' => 'draft', 'post_name' => 'a-draft'])
        );
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });
        $_GET = ['id' => '1377'];

        try {
            cdcf_redirect_to_frontend_preview();
            $this->fail('Expected wp_redirect.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('wp_redirect:http://localhost:3000/api/preview?', $e->getMessage());
            $this->assertStringContainsString('id=1377', $e->getMessage());
            $this->assertStringContainsString('slug=a-draft', $e->getMessage());
        } finally {
            $_GET = [];
        }
    }
}
