<?php

/**
 * admin-ajax handler for the "Translate" / "Translate All" buttons in the
 * Languages → AI Translation meta box.
 *
 * Cookie+nonce-authenticated counterpart to /cdcf/v1/translate. Like that
 * endpoint, it ENQUEUES the translation for the background worker (via the
 * shared cdcf_enqueue_post_translation() in handlers/translate.php) rather
 * than translating synchronously in the request. "Translate All" fans out one
 * request per language; enqueuing makes each return immediately, so a long
 * article no longer fires five concurrent OpenAI calls (which rate-limited /
 * timed out). The worker (cdcf_process_translation) does the OpenAI work
 * asynchronously and sequentially. Registered against wp_ajax_cdcf_ai_translate
 * from functions.php.
 */

defined('ABSPATH') || exit;

function cdcf_ajax_ai_translate(): void {
    check_ajax_referer('cdcf_ai_translate');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions.');
    }

    $result = cdcf_enqueue_post_translation(
        intval($_POST['source_id'] ?? 0),
        sanitize_text_field($_POST['target_lang'] ?? ''),
        intval($_POST['post_id'] ?? 0)
    );

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success([
        'message' => 'Translation queued.',
        'post_id' => $result['post_id'],
        'queue'   => $result['queue'],
    ]);
}
