<?php

/**
 * Submission lifecycle helpers + transition_post_status callbacks.
 *
 * When an admin publishes a publicly-submitted post (project,
 * community_project, or local_group whose source has submitter meta),
 * this file's hooks:
 *   - Create draft sibling posts in it/es/fr/pt/de
 *   - Link them via Polylang
 *   - Enqueue background AI translations (Redis worker preferred, WP-Cron fallback)
 *
 * Plus an untrash → re-pend hook that re-sets restored submissions
 * back to "pending" so they reappear in the admin dashboard widget and
 * Projects/Local-Groups menu bubble.
 *
 * Extracted from functions.php so the bodies can be unit-tested with
 * Brain Monkey + Mockery.
 */

defined('ABSPATH') || exit;

/**
 * Resolve the source (English) post ID for a given post.
 * If Polylang is active and this post is a translation, return the
 * English original's ID so we can read submission meta from it.
 * Falls back to the given post ID if Polylang is absent or the post
 * is already the source language version.
 */
function cdcf_get_source_post_id(int $post_id): int {
    if (!function_exists('pll_get_post')) {
        return $post_id;
    }
    $source_id = pll_get_post($post_id, 'en');
    return $source_id ? (int) $source_id : $post_id;
}

/**
 * True if any post in this post's Polylang translation group carries
 * submitter meta from the public submission/referral form.
 *
 * The submitter meta is written ONCE on the originally-submitted post
 * — which is whatever language the human submitted in. A Spanish
 * submission carries `_referral_submitter_email` on the ES post; the
 * auto-translated EN/IT/FR/PT/DE siblings have no meta. So the helper
 * walks the whole Polylang group and returns true if ANY sibling has
 * the meta — not just the EN one — so the publish hook can recognize
 * the submission regardless of which language sibling fired it.
 *
 * Production occurrence 2026-06-16: community_project 1534 was submitted
 * in Spanish; the worker-promoted EN sibling 1556 failed the old
 * EN-walk check and was never appended to /projects's
 * community_projects field.
 *
 * Falls back to a single-post check when Polylang isn't active or the
 * post is in no group.
 */
function cdcf_is_public_submission(int $post_id): bool {
    $candidate_ids = [$post_id];
    if (function_exists('pll_get_post_translations')) {
        $group = pll_get_post_translations($post_id);
        if (is_array($group) && !empty($group)) {
            $candidate_ids = array_map('intval', array_values($group));
        }
    }

    foreach ($candidate_ids as $id) {
        if ($id <= 0) {
            continue;
        }
        if (
            get_post_meta($id, '_submission_submitter_email', true)
            || get_post_meta($id, '_referral_submitter_email', true)
        ) {
            return true;
        }
    }
    return false;
}

/**
 * Create + Polylang-link the missing it/es/fr/pt/de sibling drafts of an
 * source post, then enqueue an AI translation job per newly-created
 * sibling. The existing worker (cdcf_process_translation) will auto-
 * publish each translation once its source post is `publish`.
 *
 * Source language is derived from the post itself via
 * pll_get_post_language() rather than assumed to be English. A Spanish
 * (or Italian, French, etc.) public submission produces translations
 * for the OTHER 5 languages — including English. Observed on production
 * 2026-06-16: community_project 1534 ("Enciclopedia Católica") was
 * submitted in Spanish; the old hardcoded target list ['it','es','fr',
 * 'pt','de'] created siblings for IT/FR/PT/DE only (silently dropping
 * EN since it wasn't in the list, and silently skipping ES since the
 * post already carried that language) and the atomic save was handed
 * {en: 1534, ...} — the source post mis-keyed as the EN translation,
 * which Polylang rejected → empty group on all six posts.
 *
 * Group save is **atomic** — exactly one pll_save_post_translations()
 * call after every sibling has been created. The earlier shape (one
 * save per iteration, accumulating the map) lost-updated the Polylang
 * translation group on production: Interior Castle App publish on
 * 2026-06-08 created all 6 siblings with correct per-post languages but
 * the group came out as {en} only, orphaning IT/ES/FR/PT/DE — the same
 * lost-update race the /translate-all endpoint was created to fix for
 * the meta-box Translate-All fan-out. Mirroring its atomic shape here
 * makes the publish-flow race-resistant by construction.
 *
 * If the atomic save fails, every just-created draft is force-deleted
 * so a failed call leaves no orphans behind (same shape as /translate-all).
 *
 * @param int    $source_post_id  The source post ID, in any of the 6 configured
 *                                Polylang languages. MUST be the source, not a
 *                                translation — caller should resolve via
 *                                cdcf_get_source_post_id() first.
 * @param string $post_type       The CPT slug (project | community_project | local_group).
 */
