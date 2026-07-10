<?php
/**
 * Atomic "Translate All languages" enqueue path.
 *
 * Why this exists: cdcf_enqueue_post_translation() does a READ-MODIFY-WRITE of
 * the Polylang translation group (read the source's group, append this lang,
 * write the group back). When the meta-box "Translate All" button fires five
 * concurrent enqueue requests, those reads can each return a stale snapshot of
 * the group — even with the MySQL advisory lock in cdcf_translate_link_under_lock()
 * — because pll_get_post_translations() reads through Polylang's in-process /
 * term-cache layer. Symptom: two of five enqueues "win" and end up linked;
 * the rest leave orphaned translation posts that the worker still translates,
 * but they never get linked into the source's Polylang group.
 *
 * Issues observed: media 1385 (it/pt missing), media 1409 (fr/es/pt missing).
 *
 * Fix: collapse the five reads/writes into ONE request. Create or reuse all
 * five target-language draft posts, then call pll_save_post_translations()
 * exactly once with the full {source_lang→source_id, …, target_lang→post_id}
 * map. There's no concurrent read-modify-write to lose data to.
 *
 * Browser side: the meta-box "Translate All" button is reworked to POST to
 * the admin-ajax handler in this file instead of fanning out five
 * cdcf_ai_translate requests. Per-language buttons keep their existing
 * single-request path (one writer = no race).
 */

defined('ABSPATH') || exit;

/**
 * Create or reuse the target-language draft for $source. Returns the post_id
 * plus any non-fatal attachment-plumbing errors. Does NOT touch the Polylang
 * translation group — that's done atomically by cdcf_enqueue_all_translations()
 * once all per-language drafts exist.
 *
 * @param WP_Post $source       The already-loaded source post.
 * @param string  $target_lang  Polylang language slug.
 * @return array{post_id:int,reused:bool,errors:array<int,string>}|WP_Error
 */
function cdcf_create_or_reuse_translation_draft($source, string $target_lang) {
    $errors = [];

    // Reuse if a translation already exists for this language. We DO NOT set
    // pll_set_post_language here — Polylang already has it.
    $existing_id = function_exists('pll_get_post') ? (int) pll_get_post($source->ID, $target_lang) : 0;
    if ($existing_id) {
        return ['post_id' => $existing_id, 'reused' => true, 'errors' => $errors];
    }

    $insert_args = [
        'post_type'   => $source->post_type,
        'post_status' => 'draft',
        'post_title'  => $source->post_title,
        // Inherit the source author; otherwise wp_insert_post defaults to the
        // user who triggered the translation.
        'post_author' => $source->post_author,
    ];

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
        return new WP_Error('insert_failed', "Failed to create {$target_lang} translation draft.", ['status' => 500]);
    }

    if ($source->post_type === 'attachment') {
        $attached_file = get_post_meta($source->ID, '_wp_attached_file', true);
        if ($attached_file && !update_post_meta((int) $post_id, '_wp_attached_file', $attached_file)) {
            $errors[] = "[{$target_lang}] failed to copy _wp_attached_file.";
        }
        $attachment_meta = get_post_meta($source->ID, '_wp_attachment_metadata', true);
        if ($attachment_meta && !update_post_meta((int) $post_id, '_wp_attachment_metadata', $attachment_meta)) {
            $errors[] = "[{$target_lang}] failed to copy _wp_attachment_metadata.";
        }
    }

    // Tag the new post with its language now (independent of the group save).
    pll_set_post_language((int) $post_id, $target_lang);

    // Children translated before this parent existed were created parentless —
    // adopt them now that the parent translation exists.
    cdcf_reparent_orphaned_child_translations($source, (int) $post_id, $target_lang);

    return ['post_id' => (int) $post_id, 'reused' => false, 'errors' => $errors];
}

/**
 * Enqueue translations for ALL non-source Polylang languages of $source_id in
 * one atomic operation: create-or-reuse all target drafts, save the full
 * translation group once, enqueue each job, mark each post as enqueued.
 *
 * Returns the per-language post_id map plus the list of langs that were
 * actually queued (vs reused-but-not-re-queued — re-queue still happens for
 * reused posts so the user can re-translate; this matches the existing
 * single-language path's behaviour).
 *
 * @return array{
 *     source_id:int,
 *     source_lang:string,
 *     post_ids:array<string,int>,
 *     queued:array<int,string>,
 *     queue:string,
 *     errors:array<int,string>
 * }|WP_Error
 */
