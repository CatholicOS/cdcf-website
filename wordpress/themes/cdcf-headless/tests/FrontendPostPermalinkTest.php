<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the post_link → Next.js frontend rewrite:
 *   - cdcf_frontend_post_url()      (pure slug→URL mapping)
 *   - cdcf_filter_post_permalink()  (post_link filter, with GraphQL bail)
 *
 * CDCF_FRONTEND_URL is not defined in the test process, so the helper falls
 * back to http://localhost:3000 — assertions use that base. Brain Monkey's
 * when() auto-declares each stubbed function, so function_exists() inside the
 * code under test reflects exactly which ones are stubbed.
 */
final class FrontendPostPermalinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
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

    public function test_published_english_post_maps_to_blog_slug_without_locale_prefix(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');

        $this->assertSame(
            'http://localhost:3000/blog/welcome-to-the-cdcf',
            cdcf_frontend_post_url($this->makePost())
        );
    }

    public function test_non_default_locale_gets_a_language_prefix(): void
    {
        Functions\when('pll_get_post_language')->justReturn('it');

        $this->assertSame(
            'http://localhost:3000/it/blog/welcome-to-the-cdcf',
            cdcf_frontend_post_url($this->makePost())
        );
    }

    public function test_returns_null_for_non_post_types(): void
    {
        // A page or CPT keeps its default permalink (handled elsewhere / not
        // a flat /blog/ route), so the helper opts out before touching it.
        $this->assertNull(cdcf_frontend_post_url($this->makePost(['post_type' => 'page'])));
        $this->assertNull(cdcf_frontend_post_url($this->makePost(['post_type' => 'project'])));
    }

    public function test_returns_null_for_unpublished_or_slugless_posts(): void
    {
        // Drafts have no public frontend URL (preview_post_link covers their
        // by-id preview); a never-published post has no slug to build a path.
        $this->assertNull(cdcf_frontend_post_url($this->makePost(['post_status' => 'draft'])));
        $this->assertNull(cdcf_frontend_post_url($this->makePost(['post_name' => ''])));
    }

    public function test_filter_rewrites_published_post_permalink(): void
    {
        Functions\when('pll_get_post_language')->justReturn('en');
        // is_graphql_request is left unstubbed → function_exists() is false →
        // the GraphQL bail is skipped and the rewrite proceeds.

        $this->assertSame(
            'http://localhost:3000/blog/welcome-to-the-cdcf',
            cdcf_filter_post_permalink(
                'https://cms.example.org/welcome-to-the-cdcf/',
                $this->makePost()
            )
        );
    }

    public function test_filter_leaves_permalink_untouched_during_graphql_requests(): void
    {
        // Stubbing is_graphql_request both declares it (so function_exists is
        // true) and makes it report a GraphQL request — the frontend's `uri`
        // field, derived from the permalink, must stay unmodified.
        Functions\when('is_graphql_request')->justReturn(true);

        $original = 'https://cms.example.org/welcome-to-the-cdcf/';
        $this->assertSame(
            $original,
            cdcf_filter_post_permalink($original, $this->makePost())
        );
    }
}