function cdcf_enqueue_translations_for_submission(int $source_post_id, string $post_type): void {
    if (
        !function_exists('pll_set_post_language')
        || !function_exists('pll_save_post_translations')
        || !function_exists('pll_get_post_translations')
        || !function_exists('pll_get_post_language')
        || !function_exists('pll_languages_list')
    ) {
        error_log("cdcf_enqueue_translations_for_submission: Polylang not active; skipping post {$source_post_id}.");
        return;
    }

    $source_post = get_post($source_post_id);
    if (!$source_post) {
        error_log("cdcf_enqueue_translations_for_submission: Source post {$source_post_id} not found.");
        return;
    }

    // Source language is whatever Polylang has assigned to this post —
    // ES for a Spanish submission, IT for an Italian submission, etc.
    // Fall back to the Polylang site default if the post has no
    // language (e.g. submission flow forgot to call pll_set_post_language).
    $source_lang = pll_get_post_language($source_post_id, 'slug') ?: '';
    if (!$source_lang && function_exists('pll_default_language')) {
        $source_lang = pll_default_language('slug') ?: '';
    }
    if (!$source_lang) {
        error_log("cdcf_enqueue_translations_for_submission: Source post {$source_post_id} has no Polylang language and no default; aborting.");
        return;
    }

    // Target = every configured language EXCEPT the source's own.
    $all_langs = pll_languages_list(['fields' => 'slug']);
    if (!is_array($all_langs) || empty($all_langs)) {
        error_log("cdcf_enqueue_translations_for_submission: pll_languages_list returned no languages; aborting.");
        return;
    }
    $target_langs = array_values(array_filter(
        $all_langs,
        static fn($l) => $l !== $source_lang
    ));
    if (empty($target_langs)) {
        error_log("cdcf_enqueue_translations_for_submission: Only the source language is configured; nothing to translate.");
        return;
    }

    // Pre-seed the map from any existing group (handles partial re-runs
    // where some langs are already linked from a previous attempt).
    $translations = pll_get_post_translations($source_post_id);
    $translations[$source_lang] = $source_post_id;

    error_log(sprintf(
        'cdcf_enqueue_translations_for_submission: ENTER post_id=%d post_type=%s source_lang=%s targets=[%s] pre_seed_group=%s',
        $source_post_id,
        $post_type,
        $source_lang,
        implode(',', $target_langs),
        cdcf_format_lang_map($translations)
    ));

    // Phase 0: ensure attachment translations exist for the source's
    // featured_image. Without this, post-translation workers would
    // either render the source-lang image (with source-lang alt-text /
    // SEO regression) or get a null featuredImage from WPGraphQL on
    // other-language posts. Running before Phase 1 means by the time
    // the worker handles each post translation, pll_get_post(thumbnail,
    // lang) already returns the matching-language attachment sibling.
    $source_thumbnail_id = (int) get_post_thumbnail_id($source_post_id);
    if ($source_thumbnail_id > 0) {
        cdcf_ensure_attachment_translations($source_thumbnail_id, $target_langs);
    }

    // Phase 1: create draft siblings + per-post language assignment.
    // Group save is intentionally NOT called here — see file-level
    // docblock for the lost-update race rationale.
    $newly_created  = [];
    $already_linked = [];
    foreach ($target_langs as $lang) {
        if (!empty($translations[$lang])) {
            $already_linked[$lang] = (int) $translations[$lang];
            continue;
        }

        $trans_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_status' => 'draft',
            'post_title'  => $source_post->post_title,
        ]);

        if (is_wp_error($trans_id) || !$trans_id) {
            error_log("cdcf_enqueue_translations_for_submission: Failed to create {$lang} sibling for post {$source_post_id}.");
            continue;
        }

        pll_set_post_language($trans_id, $lang);
        $translations[$lang] = $trans_id;
        $newly_created[$lang] = $trans_id;
    }

    error_log(sprintf(
        'cdcf_enqueue_translations_for_submission: PHASE_1_DONE post_id=%d newly_created=%s already_linked=%s',
        $source_post_id,
        cdcf_format_lang_map($newly_created),
        cdcf_format_lang_map($already_linked)
    ));

    if (empty($newly_created)) {
        // Nothing new to link or enqueue.
        error_log(sprintf(
            'cdcf_enqueue_translations_for_submission: NO_OP post_id=%d (all target langs already pre-seeded); exiting without group save.',
            $source_post_id
        ));
        return;
    }

    // Phase 2: one atomic group save with the full {lang => post_id} map.
    $save_result = pll_save_post_translations($translations);
    if ($save_result === false) {
        error_log(sprintf(
            'cdcf_enqueue_translations_for_submission: PHASE_2_FAIL post_id=%d pll_save_post_translations returned false; rolling back %d draft(s): %s',
            $source_post_id,
            count($newly_created),
            cdcf_format_lang_map($newly_created)
        ));
        foreach ($newly_created as $trans_id) {
            wp_delete_post($trans_id, true);
        }
        return;
    }

    error_log(sprintf(
        'cdcf_enqueue_translations_for_submission: PHASE_2_OK post_id=%d atomic group save succeeded; final_group=%s',
        $source_post_id,
        cdcf_format_lang_map($translations)
    ));

    // Phase 3: enqueue translation jobs for the newly-created siblings only.
    // (Existing siblings from the pre-seed already had their content done.)
    $queue_name = function_exists('cdcf_enqueue_translation') ? 'redis' : 'wp-cron';
    foreach ($newly_created as $lang => $trans_id) {
        if (function_exists('cdcf_enqueue_translation')) {
            cdcf_enqueue_translation($trans_id, $source_post_id, $lang);
        } else {
            wp_schedule_single_event(time(), 'cdcf_async_translate', [$trans_id, $source_post_id, $lang]);
            spawn_cron();
        }
    }

    error_log(sprintf(
        'cdcf_enqueue_translations_for_submission: PHASE_3_DONE post_id=%d queued %d job(s) via %s: %s',
        $source_post_id,
        count($newly_created),
        $queue_name,
        cdcf_format_lang_map($newly_created)
    ));
}