function cdcf_enqueue_all_translations(int $source_id) {
    if (!$source_id) {
        return new WP_Error('missing_source', 'source_id is required.', ['status' => 400]);
    }
    if (!function_exists('pll_set_post_language') || !function_exists('pll_languages_list')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }

    $source = get_post($source_id);
    if (!$source) {
        return new WP_Error('not_found', "Source post {$source_id} not found.", ['status' => 404]);
    }

    $source_lang = pll_get_post_language($source_id);
    if (!$source_lang) {
        $source_lang = pll_default_language('slug');
    }
    if (!$source_lang) {
        return new WP_Error('no_source_lang', 'Source post has no language and no Polylang default is set.', ['status' => 500]);
    }

    $all_langs    = pll_languages_list(['fields' => 'slug']);
    $target_langs = array_values(array_filter($all_langs, static fn($l) => $l !== $source_lang));
    if (empty($target_langs)) {
        return new WP_Error('no_targets', 'No target languages configured.', ['status' => 400]);
    }

    // Build the post-id map and a starting translation group. Seed from the
    // existing group so any languages the source is ALREADY linked to (e.g.
    // older translations made via wp-admin) stay linked when we re-save.
    $existing_group = pll_get_post_translations($source_id);
    $post_ids       = []; // [lang => post_id], excluding the source
    $errors         = [];
    $created_new    = []; // [post_id => true] — tracks new inserts for rollback

    foreach ($target_langs as $target_lang) {
        $result = cdcf_create_or_reuse_translation_draft($source, $target_lang);
        if (is_wp_error($result)) {
            // A single language's create failure shouldn't block the others.
            $errors[] = "[{$target_lang}] " . $result->get_error_message();
            continue;
        }
        $post_ids[$target_lang] = $result['post_id'];
        if (!$result['reused']) {
            $created_new[$result['post_id']] = true;
        }
        foreach ($result['errors'] as $e) {
            $errors[] = $e;
        }
    }

    if (empty($post_ids)) {
        return new WP_Error('all_creates_failed', 'No translation drafts could be created.', ['status' => 500]);
    }

    // ATOMIC LINK: build the full translation map and save it once. Start from
    // $existing_group (preserves unrelated pre-existing links) and overlay the
    // source + freshly-created/reused posts.
    $translations = $existing_group;
    $translations[$source_lang] = $source_id;
    foreach ($post_ids as $lang => $pid) {
        $translations[$lang] = $pid;
    }

    if (pll_save_post_translations($translations) === false) {
        // Rollback: delete only the posts we created in this call. Reused
        // ones (translations from a prior session) are left alone. We only
        // need the keys (post ids); the value is just a presence marker, so
        // iterate via array_keys() to keep static analysis from flagging an
        // unused $value placeholder.
        foreach (array_keys($created_new) as $pid) {
            wp_delete_post((int) $pid, true);
        }
        return new WP_Error('link_failed', 'Failed to save translation group.', ['status' => 500]);
    }

    // Enqueue each translation job and mark the target post as queued so the
    // meta-box UI can poll for completion.
    $queued = [];
    $queue_name = function_exists('cdcf_enqueue_translation') ? 'redis' : 'wp-cron';
    foreach ($post_ids as $target_lang => $pid) {
        if (function_exists('cdcf_enqueue_translation')) {
            cdcf_enqueue_translation($pid, $source_id, $target_lang);
        } else {
            wp_schedule_single_event(time(), 'cdcf_async_translate', [$pid, $source_id, $target_lang]);
            spawn_cron();
        }
        if (function_exists('cdcf_translation_status_set_enqueued')) {
            cdcf_translation_status_set_enqueued((int) $pid);
        }
        $queued[] = $target_lang;
    }

    return [
        'source_id'   => $source_id,
        'source_lang' => $source_lang,
        'post_ids'    => $post_ids,
        'queued'      => $queued,
        'queue'       => $queue_name,
        'errors'      => $errors,
    ];
}

/**
 * admin-ajax handler for the meta-box "Translate All" button. Cookie + nonce
 * authenticated, mirrors cdcf_ajax_ai_translate's permission gate.
 */
function cdcf_ajax_ai_translate_all(): void {
    check_ajax_referer('cdcf_ai_translate');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions.');
    }

    $result = cdcf_enqueue_all_translations((int) ($_POST['source_id'] ?? 0));
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success([
        'message'  => 'Translations queued.',
        'post_ids' => $result['post_ids'],
        'queued'   => $result['queued'],
        'queue'    => $result['queue'],
        'errors'   => $result['errors'],
    ]);
}

/**
 * POST /cdcf/v1/translate-all — Application-Password / Zitadel-bearer auth.
 * Thin wrapper for the Python CLI and other server-side callers.
 */
function cdcf_rest_translate_all(WP_REST_Request $request) {
    // source_id is already absint'd by the args block (#111 contract).
    $result = cdcf_enqueue_all_translations((int) $request['source_id']);
    if (is_wp_error($result)) {
        return $result;
    }
    return new WP_REST_Response([
        'source_id'   => $result['source_id'],
        'source_lang' => $result['source_lang'],
        'post_ids'    => $result['post_ids'],
        'queued'      => $result['queued'],
        'queue'       => $result['queue'],
        'message'     => 'Translations queued.',
        'errors'      => $result['errors'],
    ], 202);
}
