<?php
/**
 * REST route handler for /cdcf/v1/link-translations.
 *
 * Given a {lang => post_id} map of two or more already-existing posts,
 * sets each post's Polylang language and links the group together in
 * a single call. Used for repairing language metadata on imported or
 * manually-created content.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_link_translations(WP_REST_Request $request) {
    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }

    $translations = $request['translations'];
    if (!is_array($translations) || count($translations) < 2) {
        return new WP_Error('invalid_translations', 'Provide at least 2 language => post_id pairs.', ['status' => 400]);
    }

    // Validate all posts exist.
    foreach ($translations as $lang => $post_id) {
        $post_id = (int) $post_id;
        if (!get_post($post_id)) {
            return new WP_Error('invalid_post', "Post {$post_id} does not exist.", ['status' => 400]);
        }
        $translations[$lang] = $post_id;
    }

    // Set language on each post and link them.
    foreach ($translations as $lang => $post_id) {
        pll_set_post_language($post_id, $lang);
    }
    pll_save_post_translations($translations);

    return rest_ensure_response([
        'success'      => true,
        'translations' => $translations,
    ]);
}