/**
 * Format a {lang => post_id} map for compact inclusion in error_log
 * lines. Returns "{lang:id, lang:id, ...}" or "{}" if empty.
 * Used by the diagnostic logs in cdcf_enqueue_translations_for_submission.
 */
function cdcf_format_lang_map(array $map): string {
    if (empty($map)) {
        return '{}';
    }
    $parts = [];
    foreach ($map as $lang => $id) {
        $parts[] = $lang . ':' . (int) $id;
    }
    return '{' . implode(', ', $parts) . '}';
}

/**
 * Synchronously ensure attachment-translation siblings exist for each
 * target language of a source attachment. Mirrors the atomic shape of
 * cdcf_enqueue_translations_for_submission (Phase 1 create + per-post
 * language, Phase 2 ONE atomic pll_save_post_translations) but for
 * attachments and inline (no Redis queue): each missing sibling is
 * created with OpenAI-translated title/caption/description/alt-text
 * before this function returns.
 *
 * Called from cdcf_enqueue_translations_for_submission's Phase 0 so
 * the post-translation worker can later resolve the correct-language
 * featured-image via pll_get_post() without falling back to the source
 * attachment (which would render with source-language alt-text — an
 * SEO + a11y regression — and may be filtered out by WPGraphQL on
 * non-source-language posts entirely).
 *
 * No new file is uploaded: each new sibling is a fresh `wp_posts` row
 * pointing at the source's underlying `_wp_attached_file` (and its
 * `_wp_attachment_metadata`). Only the WP-side language-dependent
 * fields (title, post_excerpt as caption, post_content as description,
 * `_wp_attachment_image_alt` meta) are translated.
 *
 * @param int   $source_attachment_id  Source attachment post ID.
 * @param array $target_langs          Locale slugs (e.g. ['it','es','fr','pt','de']).
 * @return array  {lang => attachment_id} including the source. Empty
 *                array if Polylang missing or source isn't an attachment.
 */
