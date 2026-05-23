<?php
/**
 * Point WordPress post permalinks at the headless Next.js frontend.
 *
 * In headless mode the WP permalink (cms.catholicdigitalcommons.org/<slug>/)
 * is never the canonical public URL — the Next.js frontend serves posts at
 * /blog/<slug>. Without this, the admin "View Post" link, the admin bar, the
 * post-list "View" row action, and the block editor (which reads the post's
 * REST `link` field) all send editors to the dead WP-side URL.
 *
 * The slug→path mapping mirrors app/api/preview/route.ts: /blog/<slug>, with
 * an /<lang> prefix for non-default Polylang locales (en has no prefix).
 *
 * Registration (the add_filter call) lives in functions.php; the bodies are
 * extracted here so they can be unit-tested with Brain Monkey.
 */

if (defined('ABSPATH') === false) {
    return;
}

/**
 * Build the public frontend URL for a published post, or null if the post
 * should keep its default WordPress permalink (wrong type, not published, or
 * no slug yet — never-published drafts are handled by preview_post_link).
 *
 * @param WP_Post|object $post Post object as passed to the post_link filter.
 */
function cdcf_frontend_post_url($post): ?string
{
    if (
        !is_object($post)
        || ($post->post_type ?? '') !== 'post'
        || ($post->post_status ?? '') !== 'publish'
        || ($post->post_name ?? '') === ''
    ) {
        return null;
    }

    $frontend = defined('CDCF_FRONTEND_URL')
        ? CDCF_FRONTEND_URL
        : 'http://localhost:3000';

    // Polylang language slug ("en", "it", …); empty when Polylang is off.
    $lang = function_exists('pll_get_post_language')
        ? pll_get_post_language($post->ID, 'slug')
        : '';
    // "en" is hardcoded on purpose: it is the *frontend's* default locale
    // (src/i18n/routing.ts defaultLocale, localePrefix: 'as-needed'), which
    // gets no URL prefix — NOT Polylang's configured default. This must mirror
    // app/api/preview/route.ts so preview and published URLs agree; using
    // pll_default_language() here would diverge from the frontend's routing.
    $prefix = ($lang && $lang !== 'en') ? '/' . $lang : '';

    return $frontend . $prefix . '/blog/' . $post->post_name;
}

/**
 * post_link filter: rewrite a published post's permalink to the frontend.
 *
 * Bails on WPGraphQL requests. The frontend resolves its own routes from
 * slugs and consumes the unmodified `uri` field (derived from the permalink —
 * e.g. the page sitemap in lib/wordpress/api.ts), so rewriting permalinks
 * during a GraphQL request would corrupt that data. REST requests (which feed
 * the block editor's permalink) are not GraphQL requests, so they still get
 * the frontend URL.
 *
 * @param string         $permalink Default WordPress permalink.
 * @param WP_Post|object $post      Post being linked.
 */
function cdcf_filter_post_permalink(string $permalink, $post): string
{
    if (function_exists('is_graphql_request') && is_graphql_request()) {
        return $permalink;
    }

    return cdcf_frontend_post_url($post) ?? $permalink;
}
