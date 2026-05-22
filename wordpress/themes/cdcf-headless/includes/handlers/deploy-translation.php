<?php
/**
 * REST route handler for /cdcf/v1/deploy-translation.
 *
 * Sibling of /cdcf/v1/translate: where /translate enqueues background
 * work, /deploy-translation accepts an already-translated title/content
 * and writes it directly to the target-language post (creating the post
 * if it doesn't exist yet, with parent propagation + Polylang linking).
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_deploy_translation(WP_REST_Request $request) {
    $source_id   = intval($request['source_id'] ?? 0);
    $target_lang = sanitize_text_field($request['target_lang'] ?? '');
    $title       = sanitize_text_field($request['title'] ?? '');
    $content     = wp_kses_post($request['content'] ?? '');

    if (!$source_id || !$target_lang || !$content) {
        return new WP_Error('missing_params', 'Missing source_id, target_lang, or content.', ['status' => 400]);
    }

    if (!function_exists('pll_set_post_language')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }

    $source = get_post($source_id);
    if (!$source) {
        return new WP_Error('not_found', 'Source post not found.', ['status' => 404]);
    }

    // Check if a translation already exists for this language.
    $translations = pll_get_post_translations($source_id);
    $post_id = $translations[$target_lang] ?? 0;

    if ($post_id) {
        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => $title ?: $source->post_title,
            'post_content' => $content,
            'post_status'  => $source->post_status,
        ]);
    } else {
        $insert_args = [
            'post_type'    => $source->post_type,
            'post_status'  => $source->post_status,
            'post_title'   => $title ?: $source->post_title,
            'post_content' => $content,
        ];

        // Propagate parent: use the parent's translation in the target language.
        if ($source->post_parent) {
            $parent_translation = pll_get_post($source->post_parent, $target_lang);
            if ($parent_translation) {
                $insert_args['post_parent'] = $parent_translation;
            }
        }

        $post_id = wp_insert_post($insert_args);

        if (is_wp_error($post_id) || !$post_id) {
            return new WP_Error('insert_failed', 'Failed to create translation post.', ['status' => 500]);
        }

        pll_set_post_language($post_id, $target_lang);
        $source_lang = pll_get_post_language($source_id);
        $translations[$source_lang] = $source_id;
        $translations[$target_lang] = $post_id;
        pll_save_post_translations($translations);
    }

    return rest_ensure_response([
        'post_id' => $post_id,
        'message' => 'Translation deployed.',
    ]);
}
