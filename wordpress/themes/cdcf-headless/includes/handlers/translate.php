<?php
/**
 * REST route handler for /cdcf/v1/translate.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery. The theme's functions.php require_once's
 * this file and references cdcf_rest_translate() in its
 * register_rest_route() call.
 *
 * Note: this handler only enqueues the translation work — the actual
 * OpenAI HTTP call lives in cdcf_openai_translate() and is invoked
 * from the queue worker, not from this endpoint.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_translate(WP_REST_Request $request) {
    $post_id     = intval($request['post_id'] ?? 0);
    $source_id   = intval($request['source_id'] ?? 0);
    $target_lang = sanitize_text_field($request['target_lang'] ?? '');

    if (!$source_id || !$target_lang) {
        return new WP_Error('missing_params', 'Missing source_id or target_lang.', ['status' => 400]);
    }

    if (!function_exists('pll_set_post_language')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }

    // String messages for any best-effort side-effect writes that fail
    // after the translation post has been created. The primary success
    // signal (post_id + enqueued queue) is unchanged — see #109.
    $errors = [];

    // Resolve or auto-create translation post.
    if (!$post_id) {
        $source = get_post($source_id);
        if (!$source) {
            return new WP_Error('not_found', 'Source post not found.', ['status' => 404]);
        }

        // Check if a translation already exists for this language.
        $existing_id = function_exists('pll_get_post') ? pll_get_post($source_id, $target_lang) : 0;
        if ($existing_id) {
            $post_id = $existing_id;
        } else {
            $insert_args = [
                'post_type'   => $source->post_type,
                'post_status' => 'draft',
                'post_title'  => $source->post_title,
            ];

            // Propagate parent: use the parent's translation in the target language.
            if ($source->post_parent) {
                $parent_translation = pll_get_post($source->post_parent, $target_lang);
                if ($parent_translation) {
                    $insert_args['post_parent'] = $parent_translation;
                }
            }

            if ($source->post_type === 'attachment') {
                $insert_args['post_status']    = 'inherit';
                $insert_args['post_mime_type'] = $source->post_mime_type;
            }

            $post_id = wp_insert_post($insert_args);
            if (is_wp_error($post_id) || !$post_id) {
                return new WP_Error('insert_failed', 'Failed to create translation post.', ['status' => 500]);
            }

            if ($source->post_type === 'attachment') {
                // Best-effort copy of attachment plumbing meta to the
                // new translation post. update_post_meta() returns false
                // on real persistence failure — surface in errors[] so
                // the client can detect that the translation post will
                // be missing its source file pointer (#109).
                $attached_file = get_post_meta($source_id, '_wp_attached_file', true);
                if ($attached_file && !update_post_meta($post_id, '_wp_attached_file', $attached_file)) {
                    $errors[] = 'Failed to copy _wp_attached_file to translation post.';
                }
                $attachment_meta = get_post_meta($source_id, '_wp_attachment_metadata', true);
                if ($attachment_meta && !update_post_meta($post_id, '_wp_attachment_metadata', $attachment_meta)) {
                    $errors[] = 'Failed to copy _wp_attachment_metadata to translation post.';
                }
            }

            pll_set_post_language($post_id, $target_lang);
            $source_lang = pll_get_post_language($source_id);
            $translations = pll_get_post_translations($source_id);
            $translations[$source_lang] = $source_id;
            $translations[$target_lang] = $post_id;
            pll_save_post_translations($translations);
        }
    }

    // Enqueue translation: Redis Queue if available, WP Cron fallback.
    if (function_exists('cdcf_enqueue_translation')) {
        $queue = cdcf_enqueue_translation($post_id, $source_id, $target_lang);
    } else {
        wp_schedule_single_event(time(), 'cdcf_async_translate', [$post_id, $source_id, $target_lang]);
        spawn_cron();
        $queue = 'wp-cron';
    }

    return new WP_REST_Response([
        'post_id' => $post_id,
        'queue'   => $queue,
        'message' => 'Translation queued.',
        'errors'  => $errors,
    ], 202);
}
