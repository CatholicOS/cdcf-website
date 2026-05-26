<?php
/**
 * REST route handler for /cdcf/v1/team-member.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery. The theme's functions.php require_once's
 * this file and references cdcf_rest_create_team_member() in its
 * register_rest_route() call.
 */

if (defined('ABSPATH') === false) {
    return;
}

/**
 * POST /cdcf/v1/team-member — create an English team_member post, queue
 * translations into all configured languages, and (depending on the
 * `council` argument) link the translations into either an About-page
 * relationship field or an academic-collaboration governance field.
 */
function cdcf_rest_create_team_member(WP_REST_Request $request) {
    $allowed_councils = ['team_members', 'ecclesial_council', 'technical_council', 'academic_council'];
    $council = $request['council'];

    if ($council && !in_array($council, $allowed_councils, true)) {
        return new WP_Error('invalid_council', 'council must be one of: ' . implode(', ', $allowed_councils), ['status' => 400]);
    }

    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }
    if (!function_exists('update_field') || !function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    // String messages for non-persistence problems (missing parent posts,
    // missing translations) AND for failed setup writes on the English
    // post. Per-post persistence outcomes for the council relationship
    // loop live in the three arrays below — see #109.
    $errors          = [];
    $updated_posts   = [];
    $unchanged_posts = [];
    $failed_posts    = [];

    // ── 1. Create the English post ──

    $en_post_id = wp_insert_post([
        'post_type'    => 'team_member',
        'post_status'  => 'publish',
        'post_title'   => $request['title'],
        'post_content' => wp_kses_post(cdcf_protect_fragment_anchors((string) $request['content'])),
    ]);

    if (is_wp_error($en_post_id) || !$en_post_id) {
        return new WP_Error('insert_failed', 'Failed to create English team member post.', ['status' => 500]);
    }

    pll_set_post_language($en_post_id, 'en');

    // Set ACF fields on English post. update_field() returns false on real
    // persistence failure (#109) — surface in $errors so the client can
    // distinguish a half-populated post from a fully-populated one.
    if ($request['member_title'] && !update_field('member_title', $request['member_title'], $en_post_id)) {
        $errors[] = 'Failed to set member_title on English post.';
    }
    if ($request['member_role'] && !update_field('member_role', $request['member_role'], $en_post_id)) {
        $errors[] = 'Failed to set member_role on English post.';
    }
    if ($request['member_linkedin_url'] && !update_field('member_linkedin_url', $request['member_linkedin_url'], $en_post_id)) {
        $errors[] = 'Failed to set member_linkedin_url on English post.';
    }
    if ($request['member_github_url'] && !update_field('member_github_url', $request['member_github_url'], $en_post_id)) {
        $errors[] = 'Failed to set member_github_url on English post.';
    }

    // Set featured image. set_post_thumbnail() returns false on failure.
    if ($request['featured_image_id'] && !set_post_thumbnail($en_post_id, $request['featured_image_id'])) {
        $errors[] = 'Failed to set featured image on English post.';
    }

    // ── 2. Create translation drafts and enqueue background translations ──

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];
    $translations = ['en' => $en_post_id];
    $queue = null;

    foreach ($target_langs as $lang) {
        // Create draft translation post (content will be filled by the queue worker).
        $trans_id = wp_insert_post([
            'post_type'   => 'team_member',
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

    // ── 3. Update relationships (skip if no council specified) ──

    if (!$council) {
        // No council — team member is not linked to any About page section.
        // Use this for project-only members (e.g. project leads).
    } elseif ($council === 'academic_council') {
        // Academic council members link to an academic collaboration post's
        // collab_governance field instead of the About page.
        $collab_post_id = $request['collab_post_id'];
        if (!$collab_post_id) {
            $errors[] = 'academic_council requires collab_post_id parameter.';
        } else {
            $collab_translations = pll_get_post_translations($collab_post_id);

            foreach ($translations as $lang => $member_id) {
                $collab_id = $collab_translations[$lang] ?? null;
                if (!$collab_id) {
                    $errors[] = "{$lang}: No academic collaboration translation found.";
                    continue;
                }

                $current = get_field('collab_governance', $collab_id, false);
                if (!is_array($current)) {
                    $current = [];
                }

                if (in_array($member_id, $current)) {
                    // Member already linked — nothing to write (#109).
                    $unchanged_posts[] = $collab_id;
                    continue;
                }
                $current[] = $member_id;
                if (update_field('collab_governance', $current, $collab_id)) {
                    $updated_posts[] = $collab_id;
                } else {
                    $failed_posts[] = [
                        'post_id' => $collab_id,
                        'reason'  => 'update_field returned false',
                    ];
                }
            }
        }
    } else {
        // Other councils link to the About page.
        $about_pages = get_pages([
            'meta_key'   => '_wp_page_template',
            'meta_value' => 'templates/about.php',
            'number'     => 1,
        ]);

        if (!empty($about_pages)) {
            $en_about_id = null;

            foreach ($about_pages as $page) {
                $page_lang = pll_get_post_language($page->ID, 'slug');
                if ($page_lang === 'en') {
                    $en_about_id = $page->ID;
                    break;
                }
            }

            if (!$en_about_id && !empty($about_pages)) {
                $en_about_id = pll_get_post($about_pages[0]->ID, 'en');
            }

            if ($en_about_id) {
                $about_translations = pll_get_post_translations($en_about_id);

                foreach ($translations as $lang => $member_id) {
                    $about_page_id = $about_translations[$lang] ?? null;
                    if (!$about_page_id) {
                        $errors[] = "{$lang}: No About page translation found.";
                        continue;
                    }

                    $current = get_field($council, $about_page_id, false);
                    if (!is_array($current)) {
                        $current = [];
                    }

                    if (in_array($member_id, $current)) {
                        // Member already linked — nothing to write (#109).
                        $unchanged_posts[] = $about_page_id;
                        continue;
                    }
                    $current[] = $member_id;
                    if (update_field($council, $current, $about_page_id)) {
                        $updated_posts[] = $about_page_id;
                    } else {
                        $failed_posts[] = [
                            'post_id' => $about_page_id,
                            'reason'  => 'update_field returned false',
                        ];
                    }
                }
            } else {
                $errors[] = 'Could not find the English About page.';
            }
        } else {
            $errors[] = 'No About page found with templates/about.php template.';
        }
    }

    return new WP_REST_Response([
        'success'         => count($failed_posts) === 0 && count($errors) === 0,
        'en_post_id'      => $en_post_id,
        'translations'    => $translations,
        'council'         => $council,
        'queue'           => $queue,
        'updated_posts'   => $updated_posts,
        'unchanged_posts' => $unchanged_posts,
        'failed_posts'    => $failed_posts,
        'errors'          => $errors,
    ], 202);
}
