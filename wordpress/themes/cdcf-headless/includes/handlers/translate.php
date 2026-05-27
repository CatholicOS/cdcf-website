<?php
/**
 * Translation enqueue: shared core + REST route handler for /cdcf/v1/translate.
 *
 * Neither this endpoint nor the admin-ajax meta-box handler
 * (cdcf_ajax_ai_translate) translates inline — both resolve/create the
 * target-language post, link it into the Polylang group, and ENQUEUE the
 * work. The actual OpenAI call happens in cdcf_process_translation()
 * (includes/translation.php), invoked by the Redis-queue worker (or the
 * WP-Cron fallback). Keeping the create+link+enqueue logic here in one
 * function lets both entry points behave identically.
 *
 * Extracted from functions.php so the bodies can be unit-tested with
 * Brain Monkey + Mockery.
 */

defined('ABSPATH') || exit;

/**
 * Link a translation into its source's Polylang group, serialized by a MySQL
 * advisory lock keyed on the source group.
 *
 * Polylang stores a translation group as ONE shared `post_translations` term
 * (a serialized {lang: post_id} map). Linking is a read-modify-write of that
 * term. "Translate All" fans out one concurrent request per language, and
 * PHP-FPM serves them in parallel — without serialization they lost-update
 * each other's term, orphaning a language. GET_LOCK keyed on the source id
 * makes the siblings queue only across this ~millisecond critical section. If
 * the lock can't be acquired ($wpdb missing, or timeout) we proceed
 * best-effort — the linking is the only at-risk part.
 *
 * @return bool True if the group was saved; false if pll_save_post_translations
 *              reported a persistence failure.
 */
function cdcf_translate_link_under_lock(int $source_id, string $target_lang, int $post_id): bool {
    global $wpdb;

    $lock_name = 'cdcf_pll_link_' . $source_id;
    $has_db    = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var');
    $locked    = $has_db
        && (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 10)) === 1;

    try {
        pll_set_post_language($post_id, $target_lang);
        $source_lang  = pll_get_post_language($source_id);
        $translations = pll_get_post_translations($source_id);
        $translations[$source_lang] = $source_id;
        $translations[$target_lang] = $post_id;
        return pll_save_post_translations($translations) !== false;
    } finally {
        if ($locked) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}

/**
 * Resolve-or-create the $target_lang translation of $source_id, link it into
 * the Polylang group (under the lock), and enqueue the translation for the
 * background worker. Shared by the REST endpoint and the admin-ajax meta-box
 * handler so both enqueue identically rather than translating inline.
 *
 * If $post_id is given, it's used as-is (re-translate an existing post). When
 * it's 0 and a translation already exists for the language, that post is
 * reused (no duplicate); otherwise a new draft is created, attachment plumbing
 * copied, and the post linked. A failed link deletes the just-created orphan.
 *
 * @return array{post_id:int,queue:string,errors:array<int,string>}|WP_Error
 */
function cdcf_enqueue_post_translation(int $source_id, string $target_lang, int $post_id = 0) {
    if (!$source_id || $target_lang === '') {
        return new WP_Error('missing_params', 'Missing source_id or target_lang.', ['status' => 400]);
    }
    if (!function_exists('pll_set_post_language')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }

    // Best-effort side-effect failures (attachment plumbing) after the post
    // exists; the primary success signal (post_id + queue) is unchanged (#109).
    $errors = [];

    if (!$post_id) {
        $source = get_post($source_id);
        if (!$source) {
            return new WP_Error('not_found', 'Source post not found.', ['status' => 404]);
        }

        // Reuse an existing translation for this language rather than duplicate.
        $existing_id = function_exists('pll_get_post') ? pll_get_post($source_id, $target_lang) : 0;
        if ($existing_id) {
            $post_id = (int) $existing_id;
        } else {
            $insert_args = [
                'post_type'   => $source->post_type,
                'post_status' => 'draft',
                'post_title'  => $source->post_title,
                // Inherit the source author; otherwise wp_insert_post defaults
                // to the user who triggered the translation.
                'post_author' => $source->post_author,
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
                $attached_file = get_post_meta($source_id, '_wp_attached_file', true);
                if ($attached_file && !update_post_meta($post_id, '_wp_attached_file', $attached_file)) {
                    $errors[] = 'Failed to copy _wp_attached_file to translation post.';
                }
                $attachment_meta = get_post_meta($source_id, '_wp_attachment_metadata', true);
                if ($attachment_meta && !update_post_meta($post_id, '_wp_attachment_metadata', $attachment_meta)) {
                    $errors[] = 'Failed to copy _wp_attachment_metadata to translation post.';
                }
            }

            // Link under the lock; on failure delete the just-created orphan so
            // a failed attempt leaves nothing behind.
            if (!cdcf_translate_link_under_lock($source_id, $target_lang, (int) $post_id)) {
                wp_delete_post((int) $post_id, true);
                return new WP_Error('link_failed', 'Failed to link translation group.', ['status' => 500]);
            }
        }
    } else {
        // Explicit target post supplied: validate it exists and is genuinely
        // the source's translation in this language, so the worker never
        // writes a translation into a stale or arbitrary post.
        if (!get_post($post_id)) {
            return new WP_Error('invalid_post', "Translation post {$post_id} not found.", ['status' => 404]);
        }
        $canonical = function_exists('pll_get_post') ? (int) pll_get_post($source_id, $target_lang) : 0;
        if ($canonical !== $post_id) {
            return new WP_Error('invalid_post', "post_id is not the source's translation for this language.", ['status' => 400]);
        }
    }

    // Enqueue: Redis Queue if available, WP-Cron fallback.
    if (function_exists('cdcf_enqueue_translation')) {
        $queue = cdcf_enqueue_translation($post_id, $source_id, $target_lang);
    } else {
        wp_schedule_single_event(time(), 'cdcf_async_translate', [$post_id, $source_id, $target_lang]);
        spawn_cron();
        $queue = 'wp-cron';
    }

    return ['post_id' => (int) $post_id, 'queue' => $queue, 'errors' => $errors];
}

/**
 * POST /cdcf/v1/translate — enqueue a translation (Application Password auth).
 * Thin wrapper over cdcf_enqueue_post_translation().
 */
function cdcf_rest_translate(WP_REST_Request $request) {
    // Values are already sanitized by the route's args-block sanitize_callbacks
    // (absint / sanitize_text_field); per the cdcf/v1 contract (#111) the
    // handler trusts them rather than re-sanitizing. The admin-ajax caller,
    // which reads raw $_POST, sanitizes on its own side before delegating.
    $result = cdcf_enqueue_post_translation(
        $request['source_id'],
        $request['target_lang'],
        $request['post_id']
    );
    if (is_wp_error($result)) {
        return $result;
    }

    return new WP_REST_Response([
        'post_id' => $result['post_id'],
        'queue'   => $result['queue'],
        'message' => 'Translation queued.',
        'errors'  => $result['errors'],
    ], 202);
}
