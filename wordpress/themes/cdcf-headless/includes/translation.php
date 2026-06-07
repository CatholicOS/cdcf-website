<?php

/**
 * Translation pipeline (background WP-Cron processor + OpenAI client).
 *
 * Three layers:
 *   - cdcf_chunk_html_content() — splits oversized HTML at block-element
 *     boundaries so each chunk fits under CDCF_TRANSLATION_CHUNK_CHARS.
 *   - cdcf_openai_translate() — bounded-retry wrapper around the actual
 *     HTTP call (_cdcf_openai_translate_attempt).
 *   - cdcf_process_translation() — orchestrator: pulls translatable
 *     strings from a source post, chunks oversized fields, calls the
 *     OpenAI client, writes back translated title/content/excerpt/ACF
 *     fields, copies non-translatable ACF fields + featured image, and
 *     auto-publishes if the source is published. Registered against the
 *     cdcf_async_translate WP-Cron action below.
 *
 * Depends on two constants defined in functions.php and required to be
 * loaded before this file:
 *   - CDCF_TRANSLATABLE_ACF_TYPES — ACF field types eligible for AI translation
 *   - CDCF_LOCALE_NAMES — locale slug → human-readable language name
 */

defined('ABSPATH') || exit;

/**
 * Soft cap on characters per OpenAI request. A single ~30K-char post_content
 * routinely takes >120s on gpt-4o-mini, hitting the nginx/PHP-FPM upstream
 * timeout. Chunking at ~5K chars per request keeps each call well under that
 * window.
 */
const CDCF_TRANSLATION_CHUNK_CHARS = 5000;

/**
 * Split a chunk of HTML at top-level block-element boundaries so each piece
 * fits under $max_chars. Boundaries are placed AFTER closing tags of common
 * block elements (p, h1–h6, ul, ol, table, blockquote, pre, div, section,
 * article, figure, details, dl) — never mid-element. Returns [$html] when no
 * boundary is found or content is already under the cap.
 */