function cdcf_ensure_attachment_translations(int $source_attachment_id, array $target_langs): array {
    if (
        !function_exists('pll_set_post_language')
        || !function_exists('pll_save_post_translations')
        || !function_exists('pll_get_post_translations')
        || !function_exists('pll_get_post_language')
    ) {
        error_log("cdcf_ensure_attachment_translations: Polylang not active; skipping attachment {$source_attachment_id}.");
        return [];
    }

    $source = get_post($source_attachment_id);
    if (!$source || $source->post_type !== 'attachment') {
        error_log("cdcf_ensure_attachment_translations: post {$source_attachment_id} is not an attachment; skipping.");
        return [];
    }

    $source_lang = pll_get_post_language($source_attachment_id, 'slug') ?: 'en';

    $translations = pll_get_post_translations($source_attachment_id);
    $translations[$source_lang] = $source_attachment_id;

    error_log(sprintf(
        'cdcf_ensure_attachment_translations: ENTER source_id=%d source_lang=%s pre_seed_group=%s',
        $source_attachment_id,
        $source_lang,
        cdcf_format_lang_map($translations)
    ));

    // Phase 1: per-lang create + per-post language. Group save deferred to Phase 2.
    $newly_created = [];
    $already_linked = [];
    foreach ($target_langs as $lang) {
        if ($lang === $source_lang) {
            continue;
        }
        if (!empty($translations[$lang])) {
            $already_linked[$lang] = (int) $translations[$lang];
            continue;
        }

        $new_id = cdcf_create_attachment_translation($source, $source_lang, $lang);
        if (!$new_id) {
            // Helper already logged the specific failure.
            continue;
        }
        pll_set_post_language($new_id, $lang);
        $translations[$lang] = $new_id;
        $newly_created[$lang] = $new_id;
    }

    error_log(sprintf(
        'cdcf_ensure_attachment_translations: PHASE_1_DONE source_id=%d newly_created=%s already_linked=%s',
        $source_attachment_id,
        cdcf_format_lang_map($newly_created),
        cdcf_format_lang_map($already_linked)
    ));

    if (empty($newly_created)) {
        return $translations;
    }

    // Phase 2: one atomic group save. Mirrors PR #203's shape for posts.
    $save_result = pll_save_post_translations($translations);
    if ($save_result === false) {
        error_log(sprintf(
            'cdcf_ensure_attachment_translations: PHASE_2_FAIL source_id=%d pll_save_post_translations returned false; rolling back %d attachment(s): %s',
            $source_attachment_id,
            count($newly_created),
            cdcf_format_lang_map($newly_created)
        ));
        foreach ($newly_created as $id) {
            wp_delete_post($id, true);
        }
        return [];
    }

    error_log(sprintf(
        'cdcf_ensure_attachment_translations: PHASE_2_OK source_id=%d atomic group save succeeded; final_group=%s',
        $source_attachment_id,
        cdcf_format_lang_map($translations)
    ));

    return $translations;
}

/**
 * Create a single attachment-translation sibling.
 *
 * The new sibling shares the source's underlying file (_wp_attached_file
 * + _wp_attachment_metadata). Its title/caption/description/alt-text are
 * OpenAI-translated when CDCF's OpenAI helper is available; otherwise
 * the source values are copied verbatim (so callers always get a usable
 * attachment back rather than a half-formed one).
 *
 * @return int|null  New attachment post ID, or null on wp_insert_post failure.
 */
