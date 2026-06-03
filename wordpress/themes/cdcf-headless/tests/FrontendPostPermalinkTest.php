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
}