function cdcf_chunk_html_content(string $html, int $max_chars = CDCF_TRANSLATION_CHUNK_CHARS): array {
    $html = trim($html);
    if ($html === '' || mb_strlen($html) <= $max_chars) {
        return [$html];
    }

    $delimiter = "\x00CDCF_CHUNK_BOUNDARY\x00";
    $boundary  = '#(</(?:p|h[1-6]|ul|ol|table|blockquote|pre|div|section|article|figure|details|dl)>\s*)#i';
    $marked    = preg_replace($boundary, '$1' . $delimiter, $html);
    $parts     = array_values(array_filter(
        explode($delimiter, (string) $marked),
        static fn($p) => trim($p) !== ''
    ));

    if (count($parts) <= 1) {
        // No splittable boundaries (e.g. one huge <table> or plain text).
        return [$html];
    }

    $chunks  = [];
    $current = '';
    foreach ($parts as $part) {
        if ($current !== '' && mb_strlen($current) + mb_strlen($part) > $max_chars) {
            $chunks[] = $current;
            $current  = $part;
        } else {
            $current .= $part;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }
    return $chunks;
}

/**
 * @return true|WP_Error  true on success (or no-op), WP_Error on failure so
 *                        callers (Redis Queue job, REST handler) can retry.
 */
function cdcf_process_translation($post_id, $source_id, $target_lang) {
    // Flip the UI badge from "Queued" to "Processing" so polling reflects
    // that the worker has actually picked up the job. Guarded so the unit
    // tests that don't load the status helper don't blow up.
    if (function_exists('cdcf_translation_status_set_processing')) {
        cdcf_translation_status_set_processing((int) $post_id);
    }

    $source = get_post($source_id);
    if (!$source) {
        error_log("cdcf_process_translation: Source post {$source_id} not found.");
        if (function_exists('cdcf_translation_status_set_failed')) {
            cdcf_translation_status_set_failed((int) $post_id, "Source post {$source_id} not found.");
        }
        return new WP_Error('source_missing', "Source post {$source_id} not found.");
    }

    // Collect translatable strings.
    $strings = [];
    if ($source->post_title)   $strings['post_title']   = $source->post_title;
    if ($source->post_content) $strings['post_content'] = $source->post_content;
    if ($source->post_excerpt) $strings['post_excerpt'] = $source->post_excerpt;

    if ($source->post_type === 'attachment') {
        $alt = get_post_meta($source_id, '_wp_attachment_image_alt', true);
        if ($alt) $strings['alt_text'] = $alt;
    }

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
        error_log("cdcf_process_translation: No translatable content for post {$source_id}.");
        if (function_exists('cdcf_translation_status_set_completed')) {
            cdcf_translation_status_set_completed((int) $post_id);
        }
        return true; // No-op success: nothing to translate, not a failure to retry.
    }

    // Call OpenAI.
    $api_key = get_option('cdcf_openai_api_key');
    if (!$api_key) {
        error_log('cdcf_process_translation: OpenAI API key not configured.');
        if (function_exists('cdcf_translation_status_set_failed')) {
            cdcf_translation_status_set_failed((int) $post_id, 'OpenAI API key not configured.');
        }
        return new WP_Error('no_api_key', 'OpenAI API key not configured.');
    }

    $target_name = CDCF_LOCALE_NAMES[$target_lang] ?? $target_lang;
    // Read the source language from the post itself so authors can draft in
    // any locale (e.g. de → en/it/es/fr/pt). Falls back to the site default
    // for posts not yet linked into a Polylang group.
    $source_lang = pll_get_post_language($source_id) ?: pll_default_language('slug');
    $source_name = CDCF_LOCALE_NAMES[$source_lang] ?? $source_lang;

    // Pull oversized fields out of the batch and chunk them. A single ~30K
    // post_content takes >120s on gpt-4o-mini and trips the nginx/PHP-FPM
    // upstream timeout. Each chunk gets its own (short) OpenAI call;
    // translated chunks are reassembled below.
    $chunked_fields = [];
    foreach ($strings as $key => $value) {
        if (mb_strlen((string) $value) <= CDCF_TRANSLATION_CHUNK_CHARS) {
            continue;
        }
        $chunks = cdcf_chunk_html_content((string) $value);
        if (count($chunks) > 1) {
            $chunked_fields[$key] = $chunks;
            unset($strings[$key]);
        }
    }

    // Translate the (now smaller) batch in one call.
    $result = !empty($strings)
        ? cdcf_openai_translate($strings, $source_name, $target_name, $api_key)
        : [];
    if (is_wp_error($result)) {
        error_log('cdcf_process_translation: OpenAI error – ' . $result->get_error_message());
        if (function_exists('cdcf_translation_status_set_failed')) {
            cdcf_translation_status_set_failed((int) $post_id, 'OpenAI error – ' . $result->get_error_message());
        }
        return $result; // Surface to caller so retry logic engages.
    }

    // Translate each oversized field's chunks individually and reassemble.
    // Pass the tail of the previous translated chunk as context so the model
    // keeps terminology, register, and style consistent across chunk
    // boundaries (cdcf_openai_translate handles the trim and the prompt).
    foreach ($chunked_fields as $key => $chunks) {
        $translated_parts = [];
        $total = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $context = $i > 0 && !empty($translated_parts) ? end($translated_parts) : '';
            $chunk_result = cdcf_openai_translate([$key => $chunk], $source_name, $target_name, $api_key, $context);
            if (is_wp_error($chunk_result)) {
                error_log(sprintf(
                    'cdcf_process_translation: chunk %d/%d failed for post %d %s (%s) – %s',
                    $i + 1,
                    $total,
                    $post_id,
                    $key,
                    $target_lang,
                    $chunk_result->get_error_message()
                ));
                if (function_exists('cdcf_translation_status_set_failed')) {
                    cdcf_translation_status_set_failed(
                        (int) $post_id,
                        sprintf('Chunk %d/%d (%s): %s', $i + 1, $total, $key, $chunk_result->get_error_message())
                    );
                }
                return $chunk_result;
            }
            // OpenAI may return a JSON object missing the expected key (model
            // hallucination, schema drift). Falling back to the untranslated
            // chunk preserves output structure but mixes source-lang content
            // into the translation — log so this is investigable rather than
            // silently shipping bad translations.
            if (!isset($chunk_result[$key])) {
                error_log(sprintf(
                    'cdcf_process_translation: chunk %d/%d for post %d %s (%s) returned no "%s" key; falling back to untranslated chunk. Response: %s',
                    $i + 1,
                    $total,
                    $post_id,
                    $key,
                    $target_lang,
                    $key,
                    mb_substr((string) wp_json_encode($chunk_result), 0, 500)
                ));
            }
            $translated_parts[] = $chunk_result[$key] ?? $chunk;
        }
        $result[$key] = implode('', $translated_parts);
    }

    // Write translations.
    $update = [];
    if (isset($result['post_title']))   $update['post_title']   = sanitize_text_field($result['post_title']);
    if (isset($result['post_content'])) $update['post_content'] = wp_kses_post(cdcf_protect_fragment_anchors((string) $result['post_content']));
    if (isset($result['post_excerpt'])) $update['post_excerpt'] = sanitize_textarea_field($result['post_excerpt']);

    if (!empty($update)) {
        $update['ID'] = $post_id;
        wp_update_post($update);
    }

    if (isset($result['alt_text'])) {
        update_post_meta($post_id, '_wp_attachment_image_alt', sanitize_text_field($result['alt_text']));
    }

    if (function_exists('update_field')) {
        foreach ($result as $key => $value) {
            if (strpos($key, 'acf_') === 0) {
                update_field(substr($key, 4), $value, $post_id);
            }
        }
    }

    // Copy non-translatable ACF fields from source.
    if (function_exists('get_field_objects') && function_exists('update_field')) {
        $field_objects = get_field_objects($source_id);
        if ($field_objects) {
            foreach ($field_objects as $field) {
                if (in_array($field['type'], CDCF_TRANSLATABLE_ACF_TYPES, true)) continue;
                $existing = get_field($field['name'], $post_id);
                if (empty($existing) && !empty($field['value'])) {
                    update_field($field['name'], $field['value'], $post_id);
                }
            }
        }
    }

    // Copy featured image, using the translated media ID for this language.
    $source_thumbnail_id = get_post_thumbnail_id($source_id);
    if ($source_thumbnail_id && !get_post_thumbnail_id($post_id)) {
        $lang_image_id = function_exists('pll_get_post') ? pll_get_post($source_thumbnail_id, $target_lang) : 0;
        set_post_thumbnail($post_id, $lang_image_id ?: $source_thumbnail_id);
    }

    // Auto-publish if source is published.
    if ($source->post_type !== 'attachment' && $source->post_status === 'publish' && get_post_status($post_id) !== 'publish') {
        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
    }

    error_log("cdcf_process_translation: Translation complete for post {$post_id} ({$target_lang}).");
    if (function_exists('cdcf_translation_status_set_completed')) {
        cdcf_translation_status_set_completed((int) $post_id);
    }
    return true;
}

