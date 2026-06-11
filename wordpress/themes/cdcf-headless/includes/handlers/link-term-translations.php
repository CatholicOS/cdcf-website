<?php
/**
 * REST route handler for /cdcf/v1/link-term-translations.
 *
 * Term equivalent of /link-translations. Given a {lang => term_id} map
 * of two or more already-existing terms in a single taxonomy, sets each
 * term's Polylang language and links them into a single translation
 * group in one atomic pll_save_term_translations() call.
 *
 * Use case: repairing corrupted Polylang term groups (e.g. after a
 * propagation bug scrambled sibling links) or seeding language metadata
 * on terms created outside the normal flow. Polylang's term-side
 * language and translation-group helpers are PHP-only — there's no
 * native REST surface for them, hence this thin wrapper.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_link_term_translations(WP_REST_Request $request) {
    if (
        !function_exists('pll_set_term_language')
        || !function_exists('pll_save_term_translations')
    ) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }

    $taxonomy = $request['taxonomy'];
    if (!is_string($taxonomy) || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return new WP_Error(
            'invalid_taxonomy',
            "Taxonomy '{$taxonomy}' does not exist.",
            ['status' => 400]
        );
    }

    $translations = $request['translations'];
    if (!is_array($translations) || count($translations) < 2) {
        return new WP_Error(
            'invalid_translations',
            'Provide at least 2 language => term_id pairs.',
            ['status' => 400]
        );
    }

    // Validate all terms exist in the named taxonomy.
    foreach ($translations as $lang => $term_id) {
        $term_id = (int) $term_id;
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return new WP_Error(
                'invalid_term',
                "Term {$term_id} does not exist in taxonomy '{$taxonomy}'.",
                ['status' => 400]
            );
        }
        $translations[$lang] = $term_id;
    }

    // Set language on each term, then atomically link the group.
    foreach ($translations as $lang => $term_id) {
        pll_set_term_language($term_id, $lang);
    }
    if (pll_save_term_translations($translations) === false) {
        return new WP_Error(
            'link_failed',
            'Polylang refused to save the term translation group.',
            ['status' => 500]
        );
    }

    return rest_ensure_response([
        'success'      => true,
        'taxonomy'     => $taxonomy,
        'translations' => $translations,
    ]);
}
