<?php
/**
 * REST route handler for /cdcf/v1/community-channel.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery. The theme's functions.php require_once's
 * this file and references cdcf_rest_create_community_channel() in
 * its register_rest_route() call.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_create_community_channel(WP_REST_Request $request) {
    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }
    if (!function_exists('update_field') || !function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    // String messages for non-persistence problems (missing parent posts,
    // missing translations) AND for failed setup writes on the English
    // post. Per-post persistence outcomes for the Community-page loop
    // live in the three arrays below — see #109.
    $errors          = [];
    $updated_posts   = [];
    $unchanged_posts = [];
    $failed_posts    = [];

    // ── 1. Create the English post ──

    $en_post_id = wp_insert_post([
        'post_type'   => 'community_channel',
        'post_status' => 'publish',
        'post_title'  => $request['title'],
    ]);

    if (is_wp_error($en_post_id) || !$en_post_id) {
        return new WP_Error('insert_failed', 'Failed to create English community channel post.', ['status' => 500]);
    }

    pll_set_post_language($en_post_id, 'en');

    // Set ACF fields on English post. update_field() returns false on real
    // persistence failure (#109) — surface in $errors so the client can
    // distinguish a half-populated post from a fully-populated one.
    if (!update_field('channel_description', $request['channel_description'], $en_post_id)) {
        $errors[] = 'Failed to set channel_description on English post.';
    }
    if (!update_field('channel_url', $request['channel_url'], $en_post_id)) {
        $errors[] = 'Failed to set channel_url on English post.';
    }
    if ($request['channel_icon'] && !update_field('channel_icon', $request['channel_icon'], $en_post_id)) {
        $errors[] = 'Failed to set channel_icon on English post.';
    }

    // ── 2. Create translation drafts and enqueue background translations ──

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];
    $translations = ['en' => $en_post_id];
    $queue = null;

    foreach ($target_langs as $lang) {
        // Create draft translation post (content will be filled by the queue worker).
        $trans_id = wp_insert_post([
            'post_type'   => 'community_channel',
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

            foreach ($translations as $lang => $channel_id) {
                $community_page_id = $community_translations[$lang] ?? null;
                if (!$community_page_id) {
                    $errors[] = "{$lang}: No Community page translation found.";
                    continue;
                }

                $current = get_field('channels', $community_page_id, false);
                if (!is_array($current)) {
                    $current = [];
                }

                if (in_array($channel_id, $current)) {
                    // Channel already linked — nothing to write (#109).
                    $unchanged_posts[] = $community_page_id;
                    continue;
                }
                $current[] = $channel_id;
                if (update_field('channels', $current, $community_page_id)) {
                    $updated_posts[] = $community_page_id;
                } else {
                    $failed_posts[] = [
                        'post_id' => $community_page_id,
                        'reason'  => 'update_field returned false',
                    ];
                }
            }
        } else {
            $errors[] = 'Could not find the English Community page.';
        }
    } else {
        $errors[] = 'No Community page found with templates/community.php template.';
    }

    return new WP_REST_Response([
        'success'         => count($failed_posts) === 0 && count($errors) === 0,
        'en_post_id'      => $en_post_id,
        'translations'    => $translations,
        'queue'           => $queue,
        'updated_posts'   => $updated_posts,
        'unchanged_posts' => $unchanged_posts,
        'failed_posts'    => $failed_posts,
        'errors'          => $errors,
    ], 202);
}