function cdcf_create_attachment_translation(object $source, string $source_lang, string $target_lang): ?int {
    // Collect translatable strings.
    $strings = array_filter([
        'title'       => $source->post_title,
        'caption'     => $source->post_excerpt,
        'description' => $source->post_content,
        'alt_text'    => (string) get_post_meta($source->ID, '_wp_attachment_image_alt', true),
    ], static fn($v) => $v !== '');

    // OpenAI-translate the strings if possible. On any failure we fall
    // back to the source values rather than skipping the attachment —
    // a sibling with source-language metadata is still better than no
    // sibling at all (the latter triggers Phase 2 to skip linking,
    // which puts us back in the original "EN fallback on non-EN post"
    // regression we're trying to fix).
    $translated = $strings;
    if (!empty($strings) && function_exists('cdcf_openai_translate')) {
        $api_key = get_option('cdcf_openai_api_key');
        if ($api_key) {
            $source_name = defined('CDCF_LOCALE_NAMES')
                ? (CDCF_LOCALE_NAMES[$source_lang] ?? $source_lang)
                : $source_lang;
            $target_name = defined('CDCF_LOCALE_NAMES')
                ? (CDCF_LOCALE_NAMES[$target_lang] ?? $target_lang)
                : $target_lang;
            $result = cdcf_openai_translate($strings, $source_name, $target_name, $api_key);
            if (!is_wp_error($result) && is_array($result)) {
                $translated = array_merge($strings, $result);
            } else {
                $msg = is_wp_error($result) ? $result->get_error_message() : 'non-array response';
                error_log(sprintf(
                    'cdcf_create_attachment_translation: OpenAI error for attachment %d -> %s (%s); using source values.',
                    $source->ID,
                    $target_lang,
                    $msg
                ));
            }
        }
    }

    $new_id = wp_insert_post([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => $source->post_mime_type,
        'post_title'     => $translated['title']       ?? $source->post_title,
        'post_excerpt'   => $translated['caption']     ?? $source->post_excerpt,
        'post_content'   => $translated['description'] ?? $source->post_content,
        'guid'           => $source->guid,
    ]);

    if (is_wp_error($new_id) || !$new_id) {
        $msg = is_wp_error($new_id) ? $new_id->get_error_message() : 'returned 0';
        error_log("cdcf_create_attachment_translation: wp_insert_post failed for attachment {$source->ID} -> {$target_lang}: {$msg}");
        return null;
    }

    // Point the sibling at the source's underlying file + metadata. No
    // new bytes uploaded — Polylang attachment translations are a
    // metadata-only concern; the file remains shared via _wp_attached_file.
    $attached_file = get_post_meta($source->ID, '_wp_attached_file', true);
    if ($attached_file) {
        update_post_meta($new_id, '_wp_attached_file', $attached_file);
    }
    $metadata = wp_get_attachment_metadata($source->ID);
    if (is_array($metadata)) {
        wp_update_attachment_metadata($new_id, $metadata);
    }

    // Language-specific alt text (lives in _wp_attachment_image_alt, not
    // a post-table column).
    if (!empty($translated['alt_text'])) {
        update_post_meta($new_id, '_wp_attachment_image_alt', $translated['alt_text']);
    }

    return (int) $new_id;
}

/**
 * transition_post_status hook: when a publicly-submitted post
 * (project or local_group) is restored from trash, WordPress sets it
 * to "draft". This handler re-sets it to "pending" so it reappears
 * in the admin dashboard widget and menu bubble.
 *
 * Registered with priority 10 / 3 args from functions.php.
 */
function cdcf_repend_submission_on_untrash($new_status, $old_status, $post): void {
    if ($new_status !== 'draft' || $old_status !== 'trash') {
        return;
    }

    if (!in_array($post->post_type, ['project', 'local_group'], true)) {
        return;
    }

    // Only re-pend posts that came from the public submission form.
    // Check the source (English) post's meta for translations.
    $source_id = cdcf_get_source_post_id($post->ID);
    $has_submitter = get_post_meta($source_id, '_submission_submitter_email', true)
                  || get_post_meta($source_id, '_referral_submitter_email', true);
    if (!$has_submitter) {
        return;
    }

    // Unhook to avoid recursion, then update.
    remove_action('transition_post_status', __FUNCTION__);
    wp_update_post(['ID' => $post->ID, 'post_status' => 'pending']);
}