/**
 * Send an array of strings to OpenAI for translation, with bounded retries
 * for transient upstream failures (HTTP 5xx, malformed JSON, network blips).
 *
 * Hard failures (auth, rate limit, 4xx) short-circuit immediately.
 *
 * @param  array  $strings     ['key' => 'source text', ...]
 * @param  string $source_lang Human-readable source language name.
 * @param  string $target_lang Human-readable target language name.
 * @param  string $api_key     OpenAI API key.
 * @param  string $context     Optional preceding translated text to maintain
 *                             terminology / register consistency across chunks.
 * @return array|WP_Error      ['key' => 'translated text', ...] or WP_Error.
 */
function cdcf_openai_translate($strings, $source_lang, $target_lang, $api_key, $context = '') {
    $max_attempts = 3;
    $backoff      = [2, 5]; // seconds before retry attempts 2 and 3

    $last_error = null;
    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        $result = _cdcf_openai_translate_attempt($strings, $source_lang, $target_lang, $api_key, $context);
        if (!is_wp_error($result)) {
            return $result;
        }

        $last_error = $result;
        $code       = $result->get_error_code();
        $msg        = $result->get_error_message();
        $data       = $result->get_error_data();
        $status     = is_array($data) && isset($data['status']) ? (int) $data['status'] : null;

        // Retry on transient upstream conditions only. Auth / 4xx is permanent.
        // Status comes from $error_data attached by _cdcf_openai_translate_attempt
        // (numeric, exact) rather than substring-matching the error message.
        $is_retryable = in_array($code, ['openai_parse', 'openai_empty'], true)
            || ($code === 'openai_error' && $status !== null && (
                $status === 408
                || $status === 429
                || ($status >= 500 && $status < 600)
            ))
            || ($code === 'http_request_failed' && stripos($msg, 'cURL error 28') !== false);

        if (!$is_retryable || $attempt === $max_attempts) {
            return $result;
        }

        $delay = $backoff[$attempt - 1] ?? 5;
        error_log(sprintf(
            'cdcf_openai_translate: attempt %d/%d failed (%s: %s); retrying in %ds',
            $attempt,
            $max_attempts,
            $code,
            $msg,
            $delay
        ));
        sleep($delay);
    }

    return $last_error;
}

