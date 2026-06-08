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
 * True if the source (EN) post has submitter meta from the public
 * submission/referral form. Works whether called with the EN post ID
 * or a translation's ID — resolves to source via cdcf_get_source_post_id().
 */
function cdcf_is_public_submission(int $post_id): bool {
    $source_id = cdcf_get_source_post_id($post_id);
    return (bool) (
        get_post_meta($source_id, '_submission_submitter_email', true)
        || get_post_meta($source_id, '_referral_submitter_email', true)
    );
}

/**
 * For each target language (it/es/fr/pt/de):
 *   - Skip if a Polylang translation already exists.
 *   - Otherwise create a draft sibling post, link it via Polylang,
 *     and enqueue a background AI translation job.
 *
 * The existing worker (cdcf_process_translation) will auto-publish
 * each translation once its source post is `publish`.
 *
 * @param int    $en_post_id  The English (source) post ID. MUST be the source,
 *                            not a translation — caller should resolve via
 *                            cdcf_get_source_post_id() first.
 * @param string $post_type   The CPT slug (project | community_project | local_group).
 */
function cdcf_enqueue_translations_for_submission(int $en_post_id, string $post_type): void {
    if (
        !function_exists('pll_set_post_language')
        || !function_exists('pll_save_post_translations')
        || !function_exists('pll_get_post_translations')
    ) {
        error_log("cdcf_enqueue_translations_for_submission: Polylang not active; skipping post {$en_post_id}.");
        return;
    }

    $en_post = get_post($en_post_id);
    if (!$en_post) {
        error_log("cdcf_enqueue_translations_for_submission: Source post {$en_post_id} not found.");
        return;
    }

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];

    // Build the Polylang translation map once; accumulate as we create new siblings.
    // Pre-seeding from the existing map handles partial re-runs where some langs
    // are already linked.
    $translations = pll_get_post_translations($en_post_id);
    $translations['en'] = $en_post_id;

    foreach ($target_langs as $lang) {
        // Skip if a translation is already linked for this language.
        if (!empty($translations[$lang])) {
            continue;
        }

        // Create a draft sibling post; the worker will fill content and auto-publish.
        $trans_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_status' => 'draft',
            'post_title'  => $en_post->post_title,
        ]);

        if (is_wp_error($trans_id) || !$trans_id) {
            error_log("cdcf_enqueue_translations_for_submission: Failed to create {$lang} sibling for post {$en_post_id}.");
            continue;
        }

        pll_set_post_language($trans_id, $lang);

        $translations[$lang] = $trans_id;
        pll_save_post_translations($translations);

        // Enqueue background translation: Redis Queue if available, WP-Cron fallback.
        if (function_exists('cdcf_enqueue_translation')) {
            cdcf_enqueue_translation($trans_id, $en_post_id, $lang);
        } else {
            wp_schedule_single_event(time(), 'cdcf_async_translate', [$trans_id, $en_post_id, $lang]);
            spawn_cron();
        }
    }
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

    cdcf_enqueue_translations_for_submission($source_id, $post->post_type);
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
