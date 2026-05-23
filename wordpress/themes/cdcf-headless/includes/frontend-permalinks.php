<?php
/**
 * Point WordPress permalinks at the headless Next.js frontend.
 *
 * In headless mode the WP permalink (cms.catholicdigitalcommons.org/…) is never
 * the canonical public URL. Without this, the admin "View Post"/"View Page"
 * links, the admin bar, the post-list "View" row action, and the block editor
 * (which reads the REST `link` field) all send editors to a dead WP-side URL.
 *
 * The frontend path is derived from WordPress's own routing wherever possible
 * (the "align WP URIs with the frontend" approach), so there is no duplicated
 * route table to drift:
 *   - page  → the page's hierarchical URI (get_page_uri); the frontend
 *             catch-all route resolves pages by that exact path.
 *   - CPTs  → /<rewrite-slug>/<slug>, where rewrite-slug is read from the post
 *             type registration. The CPT rewrite slugs in functions.php are set
 *             to match the frontend routes (project → "projects",
 *             acad_collab → "academic-collaborations"), so this tracks WP.
 *   - post  → /blog/<slug>. The built-in post type's base is the site-wide
 *             permalink structure (not /blog/), so this one prefix is explicit.
 * Other CPTs (team_member, sponsor, community_*, stat_item) have no standalone
 * frontend page, so they keep their default permalink.
 *
 * A non-default Polylang locale gets an /<lang> path prefix; the default locale
 * (en) gets none, matching the frontend's `localePrefix: 'as-needed'` routing.
 *
 * Registration (the add_filter calls) lives in functions.php; the bodies are
 * extracted here so they can be unit-tested with Brain Monkey.
 */

if (defined('ABSPATH') === false) {
    return;
}

// Post types the Next.js frontend has a public route for. Anything else keeps
// its default WordPress permalink.
const CDCF_FRONTEND_ROUTABLE_TYPES = ['post', 'page', 'project', 'acad_collab'];

/**
 * Build the public frontend path (locale-prefixed, host-less) for a published
 * post, or null if it should keep its default WordPress permalink (unroutable
 * type, not published, or no slug yet — never-published drafts are handled by
 * preview_post_link).
 *
 * @param WP_Post|object $post Post object as passed to the permalink filters.
 */
function cdcf_frontend_path_for($post): ?string
{
    if (!is_object($post) || ($post->post_status ?? '') !== 'publish') {
        return null;
    }

    $type = $post->post_type ?? '';
    if (!in_array($type, CDCF_FRONTEND_ROUTABLE_TYPES, true)) {
        return null;
    }

    // Polylang language slug ("en", "it", …); empty when Polylang is off.
    $lang = function_exists('pll_get_post_language')
        ? pll_get_post_language($post->ID, 'slug')
        : '';
    // "en" is the *frontend's* default locale (src/i18n/routing.ts, localePrefix
    // 'as-needed'), which gets no URL prefix — not Polylang's configured default.
    $prefix = ($lang && $lang !== 'en') ? '/' . $lang : '';

    if ($type === 'page') {
        // The static front page lives at the locale root ("/" or "/<lang>"),
        // not at "/<its-slug>".
        if ((int) get_option('page_on_front') === (int) $post->ID) {
            return $prefix !== '' ? $prefix : '/';
        }
        // get_page_uri returns the hierarchical slug path (e.g. "governance/
        // research") with no language prefix — exactly what the catch-all route
        // expects after its [lang] segment.
        $uri = get_page_uri($post);
        return $uri !== '' ? $prefix . '/' . $uri : null;
    }

    // Never-published drafts have no slug yet; bail rather than emit "/blog/".
    if (($post->post_name ?? '') === '') {
        return null;
    }

    if ($type === 'post') {
        return $prefix . '/blog/' . $post->post_name;
    }

    // CPT: take the path segment from the registered rewrite slug so it tracks
    // the post type registration rather than a duplicated constant here.
    $base = $type;
    $obj = get_post_type_object($type);
    if (is_object($obj) && is_array($obj->rewrite) && !empty($obj->rewrite['slug'])) {
        $base = $obj->rewrite['slug'];
    }

    return $prefix . '/' . $base . '/' . $post->post_name;
}

/**
 * Permalink filter for post_link / page_link / post_type_link: rewrite a
 * routable published post's permalink to the headless frontend.
 *
 * Bails on WPGraphQL requests. The frontend resolves its own routes from slugs
 * and consumes the unmodified `uri` field (derived from the permalink — e.g.
 * the page sitemap in lib/wordpress/api.ts), so rewriting permalinks during a
 * GraphQL request would corrupt that data. REST requests — which feed the
 * block editor's permalink — are not GraphQL requests, so they still get the
 * frontend URL.
 *
 * @param string             $link Default WordPress permalink.
 * @param WP_Post|int|object $post WP_Post (post_link/post_type_link) or post ID
 *                                 (page_link).
 */
function cdcf_frontend_permalink(string $link, $post): string
{
    if (function_exists('is_graphql_request') && is_graphql_request()) {
        return $link;
    }

    if (!is_object($post)) {
        $post = get_post($post);
    }
    if (!is_object($post)) {
        return $link;
    }

    $path = cdcf_frontend_path_for($post);
    if ($path === null) {
        return $link;
    }

    $frontend = defined('CDCF_FRONTEND_URL')
        ? CDCF_FRONTEND_URL
        : 'http://localhost:3000';

    return rtrim($frontend, '/') . $path;
}
