<?php

/**
 * Project-tag propagation to translated posts.
 *
 * When a translated `project` or `community_project` sibling transitions
 * to publish (typically via the auto-translation worker promoting it
 * after its EN source went public), copy the EN source's `project_tag`
 * term assignments onto the translated post — translating tag names
 * to the target language via OpenAI and reusing Polylang sibling terms
 * when they already exist.
 *
 * Why this exists: the translation worker only translates post_title
 * and post_content. It does NOT propagate term assignments. Combined
 * with project_tag being registered as a translatable taxonomy in
 * Polylang (functions.php's pll_get_taxonomies filter), this means a
 * referral approved on the EN source gets translated content on the
 * IT/ES/FR/PT/DE siblings but no tags — they render with an empty tag
 * row on the frontend.
 *
 * Helpers + callback live here (not in submission-lifecycle.php) so
 * the term-side concern stays separable from the post-side lifecycle.
 *
 * Extracted to its own file so the bodies can be unit-tested with
 * Brain Monkey + Mockery.
 */

defined('ABSPATH') || exit;

/**
 * transition_post_status hook: when a translated project or
 * community_project sibling transitions to publish, mirror the EN
 * source's project_tag assignments onto it using matching-language
 * sibling terms (creating + linking + OpenAI-translating new ones
 * as needed).
 *
 * EN source publishes are skipped — the EN post already has its own
 * terms (assigned at submission time or by an admin in wp-admin).
 *
 * Registered with priority 30 from functions.php — after the
 * priority-20 enqueue-translations hook and the priority-25
 * cdcf_link_referral_on_publish hook.
 */
function cdcf_propagate_project_tags_on_publish($new_status, $old_status, $post): void {
    if ($new_status !== 'publish' || $old_status === 'publish') {
        return;
    }
    if (!in_array($post->post_type, ['project', 'community_project'], true)) {
        return;
    }

    if (!function_exists('pll_get_post_language')) {
        return;
    }
    $post_lang = pll_get_post_language($post->ID, 'slug');
    if (!$post_lang) {
        return;
    }
    // EN source carries its own terms — nothing to propagate FROM.
    if ($post_lang === 'en') {
        return;
    }

    if (
        !function_exists('pll_get_term')
        || !function_exists('pll_set_term_language')
        || !function_exists('pll_save_term_translations')
        || !function_exists('pll_get_term_translations')
    ) {
        return;
    }

    // cdcf_get_source_post_id walks back to the EN sibling. If the
    // post IS the source (or Polylang can't resolve), bail — there's
    // nothing to copy FROM.
    $source_id = cdcf_get_source_post_id($post->ID);
    if ($source_id === $post->ID) {
        return;
    }

    $source_terms = wp_get_object_terms($source_id, 'project_tag', ['fields' => 'all']);
    if (is_wp_error($source_terms) || empty($source_terms)) {
        return;
    }

    $target_term_ids = [];
    foreach ($source_terms as $en_term) {
        $sibling_id = cdcf_get_or_create_translated_term($en_term, $post_lang);
        if ($sibling_id) {
            $target_term_ids[] = $sibling_id;
        }
    }

    if (!empty($target_term_ids)) {
        wp_set_object_terms($post->ID, $target_term_ids, 'project_tag', false);
    }
}

/**
 * Find the Polylang sibling of $en_term in $target_lang. If one does
 * not exist, OpenAI-translate the term name, create the new term,
 * link it into $en_term's Polylang translation group, and return its
 * ID.
 *
 * @return int|null  The target-language term ID, or null on failure
 *                   (OpenAI error, wp_insert_term error other than a
 *                   term_exists already-my-sibling case, Polylang missing,
 *                   or a slug collision against a term that belongs to a
 *                   different EN sibling — see below).
 */
