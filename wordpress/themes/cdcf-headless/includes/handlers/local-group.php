<?php
/**
 * REST route handler for /cdcf/v1/local-group.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery. The theme's functions.php require_once's
 * this file and references cdcf_rest_create_local_group() in its
 * register_rest_route() call.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_create_local_group(WP_REST_Request $request) {
    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }
    if (!function_exists('update_field') || !function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $errors = [];

    // ── 1. Create the English post ──

    $en_post_id = wp_insert_post([
        'post_type'   => 'local_group',
        'post_status' => 'publish',
        'post_title'  => $request['title'],
    ]);

    if (is_wp_error($en_post_id) || !$en_post_id) {
        return new WP_Error('insert_failed', 'Failed to create English local group post.', ['status' => 500]);
    }

    pll_set_post_language($en_post_id, 'en');

    // Set ACF fields on English post.
    update_field('group_description', $request['group_description'], $en_post_id);
    update_field('group_url', $request['group_url'], $en_post_id);
    if ($request['group_location']) {
        update_field('group_location', $request['group_location'], $en_post_id);
    }

    // ── 2. Create translation drafts and enqueue background translations ──

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];
    $translations = ['en' => $en_post_id];
    $queue = null;

    foreach ($target_langs as $lang) {
        // Create draft translation post (content will be filled by the queue worker).
        $trans_id = wp_insert_post([
            'post_type'   => 'local_group',
            'post_status' => 'draft',
            'post_title'  => $request['title'],
        ]);

        if (is_wp_error($trans_id) || !$trans_id) {
            $errors[] = "{$lang}: Failed to create translation post.";
            continue;
        }

        pll_set_post_language($trans_id, $lang);

        // Link all translations together.
        $translations[$lang] = $trans_id;
        pll_save_post_translations($translations);

        // Enqueue background translation.
        if (function_exists('cdcf_enqueue_translation')) {
            $queue = cdcf_enqueue_translation($trans_id, $en_post_id, $lang);
        } else {
            wp_schedule_single_event(time(), 'cdcf_async_translate', [$trans_id, $en_post_id, $lang]);
            spawn_cron();
            $queue = 'wp-cron';
        }
    }

    // ── 3. Update Community page relationships ──

    // Find the Community page by its template.
    $community_pages = get_pages([
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'templates/community.php',
        'number'     => 1,
    ]);

    if (!empty($community_pages)) {
        $en_community_id = null;

        // Find the English version of the Community page.
        foreach ($community_pages as $page) {
            $page_lang = pll_get_post_language($page->ID, 'slug');
            if ($page_lang === 'en') {
                $en_community_id = $page->ID;
                break;
            }
        }

        // If the first result wasn't English, get the English translation.
        if (!$en_community_id && !empty($community_pages)) {
            $en_community_id = pll_get_post($community_pages[0]->ID, 'en');
        }

        if ($en_community_id) {
            $community_translations = pll_get_post_translations($en_community_id);

            foreach ($translations as $lang => $group_id) {
                $community_page_id = $community_translations[$lang] ?? null;
                if (!$community_page_id) {
                    $errors[] = "{$lang}: No Community page translation found.";
                    continue;
                }

                $current = get_field('local_groups', $community_page_id, false);
                if (!is_array($current)) {
                    $current = [];
                }

                // Append the new local group ID if not already present.
                if (!in_array($group_id, $current)) {
                    $current[] = $group_id;
                    update_field('local_groups', $current, $community_page_id);
                }
            }
        } else {
            $errors[] = 'Could not find the English Community page.';
        }
    } else {
        $errors[] = 'No Community page found with templates/community.php template.';
    }

    return new WP_REST_Response([
        'success'      => true,
        'en_post_id'   => $en_post_id,
        'translations' => $translations,
        'queue'        => $queue,
        'errors'       => $errors,
    ], 202);
}
