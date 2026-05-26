<?php

/**
 * admin-ajax handler for the "Translate" button in the Languages →
 * AI Translation post meta box. Mirrors /cdcf/v1/translate (REST) but
 * authenticates via cookie + nonce instead of Application Password,
 * and writes translations synchronously rather than enqueuing.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery. Registered against wp_ajax_cdcf_ai_translate
 * from functions.php.
 */

defined('ABSPATH') || exit;

/**
 * Link a freshly-created translation into its source's Polylang group,
 * serialized by a MySQL advisory lock keyed on the source group.
 *
 * Polylang stores a translation group as ONE shared `post_translations`
 * term (a serialized {lang: post_id} map). Linking is a read-modify-write
 * of that term: read the source's current group, add this language, save.
 * The "Translate All" button fans out one concurrent AJAX request per
 * language (Promise.all), and PHP-FPM serves them in parallel workers — so
 * without serialization the requests lost-update each other's group term,
 * leaving one or two languages orphaned in a stale term (the asymmetry we
 * saw on imported team-member photos).
 *
 * GET_LOCK keyed on the source id makes the sibling requests queue ONLY
 * across this ~millisecond critical section; the expensive OpenAI
 * translation that follows still runs fully concurrently, so the
 * parallelism that makes "Translate All" fast is preserved. If the lock
 * can't be acquired (no $wpdb, or timeout) we proceed best-effort rather
 * than fail the translation — the linking is the only at-risk part.
 *
 * @return bool True if the group was saved; false if pll_save_post_translations
 *              reported a persistence failure (caller should not treat the
 *              translation as successfully linked).
 */
function cdcf_ai_translate_link_under_lock(int $source_id, string $target_lang, int $post_id): bool {
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
        // pll_save_post_translations() returns false on a real persistence
        // failure (same contract relied on in handlers/link-translations.php).
        return pll_save_post_translations($translations) !== false;
    } finally {
        if ($locked) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}