function cdcf_get_or_create_translated_term($en_term, string $target_lang): ?int {
    $existing = pll_get_term((int) $en_term->term_id, $target_lang);
    // pll_get_term can return the input term_id itself as a fallback in
    // some Polylang versions when no target-language sibling exists.
    // Without this guard the EN term gets assigned to a non-EN post
    // and pll_set_term_language below silently flips its language,
    // corrupting the source — observed on production 2026-06-08 with
    // ConfessIt: EN term 171 ("examen") ended up language=fr after
    // propagation runs against ES/FR posts.
    if ($existing && (int) $existing !== (int) $en_term->term_id) {
        return (int) $existing;
    }

    $translated_name = cdcf_translate_term_name($en_term->name, $target_lang);
    if (!$translated_name) {
        return null;
    }

    $result = wp_insert_term($translated_name, 'project_tag', [
        'slug' => sanitize_title($translated_name . '-' . $target_lang),
    ]);

    if (is_wp_error($result)) {
        if ($result->get_error_code() === 'term_exists') {
            // A target-language term with this slug already exists.
            // Typical cause: two distinct EN terms whose OpenAI
            // translations land on the same target word (e.g. "examen"
            // + "examination" both translate to "examen" in Romance
            // languages). Resolution policy:
            //   - If the colliding term IS already a Polylang sibling
            //     of our EN term, return its id (idempotent — safe to
            //     reuse on a re-run).
            //   - Otherwise, DO NOT adopt + rewrite the colliding term's
            //     Polylang group. That would reassign it from its
            //     original EN sibling to ours, orphaning the original
            //     link AND (because pll_set_term_language re-asserts
            //     language) sometimes flipping a term's language to
            //     match the adopter — the bug that corrupted ConfessIt.
            //     Skip with a logged warning and let the caller render
            //     fewer tags than the source had.
            $existing_id = (int) $result->get_error_data();
            if ($existing_id) {
                $en_translations = pll_get_term_translations((int) $en_term->term_id);
                $is_already_my_sibling = is_array($en_translations)
                    && isset($en_translations[$target_lang])
                    && (int) $en_translations[$target_lang] === $existing_id;
                if ($is_already_my_sibling) {
                    return $existing_id;
                }
                error_log(sprintf(
                    'cdcf_get_or_create_translated_term: slug collision on "%s" (%s); existing term %d is linked to a different EN sibling — skipping to avoid corrupting Polylang group. EN term %d will not be propagated to %s.',
                    $translated_name,
                    $target_lang,
                    $existing_id,
                    (int) $en_term->term_id,
                    $target_lang
                ));
                return null;
            }
        }
        error_log(sprintf(
            'cdcf_get_or_create_translated_term: wp_insert_term failed for "%s" (%s): %s',
            $translated_name,
            $target_lang,
            $result->get_error_message()
        ));
        return null;
    }

    $new_term_id = (int) $result['term_id'];
    pll_set_term_language($new_term_id, $target_lang);

    // Merge into the EN term's existing Polylang group so all language
    // siblings know about each other.
    $translations = pll_get_term_translations((int) $en_term->term_id);
    if (!is_array($translations)) {
        $translations = [];
    }
    $translations['en']         = (int) $en_term->term_id;
    $translations[$target_lang] = $new_term_id;
    pll_save_term_translations($translations);

    return $new_term_id;
}

/**
 * Translate a single term name from English to $target_lang_slug via
 * the existing OpenAI helper. Returns the translated name, or null on
 * any failure (missing API key, OpenAI error, empty response).
 */
function cdcf_translate_term_name(string $en_name, string $target_lang_slug): ?string {
    if (!function_exists('cdcf_openai_translate')) {
        error_log('cdcf_translate_term_name: cdcf_openai_translate helper unavailable.');
        return null;
    }

    $api_key = get_option('cdcf_openai_api_key');
    if (!$api_key) {
        error_log('cdcf_translate_term_name: cdcf_openai_api_key is empty.');
        return null;
    }

    $source_name = defined('CDCF_LOCALE_NAMES') ? (CDCF_LOCALE_NAMES['en'] ?? 'English') : 'English';
    $target_name = defined('CDCF_LOCALE_NAMES') ? (CDCF_LOCALE_NAMES[$target_lang_slug] ?? $target_lang_slug) : $target_lang_slug;

    $result = cdcf_openai_translate(['term' => $en_name], $source_name, $target_name, $api_key);
    if (is_wp_error($result)) {
        error_log(sprintf(
            'cdcf_translate_term_name: OpenAI error translating "%s" to %s: %s',
            $en_name,
            $target_lang_slug,
            $result->get_error_message()
        ));
        return null;
    }

    $translated = isset($result['term']) ? trim((string) $result['term']) : '';
    return $translated !== '' ? $translated : null;
}