/**
 * transition_post_status hook: fires when an admin publishes a
 * public-submission post. Priority 20 from functions.php so it runs
 * after all priority-10 hooks (sitemap revalidation and the
 * untrash/re-pend hook).
 *
 * Only acts on the English source post — when the worker promotes
 * a translation sibling to publish, this hook fires again, but
 * there's nothing more to enqueue at that point.
 *
 * The actual enqueue work is deferred to `shutdown` rather than run
 * synchronously here. Running it inline puts our nested wp_insert_post
 * + pll_save_post_translations calls inside Polylang's own save_post
 * chain for the source post (and each freshly-inserted draft), where
 * the multi-post group save silently fails to persist. Observed on
 * production 2026-06-16: FamilyGraph submission 1381 was published
 * with EN/IT/ES/FR/PT/DE siblings created and worker-translated, but
 * the Polylang translation group came out empty on all six posts and
 * Phase 0's attachment translation siblings were never created. The
 * identical pll_save_post_translations call from outside the save
 * chain (via /cdcf/v1/link-translations) persisted on the first try.
 */
function cdcf_enqueue_translations_on_publish($new_status, $old_status, $post): void {
    if ($new_status === $old_status) {
        return;
    }
    if ($new_status !== 'publish') {
        return;
    }
    if (!in_array($post->post_type, ['project', 'community_project', 'local_group'], true)) {
        return;
    }

    // Only process the English source post — when the worker promotes a
    // translation sibling to `publish`, this hook fires again, but there
    // is nothing more to enqueue at that point.
    $source_id = cdcf_get_source_post_id($post->ID);
    if ($source_id !== $post->ID) {
        return;
    }

    if (!cdcf_is_public_submission($post->ID)) {
        return;
    }

    $post_type = $post->post_type;
    add_action('shutdown', static function () use ($source_id, $post_type): void {
        cdcf_enqueue_translations_for_submission($source_id, $post_type);
    });
}

/**
 * transition_post_status hook: when a public-referral submission
 * (community_project or local_group) is published — including each
 * auto-translated sibling promoted to publish by the worker — append
 * it to the matching-language parent page's relationship field.
 *
 * Why this exists: the admin CREATE endpoints (/community-channel,
 * /local-group, /academic-collaboration) link inline at creation time.
 * The PUBLIC REFERRAL endpoints (refer-community-project,
 * refer-local-group) skip that step because the referred post is
 * created `pending` and only surfaces once an admin approves it.
 * Without this hook, an approved referral is published but never
 * appears on the frontend until an admin manually edits the parent
 * page and adds it to the relationship field.
 *
 * Unlike cdcf_enqueue_translations_on_publish (which is EN-source-only
 * since the translation worker handles the siblings), this hook fires
 * once PER language sibling — each language's parent page needs its own
 * same-language post linked. Gating on cdcf_is_public_submission()
 * filters out the admin-create flow, which already linked inline.
 *
 * Idempotent: skips if the post is already in the field.
 *
 * Registered with priority 25 from functions.php — after the
 * priority-20 enqueue-translations hook.
 */
function cdcf_link_referral_on_publish($new_status, $old_status, $post): void {
    if ($new_status !== 'publish' || $old_status === 'publish') {
        return;
    }

    $map = [
        'community_project' => ['template' => 'templates/projects.php',  'field' => 'community_projects'],
        'local_group'       => ['template' => 'templates/community.php', 'field' => 'local_groups'],
    ];
    if (!isset($map[$post->post_type])) {
        return;
    }

    if (!cdcf_is_public_submission($post->ID)) {
        return;
    }

    if (
        !function_exists('pll_get_post_language')
        || !function_exists('pll_get_post_translations')
    ) {
        return;
    }

    $post_lang = pll_get_post_language($post->ID, 'slug');
    if (!$post_lang) {
        return;
    }

    $template = $map[$post->post_type]['template'];
    $field    = $map[$post->post_type]['field'];

    // Same discovery shape the create endpoints use: locate a page
    // with the right template, then walk the Polylang translation
    // group to find the same-language sibling.
    $candidate_pages = get_pages([
        'meta_key'   => '_wp_page_template',
        'meta_value' => $template,
        'number'     => 1,
    ]);
    if (empty($candidate_pages)) {
        return;
    }
    $page_translations = pll_get_post_translations($candidate_pages[0]->ID);
    $parent_id = $page_translations[$post_lang] ?? null;
    if (!$parent_id) {
        return;
    }

    $current = get_field($field, $parent_id, false);
    if (!is_array($current)) {
        $current = [];
    }
    $current = array_map('intval', $current);
    if (in_array($post->ID, $current, true)) {
        return;
    }
    $current[] = $post->ID;
    update_field($field, $current, $parent_id);
}
