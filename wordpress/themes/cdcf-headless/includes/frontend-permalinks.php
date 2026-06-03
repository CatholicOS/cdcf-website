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

// Post types the Next.js frontend supports BY-ID preview for (the /api/preview
// route validates `type` against this same allowlist). Other CPTs have no
// by-id preview path, so their draft permalinks stay untouched.
const CDCF_FRONTEND_PREVIEWABLE_TYPES = ['post', 'page'];

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
 * Build the fully-qualified Next.js /api/preview URL for a post — secret,
 * id, type, slug, lang in the query string. Shared by the preview_post_link
 * filter (the Gutenberg "Preview" button) and the admin-post.php redirect
 * handler (which is reached from the editor's "View" / hamburger menu on a
 * draft).
 *
 * @param WP_Post|object $post
 */
function cdcf_build_frontend_preview_url($post): string
{
    $frontend = defined('CDCF_FRONTEND_URL')
        ? CDCF_FRONTEND_URL
        : 'http://localhost:3000';
    $secret = defined('CDCF_PREVIEW_SECRET')
        ? CDCF_PREVIEW_SECRET
        : (getenv('WP_PREVIEW_SECRET') ?: '');
    $lang = function_exists('pll_get_post_language')
        ? pll_get_post_language($post->ID, 'slug')
        : '';

    return add_query_arg(
        [
            'secret' => $secret,
            'id'     => $post->ID,
            'type'   => $post->post_type,
            'slug'   => $post->post_name,
            'lang'   => $lang,
        ],
        rtrim($frontend, '/') . '/api/preview'
    );
}

/**
 * True when this post should resolve its public permalink to the WP-side
 * preview-redirect endpoint instead of the frontend. That is the case for
 * any post/page that is not yet published (drafts, auto-drafts, pending),
 * because the frontend has no public route for them and would otherwise serve
 * the home page in preview mode (the WP default ?p=<id> link resolves to "/"
 * in the Next.js catch-all). Trashed posts are excluded — they're not
 * surfaced with editor "View" links.
 *
 * @param WP_Post|object $post
 */
function cdcf_should_redirect_to_preview($post): bool
{
    if (!is_object($post)) {
        return false;
    }
    if (!in_array($post->post_type ?? '', CDCF_FRONTEND_PREVIEWABLE_TYPES, true)) {
        return false;
    }
    $status = $post->post_status ?? '';
    return $status !== '' && $status !== 'publish' && $status !== 'trash';
}

/**
 * admin-post.php?action=cdcf_preview_redirect&id=<id>
 *
 * The shared preview secret never appears in get_permalink() / REST `link`
 * output: editor "View" / hamburger links on drafts point at THIS handler,
 * which is cookie-authenticated by WP core (admin_post_ prefix → logged-in
 * only) and capability-gated here. We only then redirect to the frontend's
 * /api/preview URL with the secret. Read-only redirect; no state change, so
 * no nonce needed — the edit_post capability check IS the auth gate.
 */
function cdcf_redirect_to_frontend_preview(): void
{
    $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    if ($id <= 0) {
        wp_die('Missing or invalid post id.', 'Preview', ['response' => 400]);
    }
    $post = get_post($id);
    if (!is_object($post)) {
        wp_die('Post not found.', 'Preview', ['response' => 404]);
    }
    // Capability check first, then eligibility. Reversing this would let an
    // unauthorized caller fingerprint a post id as draft vs published-or-CPT
    // by reading the 400 vs 403 response code; gating on edit_post first means
    // only capable users see the more specific eligibility message.
    if (!current_user_can('edit_post', $id)) {
        wp_die('You do not have permission to preview this post.', 'Preview', ['response' => 403]);
    }
    // Mirror the cdcf_frontend_permalink draft-branch allowlist (post/page,
    // not-yet-published). Without this, a capable user could redirect a
    // published post / CPT through here — the frontend would still 401 or
    // 400, but the WP-side contract is clearer if we refuse here.
    if (!cdcf_should_redirect_to_preview($post)) {
        wp_die('This post is not eligible for frontend preview.', 'Preview', ['response' => 400]);
    }
    wp_redirect(cdcf_build_frontend_preview_url($post));
    exit;
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

    // Drafts/auto-drafts/pending of post/page: send permalink consumers (editor
    // "View" / hamburger menu, post-list View, admin bar) through the WP-side
    // preview-redirect endpoint. Cookie auth + edit_post capability check live
    // there; the shared preview secret is added server-side at redirect time
    // and never appears in this string. Without this, drafts fell through to
    // the default WP ?p=<id> permalink and the Next.js catch-all served the
    // home page in preview mode.
    if (cdcf_should_redirect_to_preview($post)) {
        return admin_url('admin-post.php?action=cdcf_preview_redirect&id=' . absint($post->ID));
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