function cdcf_ajax_ai_translate(): void {
    check_ajax_referer('cdcf_ai_translate');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions.');
    }

    $post_id     = intval($_POST['post_id']     ?? 0);
    $source_id   = intval($_POST['source_id']   ?? 0);
    $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');

    if (!$source_id || !$target_lang) {
        wp_send_json_error('Missing parameters.');
    }

    // Auto-create translation post if it doesn't exist yet.
    if (!$post_id) {
        $source = get_post($source_id);
        if (!$source) {
            wp_send_json_error('Source post not found.');
        }

        $insert_args = [
            'post_type'   => $source->post_type,
            'post_status' => 'draft',
            'post_title'  => $source->post_title, // will be overwritten by translation
            // Inherit the source author; otherwise wp_insert_post defaults to
            // the user who triggered the translation.
            'post_author' => $source->post_author,
        ];

        // Attachments use 'inherit' status and share the same uploaded file.
        if ($source->post_type === 'attachment') {
            $insert_args['post_status']    = 'inherit';
            $insert_args['post_mime_type'] = $source->post_mime_type;
        }

        $post_id = wp_insert_post($insert_args);
        if (is_wp_error($post_id) || !$post_id) {
            wp_send_json_error('Failed to create translation post.');
        }

        // For attachments, copy the file reference and metadata so both
        // translations point to the same physical file on disk.
        if ($source->post_type === 'attachment') {
            $attached_file = get_post_meta($source_id, '_wp_attached_file', true);
            if ($attached_file) {
                update_post_meta($post_id, '_wp_attached_file', $attached_file);
            }
            $attachment_meta = get_post_meta($source_id, '_wp_attachment_metadata', true);
            if ($attachment_meta) {
                update_post_meta($post_id, '_wp_attachment_metadata', $attachment_meta);
            }
        }

        // Abort if the translation couldn't be linked into the Polylang
        // group — otherwise we'd return success for a post that exists but
        // is orphaned (not reachable as a translation). Delete the
        // just-created post so a failed attempt leaves nothing behind.
        if (!cdcf_ai_translate_link_under_lock($source_id, $target_lang, (int) $post_id)) {
            wp_delete_post((int) $post_id, true);
            wp_send_json_error('Failed to link translation group.');
        }
    }

    $source = get_post($source_id);
    if (!$source) {
        wp_send_json_error('Source post not found.');
    }

    // ── 1. Collect translatable strings ──

    $strings = [];

    if ($source->post_title) {
        $strings['post_title'] = $source->post_title;
    }
    if ($source->post_content) {
        $strings['post_content'] = $source->post_content;
    }
    if ($source->post_excerpt) {
        $strings['post_excerpt'] = $source->post_excerpt;
    }

    // Collect alt text for attachments.
    if ($source->post_type === 'attachment') {
        $alt = get_post_meta($source_id, '_wp_attachment_image_alt', true);
        if ($alt) {
            $strings['alt_text'] = $alt;
        }
    }

    // Collect translatable ACF fields.
    if (function_exists('get_field_objects')) {
        $field_objects = get_field_objects($source_id);
        if ($field_objects) {
            foreach ($field_objects as $field) {
                if (
                    in_array($field['type'], CDCF_TRANSLATABLE_ACF_TYPES, true)
                    && !empty($field['value'])
                    && is_string($field['value'])
                ) {
                    $strings['acf_' . $field['name']] = $field['value'];
                }
            }
        }
    }

    if (empty($strings)) {
        // For attachments with nothing to translate (e.g. a photo with no alt text),
        // the translation post was already created and linked above — just succeed.
        if ($source->post_type === 'attachment') {
            wp_send_json_success([
                'post_id' => $post_id,
                'message' => 'Media duplicated (no translatable text found).',
            ]);
        }
        wp_send_json_error('No translatable content found on the source post.');
    }

    // ── 2. Call OpenAI ──

    $api_key = get_option('cdcf_openai_api_key');
    if (!$api_key) {
        wp_send_json_error('OpenAI API key not configured. Go to Languages → AI Translation.');
    }

    $target_name = CDCF_LOCALE_NAMES[$target_lang] ?? $target_lang;
    $source_lang = pll_default_language('slug');
    $source_name = CDCF_LOCALE_NAMES[$source_lang] ?? $source_lang;

    $result = cdcf_openai_translate($strings, $source_name, $target_name, $api_key);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    // ── 3. Write translations to the target post ──

    $update = [];

    if (isset($result['post_title'])) {
        $update['post_title'] = sanitize_text_field($result['post_title']);
    }
    if (isset($result['post_content'])) {
        $update['post_content'] = wp_kses_post(cdcf_protect_fragment_anchors((string) $result['post_content']));
    }
    if (isset($result['post_excerpt'])) {
        $update['post_excerpt'] = sanitize_textarea_field($result['post_excerpt']);
    }

    if (!empty($update)) {
        $update['ID'] = $post_id;
        wp_update_post($update);
    }

    // Write translated alt text for attachments.
    if (isset($result['alt_text'])) {
        update_post_meta($post_id, '_wp_attachment_image_alt', sanitize_text_field($result['alt_text']));
    }

    // Write translated ACF fields.
    if (function_exists('update_field')) {
        foreach ($result as $key => $value) {
            if (strpos($key, 'acf_') === 0) {
                $field_name = substr($key, 4); // strip 'acf_' prefix
                update_field($field_name, $value, $post_id);
            }
        }
    }

    // ── 4. Copy non-translatable ACF fields from source ──

    if (function_exists('get_field_objects') && function_exists('update_field')) {
        $field_objects = get_field_objects($source_id);
        if ($field_objects) {
            foreach ($field_objects as $field) {
                // Skip fields we already translated.
                if (in_array($field['type'], CDCF_TRANSLATABLE_ACF_TYPES, true)) {
                    continue;
                }
                // Copy config fields (selects, booleans, numbers, urls, etc.)
                // only if the target post doesn't already have a value.
                $existing = get_field($field['name'], $post_id);
                if (empty($existing) && !empty($field['value'])) {
                    update_field($field['name'], $field['value'], $post_id);
                }
            }
        }
    }

    // Auto-publish the translation post if the source is published.
    // Attachments use 'inherit' status, so skip them.
    $source_obj = get_post($source_id);
    if ($source_obj && $source_obj->post_type !== 'attachment') {
        if ($source_obj->post_status === 'publish' && get_post_status($post_id) !== 'publish') {
            wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
        }
    }

    wp_send_json_success([
        'message' => 'Translation complete.',
        'post_id' => $post_id,
    ]);
}