/**
 * One attempt at the OpenAI translation call. Caller handles retry policy.
 * Internal — do not call directly; use cdcf_openai_translate().
 */
function _cdcf_openai_translate_attempt($strings, $source_lang, $target_lang, $api_key, $context = '') {
    $model = get_option('cdcf_openai_model', 'gpt-4o-mini');

    $system_prompt = <<<PROMPT
You are a professional translator for the Catholic Digital Commons Foundation website.
Translate all values from {$source_lang} to {$target_lang}.
Preserve all HTML tags, attributes, and structure exactly.
Do not translate proper nouns, brand names, URLs, or code.
"Catholic Digital Commons Foundation" (CDCF) is the organization's official name and must NEVER be translated.
Use formal register appropriate for an institutional Catholic organization.
Return ONLY a valid JSON object with the same keys and translated values.
Do not wrap the response in markdown code fences.
PROMPT;

    $user_message = wp_json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Build the message list. Keep $system_prompt static so dynamic / model-
    // sourced text never gets promoted to system-level instructions; instead
    // pass the previous translated chunk as a separate user message.
    $messages = [
        ['role' => 'system', 'content' => $system_prompt],
    ];

    if ($context !== '') {
        // Trim very long context — model only needs a paragraph or two of
        // tail to lock in terminology and tone for the next chunk.
        $context_excerpt = mb_substr($context, max(0, mb_strlen($context) - 1500));
        $messages[] = [
            'role'    => 'user',
            'content' => "The following is the END of the previous translated portion of the SAME document. Do not re-translate or include it. Use it ONLY to maintain consistent terminology, register, and style:\n---\n{$context_excerpt}\n---",
        ];
    }

    $messages[] = ['role' => 'user', 'content' => $user_message];

    $body = [
        'model'       => $model,
        'temperature' => 0.3,
        'messages'    => $messages,
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 120,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);

    if ($code !== 200) {
        $err = json_decode($raw, true);
        $msg = $err['error']['message'] ?? "HTTP {$code}";
        // Attach the numeric HTTP status so cdcf_openai_translate's retry
        // policy can decide retryability without substring-matching the message.
        return new WP_Error('openai_error', 'OpenAI API error: ' . $msg, ['status' => (int) $code]);
    }

    $data = json_decode($raw, true);
    $content = $data['choices'][0]['message']['content'] ?? '';

    if (!$content) {
        return new WP_Error('openai_empty', 'OpenAI returned an empty response.');
    }

    // Strip markdown code fences if the model wraps the JSON anyway.
    $content = trim($content);
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);

    $translated = json_decode($content, true);

    if (!is_array($translated)) {
        return new WP_Error(
            'openai_parse',
            'Could not parse OpenAI response as JSON. Raw: ' . mb_substr($content, 0, 200)
        );
    }

    return $translated;
}
