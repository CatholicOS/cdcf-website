<?php
/**
 * CDCF Headless Theme — functions.php
 *
 * Registers CPTs, ACF field groups, Polylang config,
 * CORS for GraphQL, and preview URL hooks.
 */

// ─── SVG Upload Support ─────────────────────────────────────────────

add_filter('upload_mimes', function (array $mimes): array {
    $mimes['svg']  = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext === 'svg' || $ext === 'svgz') {
        $data['type'] = 'image/svg+xml';
        $data['ext']  = $ext;
    }
    return $data;
}, 10, 4);

// ─── SMTP Mail Configuration ────────────────────────────────────────
//
// Sends all wp_mail() through an authenticated SMTP server instead of
// PHP's mail(). Define these constants in wp-config.php:
//
//   define('SMTP_HOST', 'mail.catholicdigitalcommons.org');
//   define('SMTP_PORT', 465);
//   define('SMTP_SECURE', 'ssl');          // 'ssl' for 465, 'tls' for 587
//   define('SMTP_USER', 'webmaster@catholicdigitalcommons.org');
//   define('SMTP_PASS', '...');
//   define('SMTP_FROM', 'webmaster@catholicdigitalcommons.org');
//   define('SMTP_FROM_NAME', 'Catholic Digital Commons Foundation');

add_action('phpmailer_init', function (PHPMailer\PHPMailer\PHPMailer $phpmailer) {
    if (!defined('SMTP_HOST') || !SMTP_HOST) {
        return; // No SMTP configured — fall back to PHP mail().
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = SMTP_HOST;
    $phpmailer->Port       = defined('SMTP_PORT') ? (int) SMTP_PORT : 465;
    $phpmailer->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl';
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = defined('SMTP_USER') ? SMTP_USER : '';
    $phpmailer->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
});

add_filter('wp_mail_from', function (string $from): string {
    if (defined('SMTP_FROM') && SMTP_FROM) {
        return SMTP_FROM;
    }
    return $from;
});

add_filter('wp_mail_from_name', function (string $name): string {
    if (defined('SMTP_FROM_NAME') && SMTP_FROM_NAME) {
        return SMTP_FROM_NAME;
    }
    return $name;
});

// ─── Custom Post Types ───────────────────────────────────────────────

add_action('init', function () {
    // Project
    register_post_type('project', [
        'labels' => [
            'name'          => __('Projects', 'cdcf-headless'),
            'singular_name' => __('Project', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'project',
        'graphql_plural_name' => 'projects',
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'menu_icon'    => 'dashicons-portfolio',
        'has_archive'  => false,
        'rewrite'      => ['slug' => 'projects'],
    ]);

    // Team Member
    register_post_type('team_member', [
        'labels' => [
            'name'          => __('Team Members', 'cdcf-headless'),
            'singular_name' => __('Team Member', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'teamMember',
        'graphql_plural_name' => 'teamMembers',
        'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'menu_icon'    => 'dashicons-groups',
        'has_archive'  => false,
    ]);

    // Sponsor
    register_post_type('sponsor', [
        'labels' => [
            'name'          => __('Sponsors', 'cdcf-headless'),
            'singular_name' => __('Sponsor', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'sponsor',
        'graphql_plural_name' => 'sponsors',
        'supports'     => ['title', 'thumbnail', 'custom-fields'],
        'menu_icon'    => 'dashicons-star-filled',
        'has_archive'  => false,
    ]);

    // Community Channel
    register_post_type('community_channel', [
        'labels' => [
            'name'          => __('Community Channels', 'cdcf-headless'),
            'singular_name' => __('Community Channel', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'communityChannel',
        'graphql_plural_name' => 'communityChannels',
        'supports'     => ['title', 'custom-fields'],
        'menu_icon'    => 'dashicons-networking',
        'has_archive'  => false,
    ]);

    // Local Group
    register_post_type('local_group', [
        'labels' => [
            'name'          => __('Local Groups', 'cdcf-headless'),
            'singular_name' => __('Local Group', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'localGroup',
        'graphql_plural_name' => 'localGroups',
        'supports'     => ['title', 'custom-fields'],
        'menu_icon'    => 'dashicons-location',
        'has_archive'  => false,
    ]);

    // Stat Item
    register_post_type('stat_item', [
        'labels' => [
            'name'          => __('Stat Items', 'cdcf-headless'),
            'singular_name' => __('Stat Item', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'statItem',
        'graphql_plural_name' => 'statItems',
        'supports'     => ['title', 'custom-fields'],
        'menu_icon'    => 'dashicons-chart-bar',
        'has_archive'  => false,
    ]);
});

// ─── Register ACF fields as REST-writable post meta ─────────────────
//
// ACF Free exposes fields for reading via show_in_rest but doesn't
// register them with register_post_meta(), so WordPress rejects
// writes through the REST API. This hook bridges that gap.

add_action('acf/init', function () {
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return;
    }

    foreach (acf_get_field_groups() as $group) {
        // Determine which post types this group applies to.
        $post_types = [];
        foreach ($group['location'] ?? [] as $rules) {
            foreach ($rules as $rule) {
                if ($rule['param'] === 'post_type' && $rule['operator'] === '==') {
                    $post_types[] = $rule['value'];
                }
                if ($rule['param'] === 'page_template' && $rule['operator'] === '==') {
                    $post_types[] = 'page';
                }
            }
        }
        $post_types = array_unique($post_types);
        if (empty($post_types)) {
            continue;
        }

        // Only register simple scalar field types. Complex types
        // (relationship, image) store serialized arrays that can't
        // be represented as string meta and break page saves.
        $skip_types = ['relationship', 'image', 'file', 'gallery', 'repeater', 'flexible_content', 'group'];

        foreach (acf_get_fields($group['key']) as $field) {
            if (empty($field['show_in_rest'])) {
                continue;
            }
            if (in_array($field['type'], $skip_types, true)) {
                continue;
            }
            foreach ($post_types as $pt) {
                register_post_meta($pt, $field['name'], [
                    'type'          => 'string',
                    'single'        => true,
                    'show_in_rest'  => true,
                    'auth_callback' => function () {
                        return current_user_can('edit_posts');
                    },
                ]);
            }
        }
    }
}, 20); // priority 20 so it runs after field groups are registered

// ─── REST endpoint for ACF relationship fields ──────────────────────
//
// ACF relationship fields store serialized arrays in post meta and
// cannot be written through the standard WP REST API. This custom
// endpoint allows reading and updating relationship fields via REST.
//
// GET  /wp-json/cdcf/v1/relationship?post_id=5&field=technical_council
// POST /wp-json/cdcf/v1/relationship  { post_id: 5, field: "technical_council", value: [255, 256] }

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/relationship', [
        [
            'methods'             => 'GET',
            'callback'            => 'cdcf_rest_get_relationship',
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
            'args' => [
                'post_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'field'   => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'cdcf_rest_update_relationship',
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
            'args' => [
                'post_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'field'   => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'value'   => ['required' => true, 'type' => 'array'],
            ],
        ],
    ]);
});

function cdcf_rest_get_relationship(WP_REST_Request $request) {
    $post_id = $request['post_id'];
    $field   = $request['field'];

    if (!function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $acf_field = acf_get_field($field);
    if (!$acf_field || $acf_field['type'] !== 'relationship') {
        return new WP_Error('invalid_field', 'Field is not a relationship field.', ['status' => 400]);
    }

    $value = get_field($field, $post_id, false); // raw IDs
    return rest_ensure_response(['post_id' => $post_id, 'field' => $field, 'value' => $value ?: []]);
}

function cdcf_rest_update_relationship(WP_REST_Request $request) {
    $post_id = $request['post_id'];
    $field   = $request['field'];
    $value   = $request['value'];

    if (!function_exists('update_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $acf_field = acf_get_field($field);
    if (!$acf_field || $acf_field['type'] !== 'relationship') {
        return new WP_Error('invalid_field', 'Field is not a relationship field.', ['status' => 400]);
    }

    // Sanitize to array of integers.
    $value = array_map('absint', array_filter($value));
    update_field($field, $value, $post_id);

    return rest_ensure_response(['post_id' => $post_id, 'field' => $field, 'value' => $value, 'updated' => true]);
}

// ─── REST endpoint for creating a team member with translations ──────
//
// Creates an English team_member post, translates it to all configured
// languages via OpenAI, and appends each translation to the correct
// language version of the About page's relationship field (council).
//
// POST /wp-json/cdcf/v1/team-member (Application Password auth)

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/team-member', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_create_team_member',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'title'              => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'content'            => ['required' => true,  'type' => 'string'],
            'member_title'       => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'member_role'        => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'member_linkedin_url' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'member_github_url'  => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'council'            => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'featured_image_id'  => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
        ],
    ]);
});

function cdcf_rest_create_team_member(WP_REST_Request $request) {
    $allowed_councils = ['team_members', 'ecclesial_council', 'technical_council'];
    $council = $request['council'];

    if (!in_array($council, $allowed_councils, true)) {
        return new WP_Error('invalid_council', 'council must be one of: ' . implode(', ', $allowed_councils), ['status' => 400]);
    }

    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }
    if (!function_exists('update_field') || !function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $api_key = get_option('cdcf_openai_api_key');
    if (!$api_key) {
        return new WP_Error('no_api_key', 'OpenAI API key not configured.', ['status' => 500]);
    }

    set_time_limit(300);

    $errors = [];

    // ── 1. Create the English post ──

    $en_post_id = wp_insert_post([
        'post_type'    => 'team_member',
        'post_status'  => 'publish',
        'post_title'   => $request['title'],
        'post_content' => wp_kses_post($request['content']),
    ]);

    if (is_wp_error($en_post_id) || !$en_post_id) {
        return new WP_Error('insert_failed', 'Failed to create English team member post.', ['status' => 500]);
    }

    pll_set_post_language($en_post_id, 'en');

    // Set ACF fields on English post.
    if ($request['member_title']) {
        update_field('member_title', $request['member_title'], $en_post_id);
    }
    if ($request['member_role']) {
        update_field('member_role', $request['member_role'], $en_post_id);
    }
    if ($request['member_linkedin_url']) {
        update_field('member_linkedin_url', $request['member_linkedin_url'], $en_post_id);
    }
    if ($request['member_github_url']) {
        update_field('member_github_url', $request['member_github_url'], $en_post_id);
    }

    // Set featured image.
    if ($request['featured_image_id']) {
        set_post_thumbnail($en_post_id, $request['featured_image_id']);
    }

    // ── 2. Translate to other languages ──

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];
    $translations = ['en' => $en_post_id];

    foreach ($target_langs as $lang) {
        try {
            // Create draft translation post.
            $trans_id = wp_insert_post([
                'post_type'    => 'team_member',
                'post_status'  => 'draft',
                'post_title'   => $request['title'],
                'post_content' => wp_kses_post($request['content']),
            ]);

            if (is_wp_error($trans_id) || !$trans_id) {
                $errors[] = "{$lang}: Failed to create translation post.";
                continue;
            }

            pll_set_post_language($trans_id, $lang);

            // Link all translations together.
            $translations[$lang] = $trans_id;
            pll_save_post_translations($translations);

            // Collect translatable strings.
            $strings = ['post_title' => $request['title']];
            if ($request['content']) {
                $strings['post_content'] = $request['content'];
            }
            if ($request['member_title']) {
                $strings['acf_member_title'] = $request['member_title'];
            }
            if ($request['member_role']) {
                $strings['acf_member_role'] = $request['member_role'];
            }

            // Call OpenAI translation.
            $target_name = CDCF_LOCALE_NAMES[$lang] ?? $lang;
            $result = cdcf_openai_translate($strings, 'English', $target_name, $api_key);

            if (is_wp_error($result)) {
                $errors[] = "{$lang}: " . $result->get_error_message();
                // Still publish with untranslated content.
                wp_update_post(['ID' => $trans_id, 'post_status' => 'publish']);
                // Copy non-translatable fields.
                if ($request['member_linkedin_url']) {
                    update_field('member_linkedin_url', $request['member_linkedin_url'], $trans_id);
                }
                if ($request['member_github_url']) {
                    update_field('member_github_url', $request['member_github_url'], $trans_id);
                }
                if ($request['featured_image_id']) {
                    set_post_thumbnail($trans_id, $request['featured_image_id']);
                }
                continue;
            }

            // Write translated core fields.
            $update = ['ID' => $trans_id];
            if (isset($result['post_title'])) {
                $update['post_title'] = sanitize_text_field($result['post_title']);
            }
            if (isset($result['post_content'])) {
                $update['post_content'] = wp_kses_post($result['post_content']);
            }
            $update['post_status'] = 'publish';
            wp_update_post($update);

            // Write translated ACF fields.
            if (isset($result['acf_member_title'])) {
                update_field('member_title', $result['acf_member_title'], $trans_id);
            }
            if (isset($result['acf_member_role'])) {
                update_field('member_role', $result['acf_member_role'], $trans_id);
            }

            // Copy non-translatable fields (URLs).
            if ($request['member_linkedin_url']) {
                update_field('member_linkedin_url', $request['member_linkedin_url'], $trans_id);
            }
            if ($request['member_github_url']) {
                update_field('member_github_url', $request['member_github_url'], $trans_id);
            }

            // Copy featured image.
            if ($request['featured_image_id']) {
                set_post_thumbnail($trans_id, $request['featured_image_id']);
            }
        } catch (Exception $e) {
            $errors[] = "{$lang}: " . $e->getMessage();
        }
    }

    // ── 3. Update About page relationships ──

    // Find the English About page by its template.
    $about_pages = get_pages([
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'templates/about.php',
        'number'     => 1,
    ]);

    if (!empty($about_pages)) {
        $en_about_id = null;

        // Find the English version of the About page.
        foreach ($about_pages as $page) {
            $page_lang = pll_get_post_language($page->ID, 'slug');
            if ($page_lang === 'en') {
                $en_about_id = $page->ID;
                break;
            }
        }

        // If the first result wasn't English, get the English translation.
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

                // Append the new team member ID if not already present.
                if (!in_array($member_id, $current)) {
                    $current[] = $member_id;
                    update_field($council, $current, $about_page_id);
                }
            }
        } else {
            $errors[] = 'Could not find the English About page.';
        }
    } else {
        $errors[] = 'No About page found with templates/about.php template.';
    }

    return rest_ensure_response([
        'success'      => true,
        'en_post_id'   => $en_post_id,
        'translations' => $translations,
        'council'      => $council,
        'errors'       => $errors,
    ]);
}

// ─── REST endpoint for creating a community channel with translations ─
//
// Creates an English community_channel post, translates it to all configured
// languages via OpenAI, and appends each translation to the correct
// language version of the Community page's channels relationship field.
//
// POST /wp-json/cdcf/v1/community-channel (Application Password auth)

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/community-channel', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_create_community_channel',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'title'               => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'channel_description' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field'],
            'channel_url'         => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'esc_url_raw'],
            'channel_icon'        => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
        ],
    ]);
});

function cdcf_rest_create_community_channel(WP_REST_Request $request) {
    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }
    if (!function_exists('update_field') || !function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $api_key = get_option('cdcf_openai_api_key');
    if (!$api_key) {
        return new WP_Error('no_api_key', 'OpenAI API key not configured.', ['status' => 500]);
    }

    set_time_limit(300);

    $errors = [];

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

    // Set ACF fields on English post.
    update_field('channel_description', $request['channel_description'], $en_post_id);
    update_field('channel_url', $request['channel_url'], $en_post_id);
    if ($request['channel_icon']) {
        update_field('channel_icon', $request['channel_icon'], $en_post_id);
    }

    // ── 2. Translate to other languages ──

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];
    $translations = ['en' => $en_post_id];

    foreach ($target_langs as $lang) {
        try {
            // Create draft translation post.
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

            // Collect translatable strings.
            $strings = [
                'post_title'            => $request['title'],
                'acf_channel_description' => $request['channel_description'],
            ];

            // Call OpenAI translation.
            $target_name = CDCF_LOCALE_NAMES[$lang] ?? $lang;
            $result = cdcf_openai_translate($strings, 'English', $target_name, $api_key);

            if (is_wp_error($result)) {
                $errors[] = "{$lang}: " . $result->get_error_message();
                // Still publish with untranslated content.
                wp_update_post(['ID' => $trans_id, 'post_status' => 'publish']);
                // Copy non-translatable fields.
                update_field('channel_url', $request['channel_url'], $trans_id);
                if ($request['channel_icon']) {
                    update_field('channel_icon', $request['channel_icon'], $trans_id);
                }
                continue;
            }

            // Write translated core fields.
            $update = ['ID' => $trans_id, 'post_status' => 'publish'];
            if (isset($result['post_title'])) {
                $update['post_title'] = sanitize_text_field($result['post_title']);
            }
            wp_update_post($update);

            // Write translated ACF fields.
            if (isset($result['acf_channel_description'])) {
                update_field('channel_description', $result['acf_channel_description'], $trans_id);
            } else {
                update_field('channel_description', $request['channel_description'], $trans_id);
            }

            // Copy non-translatable fields.
            update_field('channel_url', $request['channel_url'], $trans_id);
            if ($request['channel_icon']) {
                update_field('channel_icon', $request['channel_icon'], $trans_id);
            }
        } catch (Exception $e) {
            $errors[] = "{$lang}: " . $e->getMessage();
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

                // Append the new channel ID if not already present.
                if (!in_array($channel_id, $current)) {
                    $current[] = $channel_id;
                    update_field('channels', $current, $community_page_id);
                }
            }
        } else {
            $errors[] = 'Could not find the English Community page.';
        }
    } else {
        $errors[] = 'No Community page found with templates/community.php template.';
    }

    return rest_ensure_response([
        'success'      => true,
        'en_post_id'   => $en_post_id,
        'translations' => $translations,
        'errors'       => $errors,
    ]);
}

// ─── REST endpoint for creating a local group with translations ──────
//
// Creates an English local_group post, translates it to all configured
// languages via OpenAI, and appends each translation to the correct
// language version of the Community page's local_groups relationship field.
//
// POST /wp-json/cdcf/v1/local-group (Application Password auth)

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/local-group', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_create_local_group',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'title'             => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'group_description' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field'],
            'group_url'         => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'esc_url_raw'],
            'group_location'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
        ],
    ]);
});

function cdcf_rest_create_local_group(WP_REST_Request $request) {
    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
    }
    if (!function_exists('update_field') || !function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $api_key = get_option('cdcf_openai_api_key');
    if (!$api_key) {
        return new WP_Error('no_api_key', 'OpenAI API key not configured.', ['status' => 500]);
    }

    set_time_limit(300);

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

    // ── 2. Translate to other languages ──

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];
    $translations = ['en' => $en_post_id];

    foreach ($target_langs as $lang) {
        try {
            // Create draft translation post.
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

            // Collect translatable strings.
            $strings = [
                'post_title'           => $request['title'],
                'acf_group_description' => $request['group_description'],
            ];

            if ($request['group_location']) {
                $strings['acf_group_location'] = $request['group_location'];
            }

            // Call OpenAI translation.
            $target_name = CDCF_LOCALE_NAMES[$lang] ?? $lang;
            $result = cdcf_openai_translate($strings, 'English', $target_name, $api_key);

            if (is_wp_error($result)) {
                $errors[] = "{$lang}: " . $result->get_error_message();
                // Still publish with untranslated content.
                wp_update_post(['ID' => $trans_id, 'post_status' => 'publish']);
                // Copy fields as-is.
                update_field('group_url', $request['group_url'], $trans_id);
                update_field('group_description', $request['group_description'], $trans_id);
                if ($request['group_location']) {
                    update_field('group_location', $request['group_location'], $trans_id);
                }
                continue;
            }

            // Write translated core fields.
            $update = ['ID' => $trans_id, 'post_status' => 'publish'];
            if (isset($result['post_title'])) {
                $update['post_title'] = sanitize_text_field($result['post_title']);
            }
            wp_update_post($update);

            // Write translated ACF fields.
            if (isset($result['acf_group_description'])) {
                update_field('group_description', $result['acf_group_description'], $trans_id);
            } else {
                update_field('group_description', $request['group_description'], $trans_id);
            }

            if (isset($result['acf_group_location'])) {
                update_field('group_location', $result['acf_group_location'], $trans_id);
            } elseif ($request['group_location']) {
                update_field('group_location', $request['group_location'], $trans_id);
            }

            // Copy non-translatable fields.
            update_field('group_url', $request['group_url'], $trans_id);
        } catch (Exception $e) {
            $errors[] = "{$lang}: " . $e->getMessage();
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

    return rest_ensure_response([
        'success'      => true,
        'en_post_id'   => $en_post_id,
        'translations' => $translations,
        'errors'       => $errors,
    ]);
}

// ─── Public Referral Endpoint ────────────────────────────────────────
//
// Allows visitors to submit a local group referral for admin review.
// Creates a pending local_group post and sends an admin notification email.
//
// POST /wp-json/cdcf/v1/refer-local-group (public — no auth required)

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/refer-local-group', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_refer_local_group',
        'permission_callback' => '__return_true',
        'args' => [
            'group_name'        => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'description'       => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'url'               => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            'location'          => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'submitter_name'    => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'submitter_email'   => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'verification_code' => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);

    register_rest_route('cdcf/v1', '/refer-local-group/send-code', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_send_verification_code',
        'permission_callback' => '__return_true',
        'args' => [
            'group_name'      => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'description'     => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'url'             => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            'location'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'submitter_name'  => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'submitter_email' => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'honeypot'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'elapsed_ms'      => ['required' => false, 'type' => 'number', 'default' => 0],
        ],
    ]);
});

// ── Spam-protection helpers for public referral endpoint ──

/**
 * Check whether an IP is listed in DNS-based Real-time Blackhole Lists.
 * Returns true if the IP is listed on any checked RBL.
 * Results are cached in a transient for 1 hour.
 */
function cdcf_check_ip_rbl(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false; // Only IPv4 is supported by DNSBL lookups.
    }

    $cache_key = 'cdcf_rbl_' . md5($ip);
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        return $cached === 'listed';
    }

    $reversed = implode('.', array_reverse(explode('.', $ip)));
    $rbls     = ['zen.spamhaus.org', 'bl.spamcop.net'];
    $listed   = false;

    foreach ($rbls as $rbl) {
        if (checkdnsrr("{$reversed}.{$rbl}", 'A')) {
            $listed = true;
            break;
        }
    }

    set_transient($cache_key, $listed ? 'listed' : 'clean', HOUR_IN_SECONDS);
    return $listed;
}

/**
 * Check whether an email address uses a known disposable/throwaway domain.
 */
function cdcf_is_disposable_email(string $email): bool {
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    if (!$domain) {
        return false;
    }

    static $domains = null;
    if ($domains === null) {
        $file = __DIR__ . '/disposable-domains.txt';
        if (!file_exists($file)) {
            return false;
        }
        $domains = array_flip(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
    }

    return isset($domains[$domain]);
}

/**
 * Score text content for spam indicators. Returns true if likely spam (score >= 5).
 */
function cdcf_is_spam_content(string $text): bool {
    $score = 0;

    // Excessive URLs (> 2)
    $url_count = preg_match_all('#https?://#i', $text);
    if ($url_count > 2) {
        $score += 2;
    }

    // Common spam keywords
    $spam_keywords = [
        'viagra', 'cialis', 'casino', 'lottery', 'poker', 'blackjack',
        'buy now', 'free money', 'click here', 'act now', 'limited time',
        'nigerian prince', 'wire transfer', 'cryptocurrency offer',
    ];
    $lower = strtolower($text);
    foreach ($spam_keywords as $kw) {
        if (str_contains($lower, $kw)) {
            $score += 3;
        }
    }

    // HTML/script injection attempts
    if (preg_match('/<\s*(script|iframe|object|embed|form|style)\b/i', $text)) {
        $score += 10;
    }

    // Excessive email addresses in content (> 1)
    $email_count = preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text);
    if ($email_count > 1) {
        $score += 2;
    }

    // Non-Latin script ratio (> 50% suggests gibberish or Cyrillic spam)
    $total_chars = mb_strlen(preg_replace('/\s+/', '', $text));
    if ($total_chars > 0) {
        $latin_chars = preg_match_all('/[\x20-\x7E\xC0-\xFF]/u', $text);
        if ($latin_chars / $total_chars < 0.5) {
            $score += 2;
        }
    }

    return $score >= 5;
}

function cdcf_rest_send_verification_code(WP_REST_Request $request) {
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    // IP rate limit: max 5 code requests per hour.
    $ip_key   = 'cdcf_verify_' . md5($ip);
    $ip_count = (int) get_transient($ip_key);
    if ($ip_count >= 5) {
        return new WP_Error('rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
    }
    set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);

    // Honeypot — silent success so bots don't adapt.
    if (!empty($request['honeypot'])) {
        return rest_ensure_response(['success' => true]);
    }

    // Timing check — too fast means bot.
    $elapsed = (int) $request['elapsed_ms'];
    if ($elapsed > 0 && $elapsed < 3000) {
        return rest_ensure_response(['success' => true]);
    }

    // DNSBL check.
    if (cdcf_check_ip_rbl($ip)) {
        return new WP_Error('forbidden', 'Request blocked.', ['status' => 403]);
    }

    // Validate email format.
    if (!is_email($request['submitter_email'])) {
        return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status' => 400]);
    }

    // Disposable email check.
    if (cdcf_is_disposable_email($request['submitter_email'])) {
        return new WP_Error('disposable_email', 'Please use a permanent email address.', ['status' => 400]);
    }

    // Content spam scoring — silent success so bots don't adapt.
    if (cdcf_is_spam_content($request['description'] . ' ' . $request['group_name'])) {
        return rest_ensure_response(['success' => true]);
    }

    // Email send rate limit: max 3 codes per hour per email.
    $email       = $request['submitter_email'];
    $sends_key   = 'cdcf_code_sends_' . md5($email);
    $sends_count = (int) get_transient($sends_key);
    if ($sends_count >= 3) {
        return new WP_Error('rate_limited', 'Too many code requests for this email. Please try again later.', ['status' => 429]);
    }
    set_transient($sends_key, $sends_count + 1, HOUR_IN_SECONDS);

    // Generate 6-digit code and store in transient (10 min TTL).
    $code         = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $code_key     = 'cdcf_email_code_' . md5($email);
    set_transient($code_key, ['code' => $code, 'attempts' => 0], 600);

    // Send the code via email.
    $subject = '[CDCF] Your verification code';
    $body    = sprintf(
        "Your verification code is: %s\n\n" .
        "Enter this code in the referral form to complete your submission.\n" .
        "This code expires in 10 minutes.\n\n" .
        "If you did not request this code, you can safely ignore this email.",
        $code
    );

    $sent = wp_mail($email, $subject, $body);
    if (!$sent) {
        return new WP_Error('mail_failed', 'Failed to send verification email. Please try again.', ['status' => 500]);
    }

    return rest_ensure_response(['success' => true]);
}

function cdcf_rest_refer_local_group(WP_REST_Request $request) {
    // Rate limiting via transients: 3 submissions per hour per IP (defense-in-depth).
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $transient_key = 'cdcf_refer_' . md5($ip);
    $count = (int) get_transient($transient_key);

    if ($count >= 3) {
        return new WP_Error(
            'rate_limited',
            'Too many submissions. Please try again later.',
            ['status' => 429]
        );
    }

    set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

    // Layer 5: IP DNSBL check.
    if (cdcf_check_ip_rbl($ip)) {
        return new WP_Error('forbidden', 'Request blocked.', ['status' => 403]);
    }

    // Validate email format.
    if (!is_email($request['submitter_email'])) {
        return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status' => 400]);
    }

    // Layer 6: Disposable email check.
    if (cdcf_is_disposable_email($request['submitter_email'])) {
        return new WP_Error('disposable_email', 'Please use a permanent email address.', ['status' => 400]);
    }

    // Layer 7: Content spam scoring — silent success so bots don't adapt.
    if (cdcf_is_spam_content($request['description'] . ' ' . $request['group_name'])) {
        return rest_ensure_response(['success' => true, 'post_id' => 0]);
    }

    // Verify email verification code.
    $email    = $request['submitter_email'];
    $code_key = 'cdcf_email_code_' . md5($email);
    $stored   = get_transient($code_key);

    if (!$stored) {
        return new WP_Error('code_expired', 'Verification code has expired. Please request a new one.', ['status' => 400]);
    }

    if ($stored['attempts'] >= 5) {
        delete_transient($code_key);
        return new WP_Error('too_many_attempts', 'Too many incorrect attempts. Please request a new code.', ['status' => 429]);
    }

    if ($request['verification_code'] !== $stored['code']) {
        $stored['attempts']++;
        set_transient($code_key, $stored, 600);
        return new WP_Error('invalid_code', 'Invalid verification code. Please check and try again.', ['status' => 400]);
    }

    // Code is valid — delete it (single use).
    delete_transient($code_key);

    // Create a pending local_group post.
    $post_id = wp_insert_post([
        'post_type'   => 'local_group',
        'post_status' => 'pending',
        'post_title'  => $request['group_name'],
    ]);

    if (is_wp_error($post_id) || !$post_id) {
        return new WP_Error('insert_failed', 'Failed to create referral.', ['status' => 500]);
    }

    // Set ACF fields if ACF is active.
    if (function_exists('update_field')) {
        update_field('group_description', $request['description'], $post_id);
        update_field('group_url', $request['url'], $post_id);
        if ($request['location']) {
            update_field('group_location', $request['location'], $post_id);
        }
    }

    // Store submitter info as private post meta.
    update_post_meta($post_id, '_referral_submitter_name', $request['submitter_name']);
    update_post_meta($post_id, '_referral_submitter_email', $request['submitter_email']);

    // Send admin notification email.
    $admin_email = get_option('admin_email');
    $edit_link   = admin_url("post.php?post={$post_id}&action=edit");
    $subject     = sprintf('[CDCF] New Local Group Referral: %s', $request['group_name']);
    $body        = sprintf(
        "A new local group referral has been submitted for review.\n\n" .
        "Group Name: %s\n" .
        "Location: %s\n" .
        "URL: %s\n" .
        "Description:\n%s\n\n" .
        "Submitted by: %s (%s)\n\n" .
        "Review and approve it here:\n%s",
        $request['group_name'],
        $request['location'] ?: '(not provided)',
        $request['url'],
        $request['description'],
        $request['submitter_name'],
        $request['submitter_email'],
        $edit_link
    );

    wp_mail($admin_email, $subject, $body);

    return rest_ensure_response([
        'success' => true,
        'post_id' => $post_id,
    ]);
}

// ─── Public Project Submission Endpoint ───────────────────────────────
//
// Allows visitors to submit an open-source project for admin review.
// Creates a pending project post and sends an admin notification email.
//
// POST /wp-json/cdcf/v1/submit-project (public — no auth required)

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/submit-project', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_submit_project',
        'permission_callback' => '__return_true',
        'args' => [
            'project_name'      => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'description'       => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'url'               => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            'repo_urls'         => ['required' => false, 'type' => 'array',  'default' => []],
            'submitter_name'    => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'submitter_email'   => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'verification_code' => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);

    register_rest_route('cdcf/v1', '/submit-project/send-code', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_submit_project_send_code',
        'permission_callback' => '__return_true',
        'args' => [
            'project_name'    => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'description'     => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'url'             => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            'repo_urls'       => ['required' => false, 'type' => 'array',  'default' => []],
            'submitter_name'  => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'submitter_email' => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'honeypot'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'elapsed_ms'      => ['required' => false, 'type' => 'number', 'default' => 0],
        ],
    ]);
});

function cdcf_rest_submit_project_send_code(WP_REST_Request $request) {
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    // IP rate limit: max 5 code requests per hour.
    $ip_key   = 'cdcf_projv_' . md5($ip);
    $ip_count = (int) get_transient($ip_key);
    if ($ip_count >= 5) {
        return new WP_Error('rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
    }
    set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);

    // Honeypot — silent success so bots don't adapt.
    if (!empty($request['honeypot'])) {
        return rest_ensure_response(['success' => true]);
    }

    // Timing check — too fast means bot.
    $elapsed = (int) $request['elapsed_ms'];
    if ($elapsed > 0 && $elapsed < 3000) {
        return rest_ensure_response(['success' => true]);
    }

    // DNSBL check.
    if (cdcf_check_ip_rbl($ip)) {
        return new WP_Error('forbidden', 'Request blocked.', ['status' => 403]);
    }

    // Validate email format.
    if (!is_email($request['submitter_email'])) {
        return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status' => 400]);
    }

    // Disposable email check.
    if (cdcf_is_disposable_email($request['submitter_email'])) {
        return new WP_Error('disposable_email', 'Please use a permanent email address.', ['status' => 400]);
    }

    // Content spam scoring — silent success so bots don't adapt.
    if (cdcf_is_spam_content($request['description'] . ' ' . $request['project_name'])) {
        return rest_ensure_response(['success' => true]);
    }

    // Email send rate limit: max 3 codes per hour per email.
    $email       = $request['submitter_email'];
    $sends_key   = 'cdcf_code_sends_' . md5($email);
    $sends_count = (int) get_transient($sends_key);
    if ($sends_count >= 3) {
        return new WP_Error('rate_limited', 'Too many code requests for this email. Please try again later.', ['status' => 429]);
    }
    set_transient($sends_key, $sends_count + 1, HOUR_IN_SECONDS);

    // Generate 6-digit code and store in transient (10 min TTL).
    $code     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $code_key = 'cdcf_email_code_' . md5($email);
    set_transient($code_key, ['code' => $code, 'attempts' => 0], 600);

    // Send the code via email.
    $subject = '[CDCF] Your verification code';
    $body    = sprintf(
        "Your verification code is: %s\n\n" .
        "Enter this code in the project submission form to complete your submission.\n" .
        "This code expires in 10 minutes.\n\n" .
        "If you did not request this code, you can safely ignore this email.",
        $code
    );

    $sent = wp_mail($email, $subject, $body);
    if (!$sent) {
        return new WP_Error('mail_failed', 'Failed to send verification email. Please try again.', ['status' => 500]);
    }

    return rest_ensure_response(['success' => true]);
}

function cdcf_rest_submit_project(WP_REST_Request $request) {
    // Rate limiting via transients: 3 submissions per hour per IP (defense-in-depth).
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $transient_key = 'cdcf_projsub_' . md5($ip);
    $count = (int) get_transient($transient_key);

    if ($count >= 3) {
        return new WP_Error(
            'rate_limited',
            'Too many submissions. Please try again later.',
            ['status' => 429]
        );
    }

    set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

    // IP DNSBL check.
    if (cdcf_check_ip_rbl($ip)) {
        return new WP_Error('forbidden', 'Request blocked.', ['status' => 403]);
    }

    // Validate email format.
    if (!is_email($request['submitter_email'])) {
        return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status' => 400]);
    }

    // Disposable email check.
    if (cdcf_is_disposable_email($request['submitter_email'])) {
        return new WP_Error('disposable_email', 'Please use a permanent email address.', ['status' => 400]);
    }

    // Content spam scoring — silent success so bots don't adapt.
    if (cdcf_is_spam_content($request['description'] . ' ' . $request['project_name'])) {
        return rest_ensure_response(['success' => true, 'post_id' => 0]);
    }

    // Verify email verification code.
    $email    = $request['submitter_email'];
    $code_key = 'cdcf_email_code_' . md5($email);
    $stored   = get_transient($code_key);

    if (!$stored) {
        return new WP_Error('code_expired', 'Verification code has expired. Please request a new one.', ['status' => 400]);
    }

    if ($stored['attempts'] >= 5) {
        delete_transient($code_key);
        return new WP_Error('too_many_attempts', 'Too many incorrect attempts. Please request a new code.', ['status' => 429]);
    }

    if ($request['verification_code'] !== $stored['code']) {
        $stored['attempts']++;
        set_transient($code_key, $stored, 600);
        return new WP_Error('invalid_code', 'Invalid verification code. Please check and try again.', ['status' => 400]);
    }

    // Code is valid — delete it (single use).
    delete_transient($code_key);

    // Create a pending project post.
    $post_id = wp_insert_post([
        'post_type'    => 'project',
        'post_status'  => 'pending',
        'post_title'   => $request['project_name'],
        'post_content' => $request['description'],
    ]);

    if (is_wp_error($post_id) || !$post_id) {
        return new WP_Error('insert_failed', 'Failed to create project submission.', ['status' => 500]);
    }

    // Sanitise repo URLs.
    $repo_urls = array_values(array_filter(array_map('esc_url_raw', (array) $request['repo_urls'])));

    // Set ACF fields if ACF is active.
    if (function_exists('update_field')) {
        update_field('project_url', $request['url'], $post_id);
        update_field('project_status', 'incubating', $post_id);
        if (!empty($repo_urls)) {
            update_field('project_repo_url', $repo_urls[0], $post_id);
        }
    }

    // Store all repo URLs as private meta (JSON-encoded array) for the meta box.
    if (!empty($repo_urls)) {
        update_post_meta($post_id, '_submission_repo_urls', wp_json_encode($repo_urls));
    }

    // Store submitter info as private post meta.
    update_post_meta($post_id, '_submission_submitter_name', $request['submitter_name']);
    update_post_meta($post_id, '_submission_submitter_email', $request['submitter_email']);

    // Send admin notification email.
    $admin_email = get_option('admin_email');
    $edit_link   = admin_url("post.php?post={$post_id}&action=edit");
    $subject     = sprintf('[CDCF] New Project Submission: %s', $request['project_name']);

    $repo_list = !empty($repo_urls) ? implode("\n  ", $repo_urls) : '(none provided)';
    $body = sprintf(
        "A new project has been submitted for review.\n\n" .
        "Project Name: %s\n" .
        "Website: %s\n" .
        "Repositories:\n  %s\n" .
        "Description:\n%s\n\n" .
        "Submitted by: %s (%s)\n\n" .
        "Review and approve it here:\n%s",
        $request['project_name'],
        $request['url'],
        $repo_list,
        $request['description'],
        $request['submitter_name'],
        $request['submitter_email'],
        $edit_link
    );

    wp_mail($admin_email, $subject, $body);

    return rest_ensure_response([
        'success' => true,
        'post_id' => $post_id,
    ]);
}

// ─── ACF Field Groups (registered programmatically) ──────────────────

add_action('acf/init', function () {
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    // ── Hero Fields (shared — all page templates) ──

    acf_add_local_field_group([
        'key'      => 'group_hero',
        'title'    => 'Hero Section',
        'fields'   => [
            [
                'key'     => 'field_hero_bg_style',
                'label'   => 'Background Style',
                'name'    => 'hero_bg_style',
                'type'    => 'select',
                'choices' => [
                    'gradient' => 'Navy Gradient',
                    'image'    => 'Background Image',
                    'solid'    => 'Solid Color',
                ],
                'default_value' => 'gradient',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'     => 'field_hero_bg_color',
                'label'   => 'Background Color',
                'name'    => 'hero_bg_color',
                'type'    => 'color_picker',
                'default_value' => '#213463',
                'conditional_logic' => [
                    [['field' => 'field_hero_bg_style', 'operator' => '==', 'value' => 'solid']],
                ],
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'     => 'field_hero_show_logo',
                'label'   => 'Show Logo',
                'name'    => 'hero_show_logo',
                'type'    => 'true_false',
                'default_value' => 1,
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'     => 'field_hero_alignment',
                'label'   => 'Alignment',
                'name'    => 'hero_alignment',
                'type'    => 'select',
                'choices' => [
                    'left'   => 'Left',
                    'center' => 'Center',
                    'right'  => 'Right',
                ],
                'default_value' => 'center',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_hero_tagline',
                'label' => 'Tagline',
                'name'  => 'hero_tagline',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_hero_subtitle',
                'label' => 'Subtitle',
                'name'  => 'hero_subtitle',
                'type'  => 'wysiwyg',
                'media_upload' => 0,
                'tabs'  => 'all',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'     => 'field_hero_background_image',
                'label'   => 'Background Image',
                'name'    => 'hero_background_image',
                'type'    => 'image',
                'return_format' => 'array',
                'conditional_logic' => [
                    [['field' => 'field_hero_bg_style', 'operator' => '==', 'value' => 'image']],
                ],
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_hero_primary_btn_label',
                'label' => 'Primary Button Label',
                'name'  => 'hero_primary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_hero_primary_btn_url',
                'label' => 'Primary Button URL',
                'name'  => 'hero_primary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_hero_secondary_btn_label',
                'label' => 'Secondary Button Label',
                'name'  => 'hero_secondary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_hero_secondary_btn_url',
                'label' => 'Secondary Button URL',
                'name'  => 'hero_secondary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'default']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/home.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/about.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/projects.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/community.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/blog.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/contact.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'hero',
        'graphql_types' => ['Page'],
        'position' => 'normal',
        'menu_order' => 0,
    ]);

    // ── CTA Fields (shared — templates that use a CTA) ──

    acf_add_local_field_group([
        'key'      => 'group_cta',
        'title'    => 'Call to Action Section',
        'fields'   => [
            [
                'key'     => 'field_cta_style',
                'label'   => 'CTA Style',
                'name'    => 'cta_style',
                'type'    => 'select',
                'choices' => [
                    'banner' => 'Full-width Banner',
                    'card'   => 'Card',
                    'inline' => 'Inline',
                ],
                'default_value' => 'banner',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_cta_heading',
                'label' => 'CTA Heading',
                'name'  => 'cta_heading',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_cta_description',
                'label' => 'CTA Description',
                'name'  => 'cta_description',
                'type'  => 'wysiwyg',
                'media_upload' => 0,
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_cta_primary_btn_label',
                'label' => 'Primary Button Label',
                'name'  => 'cta_primary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_cta_primary_btn_url',
                'label' => 'Primary Button URL',
                'name'  => 'cta_primary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_cta_secondary_btn_label',
                'label' => 'Secondary Button Label',
                'name'  => 'cta_secondary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_cta_secondary_btn_url',
                'label' => 'Secondary Button URL',
                'name'  => 'cta_secondary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'default']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/home.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/about.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/projects.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/community.php']],
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/contact.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'cta',
        'graphql_types' => ['Page'],
        'position' => 'normal',
        'menu_order' => 90,
    ]);

    // ── Project CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_project',
        'title' => 'Project Details',
        'fields' => [
            [
                'key'     => 'field_project_status',
                'label'   => 'Status',
                'name'    => 'project_status',
                'type'    => 'select',
                'choices' => [
                    'incubating' => 'Incubating',
                    'active'     => 'Active',
                    'graduated'  => 'Graduated',
                ],
                'default_value' => 'incubating',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_project_repo_url',
                'label' => 'Repository URL',
                'name'  => 'project_repo_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_project_url',
                'label' => 'Project Website URL',
                'name'  => 'project_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_project_license',
                'label' => 'License',
                'name'  => 'project_license',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_project_category',
                'label' => 'Category',
                'name'  => 'project_category',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'project']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'projectFields',
    ]);

    // ── Team Member CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_team_member',
        'title' => 'Team Member Details',
        'fields' => [
            [
                'key'   => 'field_member_role',
                'label' => 'Role',
                'name'  => 'member_role',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_member_title',
                'label' => 'Title',
                'name'  => 'member_title',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_member_linkedin_url',
                'label' => 'LinkedIn URL',
                'name'  => 'member_linkedin_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_member_github_url',
                'label' => 'GitHub URL',
                'name'  => 'member_github_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'team_member']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'teamMemberFields',
    ]);

    // ── Sponsor CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_sponsor',
        'title' => 'Sponsor Details',
        'fields' => [
            [
                'key'     => 'field_sponsor_tier',
                'label'   => 'Tier',
                'name'    => 'sponsor_tier',
                'type'    => 'select',
                'choices' => [
                    'platinum' => 'Platinum',
                    'gold'     => 'Gold',
                    'silver'   => 'Silver',
                    'bronze'   => 'Bronze',
                ],
                'default_value' => 'silver',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_sponsor_url',
                'label' => 'Website URL',
                'name'  => 'sponsor_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'sponsor']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'sponsorFields',
    ]);

    // ── Community Channel CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_community_channel',
        'title' => 'Channel Details',
        'fields' => [
            [
                'key'   => 'field_channel_icon',
                'label' => 'Icon (emoji)',
                'name'  => 'channel_icon',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_channel_url',
                'label' => 'Channel URL',
                'name'  => 'channel_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_channel_description',
                'label' => 'Description',
                'name'  => 'channel_description',
                'type'  => 'textarea',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'community_channel']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'channelFields',
    ]);

    // ── Local Group CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_local_group',
        'title' => 'Local Group Details',
        'fields' => [
            [
                'key'   => 'field_group_location',
                'label' => 'Location (city/region)',
                'name'  => 'group_location',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_group_url',
                'label' => 'Group URL',
                'name'  => 'group_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_group_description',
                'label' => 'Description',
                'name'  => 'group_description',
                'type'  => 'textarea',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'local_group']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'localGroupFields',
    ]);

    // ── Stat Item CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_stat_item',
        'title' => 'Stat Details',
        'fields' => [
            [
                'key'   => 'field_stat_icon',
                'label' => 'Icon (emoji)',
                'name'  => 'stat_icon',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_stat_number',
                'label' => 'Number',
                'name'  => 'stat_number',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_stat_label',
                'label' => 'Label',
                'name'  => 'stat_label',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'stat_item']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'statFields',
    ]);

    // ── Home Page Specific Fields ──

    acf_add_local_field_group([
        'key'   => 'group_home',
        'title' => 'Home Page Sections',
        'fields' => [
            [
                'key'   => 'field_home_featured_projects',
                'label' => 'Featured Projects',
                'name'  => 'featured_projects',
                'type'  => 'relationship',
                'post_type' => ['project'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_home_stats',
                'label' => 'Stats',
                'name'  => 'stats',
                'type'  => 'relationship',
                'post_type' => ['stat_item'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'     => 'field_home_stats_bg',
                'label'   => 'Stats Background',
                'name'    => 'stats_bg_color',
                'type'    => 'select',
                'choices' => [
                    'navy'  => 'Navy',
                    'white' => 'White',
                ],
                'default_value' => 'navy',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/home.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'homeFields',
        'graphql_types' => ['Page'],
        'menu_order' => 10,
    ]);

    // ── About Page Specific Fields ──

    acf_add_local_field_group([
        'key'   => 'group_about',
        'title' => 'About Page Sections',
        'fields' => [
            [
                'key'   => 'field_about_team_members',
                'label' => 'Board of Directors',
                'name'  => 'team_members',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_about_ecclesial_council',
                'label' => 'Ecclesial Advisory Council',
                'name'  => 'ecclesial_council',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_about_technical_council',
                'label' => 'Technical Advisory Council',
                'name'  => 'technical_council',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'     => 'field_about_governance_columns',
                'label'   => 'Team Grid Columns',
                'name'    => 'governance_columns',
                'type'    => 'select',
                'choices' => [
                    '2' => '2 Columns',
                    '3' => '3 Columns',
                    '4' => '4 Columns',
                ],
                'default_value' => '3',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/about.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'aboutFields',
        'graphql_types' => ['Page'],
        'menu_order' => 10,
    ]);

    // ── Projects Page Specific Fields ──

    acf_add_local_field_group([
        'key'   => 'group_projects_page',
        'title' => 'Projects Page Settings',
        'fields' => [
            [
                'key'   => 'field_projects_show_filters',
                'label' => 'Show Filters',
                'name'  => 'show_filters',
                'type'  => 'true_false',
                'default_value' => 0,
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'     => 'field_projects_columns',
                'label'   => 'Grid Columns',
                'name'    => 'grid_columns',
                'type'    => 'select',
                'choices' => [
                    '2' => '2 Columns',
                    '3' => '3 Columns',
                    '4' => '4 Columns',
                ],
                'default_value' => '3',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/projects.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'projectsPageFields',
        'graphql_types' => ['Page'],
        'menu_order' => 10,
    ]);

    // ── Community Page Specific Fields ──

    acf_add_local_field_group([
        'key'   => 'group_community_page',
        'title' => 'Community Page Sections',
        'fields' => [
            [
                'key'   => 'field_community_channels',
                'label' => 'Community Channels',
                'name'  => 'channels',
                'type'  => 'relationship',
                'post_type' => ['community_channel'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_community_local_groups',
                'label' => 'Local Groups',
                'name'  => 'local_groups',
                'type'  => 'relationship',
                'post_type' => ['local_group'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_community_members',
                'label' => 'Team Members',
                'name'  => 'members',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/community.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'communityFields',
        'graphql_types' => ['Page'],
        'menu_order' => 10,
    ]);

    // ── Blog Page Specific Fields ──

    acf_add_local_field_group([
        'key'   => 'group_blog_page',
        'title' => 'Blog Page Settings',
        'fields' => [
            [
                'key'     => 'field_blog_max_posts',
                'label'   => 'Max Posts',
                'name'    => 'max_posts',
                'type'    => 'number',
                'default_value' => 6,
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/blog.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'blogFields',
        'graphql_types' => ['Page'],
        'menu_order' => 10,
    ]);

    // ── Post Settings (applies to all blog posts) ──

    acf_add_local_field_group([
        'key'   => 'group_post_settings',
        'title' => 'Post Settings',
        'fields' => [
            [
                'key'           => 'field_hide_from_blog',
                'label'         => 'Hide from Blog',
                'name'          => 'hide_from_blog',
                'type'          => 'true_false',
                'instructions'  => 'Enable this to hide the post from the blog listing. The post will still be accessible via direct link.',
                'default_value' => 0,
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'postSettings',
        'graphql_types' => ['Post'],
        'menu_order' => 0,
    ]);

    // ── Contact Page Specific Fields ──

    acf_add_local_field_group([
        'key'   => 'group_contact_page',
        'title' => 'Contact Page Content',
        'fields' => [
            [
                'key'   => 'field_contact_body',
                'label' => 'Body Content',
                'name'  => 'contact_body',
                'type'  => 'wysiwyg',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/contact.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'contactFields',
        'graphql_types' => ['Page'],
        'menu_order' => 10,
    ]);
});

// ─── Page Templates ──────────────────────────────────────────────────

// Register page templates for the headless theme
add_filter('theme_page_templates', function ($templates) {
    $templates['templates/home.php']      = 'Home';
    $templates['templates/about.php']     = 'About';
    $templates['templates/projects.php']  = 'Projects';
    $templates['templates/community.php'] = 'Community';
    $templates['templates/blog.php']      = 'Blog';
    $templates['templates/contact.php']   = 'Contact';
    return $templates;
});

// ─── Polylang: register CPTs for translation ─────────────────────────

add_filter('pll_get_post_types', function ($post_types) {
    $post_types['project']           = 'project';
    $post_types['team_member']       = 'team_member';
    $post_types['sponsor']           = 'sponsor';
    $post_types['community_channel'] = 'community_channel';
    $post_types['local_group']       = 'local_group';
    $post_types['stat_item']         = 'stat_item';
    return $post_types;
}, 10, 2);

// ─── CORS for GraphQL endpoint ───────────────────────────────────────

add_action('graphql_response_headers_to_send', function ($headers) {
    $frontend = defined('CDCF_FRONTEND_URL')
        ? CDCF_FRONTEND_URL
        : 'http://localhost:3000';

    $headers['Access-Control-Allow-Origin']  = $frontend;
    $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization';
    $headers['Access-Control-Allow-Methods'] = 'POST, GET, OPTIONS';

    return $headers;
});

// ─── Preview URL → Next.js draft mode ────────────────────────────────

add_filter('preview_post_link', function ($preview_link, $post) {
    $frontend = defined('CDCF_FRONTEND_URL')
        ? CDCF_FRONTEND_URL
        : 'http://localhost:3000';

    $secret = defined('CDCF_PREVIEW_SECRET')
        ? CDCF_PREVIEW_SECRET
        : '';

    $slug = $post->post_name;
    $type = $post->post_type;

    return sprintf(
        '%s/api/preview?secret=%s&slug=%s&type=%s',
        $frontend,
        urlencode($secret),
        urlencode($slug),
        urlencode($type)
    );
}, 10, 2);

// ─── Theme setup ─────────────────────────────────────────────────────

add_action('after_setup_theme', function () {
    add_theme_support('post-thumbnails');
    add_theme_support('title-tag');
});

// ─── AI Translation for Polylang ─────────────────────────────────────
//
// Translates post title, content, excerpt, and all translatable ACF
// fields using the OpenAI API. Triggered via a button in the post editor
// when editing a Polylang translation post.

// ACF field types that contain human-readable text worth translating.
// Everything else (urls, selects, booleans, numbers, images, colors,
// relationships) is copied verbatim by Polylang and should not be sent
// to the AI.
define('CDCF_TRANSLATABLE_ACF_TYPES', ['text', 'textarea', 'wysiwyg']);

// Locale code → human-readable language name for the AI prompt.
define('CDCF_LOCALE_NAMES', [
    'en' => 'English',
    'it' => 'Italian',
    'es' => 'Spanish',
    'fr' => 'French',
    'pt' => 'Portuguese',
    'de' => 'German',
]);

// ── Settings page (under Languages menu) ──

add_action('admin_menu', function () {
    add_submenu_page(
        'mlang',  // Polylang's top-level menu slug
        'AI Translation Settings',
        'AI Translation',
        'manage_options',
        'cdcf-ai-translate',
        'cdcf_ai_translate_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('cdcf_ai_translate', 'cdcf_openai_api_key', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
    register_setting('cdcf_ai_translate', 'cdcf_openai_model', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'gpt-4o-mini',
    ]);
});

function cdcf_ai_translate_settings_page() {
    ?>
    <div class="wrap">
        <h1>AI Translation Settings</h1>
        <?php settings_errors(); ?>
        <form method="post" action="options.php">
            <?php settings_fields('cdcf_ai_translate'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="cdcf_openai_api_key">OpenAI API Key</label></th>
                    <td>
                        <input type="password" id="cdcf_openai_api_key" name="cdcf_openai_api_key"
                               value="<?php echo esc_attr(get_option('cdcf_openai_api_key')); ?>"
                               class="regular-text" autocomplete="off" />
                        <p class="description">Your OpenAI API key. Obtain one at
                            <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cdcf_openai_model">Model</label></th>
                    <td>
                        <input type="text" id="cdcf_openai_model" name="cdcf_openai_model"
                               value="<?php echo esc_attr(get_option('cdcf_openai_model', 'gpt-4o-mini')); ?>"
                               class="regular-text" />
                        <p class="description">OpenAI model ID (e.g. <code>gpt-4o-mini</code>, <code>gpt-4o</code>, <code>gpt-4.1-nano</code>).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// ── "Translate with AI" meta box in the post editor ──

add_action('add_meta_boxes', function () {
    if (!function_exists('pll_get_post_language') || !get_option('cdcf_openai_api_key')) {
        return;
    }

    // Show on all public post types registered with Polylang.
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $pt) {
        add_meta_box(
            'cdcf_ai_translate',
            'AI Translation',
            'cdcf_ai_translate_meta_box',
            $pt,
            'side',
            'high'
        );
    }
});

function cdcf_ai_translate_meta_box($post) {
    if (!function_exists('pll_get_post_language')) {
        echo '<p>Polylang is not active.</p>';
        return;
    }

    $lang = pll_get_post_language($post->ID, 'slug');
    if (!$lang) {
        echo '<p>Save the post with a language first.</p>';
        return;
    }

    $default_lang = pll_default_language('slug');
    $is_source = ($lang === $default_lang);

    wp_nonce_field('cdcf_ai_translate', 'cdcf_ai_translate_nonce');

    if ($is_source) {
        // ── Source post: show buttons for each target language ──
        $all_langs = pll_languages_list(['fields' => 'slug']);
        $target_langs = array_values(array_filter($all_langs, fn($l) => $l !== $default_lang));
        $translations = pll_get_post_translations($post->ID);

        if (empty($target_langs)) {
            echo '<p>No other languages configured.</p>';
            return;
        }
        ?>
        <p>Translate from <strong><?php echo esc_html(CDCF_LOCALE_NAMES[$default_lang] ?? $default_lang); ?></strong>
           to other languages using OpenAI.</p>
        <p><em>Creates translation posts if needed, then translates title, content, excerpt, and ACF fields.</em></p>
        <div style="margin-top:8px;">
        <?php foreach ($target_langs as $tl):
            $lang_name = CDCF_LOCALE_NAMES[$tl] ?? $tl;
            $existing_id = $translations[$tl] ?? 0;
        ?>
            <div style="margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                <button type="button" class="button cdcf-ai-translate-btn"
                        data-source-id="<?php echo esc_attr($post->ID); ?>"
                        data-target-lang="<?php echo esc_attr($tl); ?>"
                        data-post-id="<?php echo esc_attr($existing_id); ?>">
                    <?php echo esc_html($lang_name); ?>
                </button>
                <span class="cdcf-ai-translate-status" style="font-size:12px;"></span>
            </div>
        <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;">
            <button type="button" id="cdcf-ai-translate-all" class="button button-primary">
                Translate All
            </button>
        </div>
        <?php
    } else {
        // ── Translation post: single translate button ──
        $source_id = pll_get_post($post->ID, $default_lang);
        if (!$source_id && isset($_GET['from_post'])) {
            $source_id = (int) $_GET['from_post'];
        }
        if (!$source_id) {
            echo '<p>No source post found in the default language.</p>';
            return;
        }
        $lang_name = CDCF_LOCALE_NAMES[$lang] ?? $lang;
        ?>
        <p>Translate from <strong><?php echo esc_html(CDCF_LOCALE_NAMES[$default_lang] ?? $default_lang); ?></strong>
           to <strong><?php echo esc_html($lang_name); ?></strong> using OpenAI.</p>
        <p><em>This will overwrite the title, content, excerpt, and translatable ACF fields on this post.</em></p>
        <div style="margin-bottom:6px;display:flex;align-items:center;gap:6px;">
            <button type="button" class="button button-primary cdcf-ai-translate-btn"
                    data-post-id="<?php echo esc_attr($post->ID); ?>"
                    data-source-id="<?php echo esc_attr($source_id); ?>"
                    data-target-lang="<?php echo esc_attr($lang); ?>">
                Translate with AI
            </button>
            <span class="cdcf-ai-translate-status" style="font-size:12px;"></span>
        </div>
        <?php
    }
    ?>
    <script>
    (function() {
        function translateOne(btn) {
            var status = btn.parentElement.querySelector('.cdcf-ai-translate-status');
            btn.disabled = true;
            status.textContent = 'Translating…';
            status.style.color = '#0073aa';

            var data = new FormData();
            data.append('action', 'cdcf_ai_translate');
            data.append('source_id', btn.dataset.sourceId);
            data.append('target_lang', btn.dataset.targetLang);
            data.append('post_id', btn.dataset.postId || '0');
            data.append('_wpnonce', document.getElementById('cdcf_ai_translate_nonce').value);

            return fetch(ajaxurl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        status.style.color = '#46b450';
                        if (resp.data && resp.data.post_id) {
                            btn.dataset.postId = resp.data.post_id;
                            var editUrl = '<?php echo admin_url('post.php?action=edit&post='); ?>' + resp.data.post_id;
                            status.innerHTML = '✓ <a href="' + editUrl + '" target="_blank">Edit</a>';
                        } else {
                            status.textContent = '✓ Done';
                        }
                    } else {
                        status.textContent = resp.data || 'Error';
                        status.style.color = '#dc3232';
                        btn.disabled = false;
                        throw new Error(resp.data);
                    }
                })
                .catch(function(err) {
                    if (!status.textContent || status.textContent === 'Translating…') {
                        status.textContent = 'Failed';
                        status.style.color = '#dc3232';
                        btn.disabled = false;
                    }
                    throw err;
                });
        }

        // Individual language buttons
        document.querySelectorAll('.cdcf-ai-translate-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                translateOne(btn).catch(function() {});
            });
        });

        // "Translate All" button — sequential to avoid rate limits
        var allBtn = document.getElementById('cdcf-ai-translate-all');
        if (allBtn) {
            allBtn.addEventListener('click', function() {
                if (!confirm('This will create/overwrite translations for ALL languages. Continue?')) return;
                allBtn.disabled = true;
                var buttons = Array.from(document.querySelectorAll('.cdcf-ai-translate-btn'));
                var chain = Promise.resolve();
                buttons.forEach(function(btn) {
                    chain = chain.then(function() { return translateOne(btn); })
                                 .catch(function() { return Promise.resolve(); }); // continue on error
                });
                chain.then(function() { allBtn.textContent = 'All done!'; });
            });
        }
    })();
    </script>
    <?php
}

// ── AJAX handler ──

add_action('wp_ajax_cdcf_ai_translate', function () {
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

        pll_set_post_language($post_id, $target_lang);
        $translations = pll_get_post_translations($source_id);
        $translations[$target_lang] = $post_id;
        pll_save_post_translations($translations);
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
        $update['post_content'] = wp_kses_post($result['post_content']);
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
});

// ─── Background translation processor (WP Cron) ─────────────────────

function cdcf_process_translation($post_id, $source_id, $target_lang) {
    $source = get_post($source_id);
    if (!$source) {
        error_log("cdcf_process_translation: Source post {$source_id} not found.");
        return;
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
        return;
    }

    // Call OpenAI.
    $api_key = get_option('cdcf_openai_api_key');
    if (!$api_key) {
        error_log('cdcf_process_translation: OpenAI API key not configured.');
        return;
    }

    $target_name = CDCF_LOCALE_NAMES[$target_lang] ?? $target_lang;
    $source_lang = pll_default_language('slug');
    $source_name = CDCF_LOCALE_NAMES[$source_lang] ?? $source_lang;

    $result = cdcf_openai_translate($strings, $source_name, $target_name, $api_key);
    if (is_wp_error($result)) {
        error_log('cdcf_process_translation: OpenAI error – ' . $result->get_error_message());
        return;
    }

    // Write translations.
    $update = [];
    if (isset($result['post_title']))   $update['post_title']   = sanitize_text_field($result['post_title']);
    if (isset($result['post_content'])) $update['post_content'] = wp_kses_post($result['post_content']);
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

    // Auto-publish if source is published.
    if ($source->post_type !== 'attachment' && $source->post_status === 'publish' && get_post_status($post_id) !== 'publish') {
        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
    }

    error_log("cdcf_process_translation: Translation complete for post {$post_id} ({$target_lang}).");
}
add_action('cdcf_async_translate', 'cdcf_process_translation', 10, 3);

// ─── REST endpoint for AI translation ────────────────────────────────
//
// Mirrors the admin-ajax cdcf_ai_translate handler but uses REST API
// authentication (Application Passwords) instead of cookie + nonce.
//
// POST /wp-json/cdcf/v1/translate { source_id: 255, target_lang: "it", post_id: 0 }

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/translate', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => function (WP_REST_Request $request) {
            $post_id     = intval($request['post_id'] ?? 0);
            $source_id   = intval($request['source_id'] ?? 0);
            $target_lang = sanitize_text_field($request['target_lang'] ?? '');

            if (!$source_id || !$target_lang) {
                return new WP_Error('missing_params', 'Missing source_id or target_lang.', ['status' => 400]);
            }

            if (!function_exists('pll_set_post_language')) {
                return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
            }

            // Auto-create translation post if needed.
            if (!$post_id) {
                $source = get_post($source_id);
                if (!$source) {
                    return new WP_Error('not_found', 'Source post not found.', ['status' => 404]);
                }

                $insert_args = [
                    'post_type'   => $source->post_type,
                    'post_status' => 'draft',
                    'post_title'  => $source->post_title,
                ];

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
                    if ($attached_file) {
                        update_post_meta($post_id, '_wp_attached_file', $attached_file);
                    }
                    $attachment_meta = get_post_meta($source_id, '_wp_attachment_metadata', true);
                    if ($attachment_meta) {
                        update_post_meta($post_id, '_wp_attachment_metadata', $attachment_meta);
                    }
                }

                pll_set_post_language($post_id, $target_lang);
                $source_lang = pll_get_post_language($source_id);
                $translations = pll_get_post_translations($source_id);
                $translations[$source_lang] = $source_id;
                $translations[$target_lang] = $post_id;
                pll_save_post_translations($translations);
            }

            // Enqueue translation: Redis Queue if available, WP Cron fallback.
            if (function_exists('cdcf_enqueue_translation')) {
                $queue = cdcf_enqueue_translation($post_id, $source_id, $target_lang);
            } else {
                wp_schedule_single_event(time(), 'cdcf_async_translate', [$post_id, $source_id, $target_lang]);
                spawn_cron();
                $queue = 'wp-cron';
            }

            return new WP_REST_Response([
                'post_id' => $post_id,
                'queue'   => $queue,
                'message' => 'Translation queued.',
            ], 202);
        },
        'args' => [
            'source_id'   => ['required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint'],
            'target_lang' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'post_id'     => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
        ],
    ]);

    register_rest_route('cdcf/v1', '/deploy-translation', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => function (WP_REST_Request $request) {
            $source_id   = intval($request['source_id'] ?? 0);
            $target_lang = sanitize_text_field($request['target_lang'] ?? '');
            $title       = sanitize_text_field($request['title'] ?? '');
            $content     = wp_kses_post($request['content'] ?? '');

            if (!$source_id || !$target_lang || !$content) {
                return new WP_Error('missing_params', 'Missing source_id, target_lang, or content.', ['status' => 400]);
            }

            if (!function_exists('pll_set_post_language')) {
                return new WP_Error('polylang_missing', 'Polylang is not active.', ['status' => 500]);
            }

            $source = get_post($source_id);
            if (!$source) {
                return new WP_Error('not_found', 'Source post not found.', ['status' => 404]);
            }

            // Check if a translation already exists for this language.
            $translations = pll_get_post_translations($source_id);
            $post_id = $translations[$target_lang] ?? 0;

            if ($post_id) {
                wp_update_post([
                    'ID'           => $post_id,
                    'post_title'   => $title ?: $source->post_title,
                    'post_content' => $content,
                    'post_status'  => $source->post_status,
                ]);
            } else {
                $post_id = wp_insert_post([
                    'post_type'    => $source->post_type,
                    'post_status'  => $source->post_status,
                    'post_title'   => $title ?: $source->post_title,
                    'post_content' => $content,
                ]);

                if (is_wp_error($post_id) || !$post_id) {
                    return new WP_Error('insert_failed', 'Failed to create translation post.', ['status' => 500]);
                }

                pll_set_post_language($post_id, $target_lang);
                $translations[$target_lang] = $post_id;
                pll_save_post_translations($translations);
            }

            return rest_ensure_response([
                'post_id' => $post_id,
                'message' => 'Translation deployed.',
            ]);
        },
        'args' => [
            'source_id'   => ['required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint'],
            'target_lang' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'title'       => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'content'     => ['required' => true,  'type' => 'string'],
        ],
    ]);
});

/**
 * Send an array of strings to OpenAI for translation.
 *
 * @param  array  $strings     ['key' => 'source text', ...]
 * @param  string $source_lang Human-readable source language name.
 * @param  string $target_lang Human-readable target language name.
 * @param  string $api_key     OpenAI API key.
 * @return array|WP_Error      ['key' => 'translated text', ...] or WP_Error.
 */
function cdcf_openai_translate($strings, $source_lang, $target_lang, $api_key) {
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

    $body = [
        'model'       => $model,
        'temperature' => 0.3,
        'messages'    => [
            ['role' => 'system',  'content' => $system_prompt],
            ['role' => 'user',    'content' => $user_message],
        ],
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
        return new WP_Error('openai_error', 'OpenAI API error: ' . $msg);
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

// ═══════════════════════════════════════════════════════════════════════════
// Bulk Translate media from the Media Library list view
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Add "Translate All Languages" to the Media Library bulk-actions dropdown.
 */
add_filter('bulk_actions-upload', function ($actions) {
    if (function_exists('pll_default_language') && get_option('cdcf_openai_api_key')) {
        $actions['cdcf_bulk_translate'] = 'Translate All Languages';
    }
    return $actions;
});

/**
 * Handle the bulk action — redirect to a progress page.
 */
add_filter('handle_bulk_actions-upload', function ($redirect_url, $action, $post_ids) {
    if ($action !== 'cdcf_bulk_translate') {
        return $redirect_url;
    }
    $redirect_url = add_query_arg([
        'page'     => 'cdcf-bulk-translate',
        'post_ids' => implode(',', array_map('intval', $post_ids)),
    ], admin_url('admin.php'));
    return $redirect_url;
}, 10, 3);

/**
 * Register the hidden admin page for the bulk-translate progress screen.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        null, // hidden — no menu entry
        'Bulk Translate Media',
        'Bulk Translate Media',
        'edit_posts',
        'cdcf-bulk-translate',
        'cdcf_bulk_translate_page'
    );
});

/**
 * Render the bulk-translate progress page.
 * Uses the existing cdcf_ai_translate AJAX endpoint sequentially.
 */
function cdcf_bulk_translate_page() {
    if (!current_user_can('edit_posts')) {
        wp_die('Insufficient permissions.');
    }

    $post_ids = array_filter(array_map('intval', explode(',', $_GET['post_ids'] ?? '')));
    if (empty($post_ids)) {
        echo '<div class="wrap"><h1>Bulk Translate Media</h1><p>No media items selected.</p></div>';
        return;
    }

    $default_lang = function_exists('pll_default_language') ? pll_default_language('slug') : 'en';
    $all_langs    = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
    $target_langs = array_values(array_filter($all_langs, fn($l) => $l !== $default_lang));

    if (empty($target_langs)) {
        echo '<div class="wrap"><h1>Bulk Translate Media</h1><p>No target languages configured.</p></div>';
        return;
    }

    $nonce = wp_create_nonce('cdcf_ai_translate');
    ?>
    <div class="wrap">
        <h1>Bulk Translate Media</h1>
        <p>Translating <strong><?php echo count($post_ids); ?></strong> media item(s)
           into <strong><?php echo count($target_langs); ?></strong> language(s).
           Total API calls: <strong><?php echo count($post_ids) * count($target_langs); ?></strong>.</p>

        <table class="widefat fixed striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th style="width:40%;">Media</th>
                    <?php foreach ($target_langs as $tl): ?>
                        <th style="text-align:center;"><?php echo esc_html(CDCF_LOCALE_NAMES[$tl] ?? $tl); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($post_ids as $pid):
                    $post = get_post($pid);
                    if (!$post || $post->post_type !== 'attachment') continue;
                    $thumb = wp_get_attachment_image($pid, [48, 48]);
                    $translations = function_exists('pll_get_post_translations') ? pll_get_post_translations($pid) : [];
                ?>
                <tr data-source-id="<?php echo esc_attr($pid); ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <?php echo $thumb; ?>
                            <span><?php echo esc_html($post->post_title); ?></span>
                        </div>
                    </td>
                    <?php foreach ($target_langs as $tl): ?>
                        <td style="text-align:center;">
                            <span class="cdcf-bulk-status"
                                  data-target-lang="<?php echo esc_attr($tl); ?>"
                                  data-post-id="<?php echo esc_attr($translations[$tl] ?? 0); ?>">
                                &mdash;
                            </span>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:16px;">
            <button type="button" id="cdcf-bulk-start" class="button button-primary button-hero">
                Start Translating
            </button>
            <span id="cdcf-bulk-overall" style="margin-left:12px;font-size:14px;"></span>
        </p>
    </div>

    <script>
    (function() {
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var startBtn = document.getElementById('cdcf-bulk-start');
        var overallStatus = document.getElementById('cdcf-bulk-overall');

        // Collect all translation tasks.
        var tasks = [];
        document.querySelectorAll('tr[data-source-id]').forEach(function(row) {
            var sourceId = row.dataset.sourceId;
            row.querySelectorAll('.cdcf-bulk-status').forEach(function(cell) {
                tasks.push({
                    sourceId: sourceId,
                    targetLang: cell.dataset.targetLang,
                    postId: cell.dataset.postId || '0',
                    cell: cell
                });
            });
        });

        startBtn.addEventListener('click', function() {
            if (!confirm('This will translate ' + tasks.length + ' item(s). Continue?')) return;
            startBtn.disabled = true;

            var done = 0;
            var failed = 0;
            var total = tasks.length;

            function updateOverall() {
                overallStatus.textContent = done + '/' + total + ' done' + (failed ? ', ' + failed + ' failed' : '');
            }

            function runNext(i) {
                if (i >= total) {
                    overallStatus.textContent = 'Complete! ' + done + ' translated' + (failed ? ', ' + failed + ' failed' : '') + '.';
                    startBtn.textContent = 'Done';
                    return;
                }

                var task = tasks[i];
                task.cell.textContent = '…';
                task.cell.style.color = '#0073aa';
                updateOverall();

                var data = new FormData();
                data.append('action', 'cdcf_ai_translate');
                data.append('source_id', task.sourceId);
                data.append('target_lang', task.targetLang);
                data.append('post_id', task.postId);
                data.append('_wpnonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.success) {
                            task.cell.textContent = '✓';
                            task.cell.style.color = '#46b450';
                            if (resp.data && resp.data.post_id) {
                                task.cell.dataset.postId = resp.data.post_id;
                            }
                            done++;
                        } else {
                            task.cell.textContent = '✗';
                            task.cell.title = resp.data || 'Error';
                            task.cell.style.color = '#dc3232';
                            failed++;
                        }
                    })
                    .catch(function() {
                        task.cell.textContent = '✗';
                        task.cell.style.color = '#dc3232';
                        failed++;
                    })
                    .then(function() {
                        updateOverall();
                        runNext(i + 1);
                    });
            }

            runNext(0);
        });
    })();
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════
// Next.js on-demand revalidation when post status changes
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Notify the Next.js frontend to revalidate cached data (e.g. sitemap)
 * whenever a page, post, or project is published, unpublished, or trashed.
 *
 * Requires two constants in wp-config.php:
 *   define('CDCF_FRONTEND_URL',       'https://staging.catholicdigitalcommons.org');
 *   define('CDCF_PREVIEW_SECRET',     'your-shared-secret');
 */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status === $old_status) {
        return;
    }

    $revalidatable_types    = ['page', 'post', 'project'];
    $revalidatable_statuses = ['publish', 'trash'];

    if (!in_array($post->post_type, $revalidatable_types, true)) {
        return;
    }

    if (
        !in_array($new_status, $revalidatable_statuses, true) &&
        !in_array($old_status, $revalidatable_statuses, true)
    ) {
        return;
    }

    $frontend_url = defined('CDCF_FRONTEND_URL')
        ? CDCF_FRONTEND_URL
        : 'http://localhost:3000';

    $secret = defined('CDCF_PREVIEW_SECRET')
        ? CDCF_PREVIEW_SECRET
        : '';

    if (empty($secret)) {
        return;
    }

    wp_remote_post($frontend_url . '/api/revalidate', [
        'blocking' => false,
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode([
            'secret' => $secret,
            'tags'   => ['sitemap'],
        ]),
    ]);
}, 10, 3);

// ─── API Documentation (wp-admin page with Redoc) ────────────────────

add_action('admin_menu', function () {
    add_menu_page(
        'API Docs',
        'API Docs',
        'edit_posts',
        'cdcf-api-docs',
        'cdcf_api_docs_page',
        'dashicons-rest-api',
        90
    );
});

function cdcf_api_docs_page() {
    $spec_file = get_template_directory() . '/openapi.json';
    if (!file_exists($spec_file)) {
        echo '<div class="wrap"><h1>API Documentation</h1>';
        echo '<div class="notice notice-error"><p>OpenAPI spec not found at <code>openapi.json</code> in theme directory.</p></div></div>';
        return;
    }

    $spec_json = file_get_contents($spec_file);
    ?>
    <style>
        #cdcf-api-docs { margin: 0 -20px -10px 0; }
        #cdcf-api-docs .redoc-wrap { min-height: calc(100vh - 32px); }
        #cdcf-api-docs [role="search"] { position: relative; display: flex; align-items: center; }
        #cdcf-api-docs [role="search"] svg { flex-shrink: 0; margin-left: 8px; margin-right: -26px; z-index: 1; }
        #cdcf-api-docs [role="search"] input { padding-left: 28px !important; }
    </style>
    <div id="cdcf-api-docs"></div>
    <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
    <script>
        Redoc.init(<?php echo $spec_json; ?>, {
            scrollYOffset: 32,
            hideDownloadButton: false,
            theme: {
                colors: {
                    primary: { main: '#213463' },
                },
                typography: {
                    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
                    headings: { fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif' },
                },
            },
        }, document.getElementById('cdcf-api-docs'));
    </script>
    <?php
}

// ─── Submission Meta Helper ──────────────────────────────────────────

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

// ─── Referral Submitter Meta Box ─────────────────────────────────────

/**
 * Show referral submitter info on the local_group edit screen.
 */
add_action('add_meta_boxes_local_group', function () {
    $post_id   = get_the_ID();
    $source_id = cdcf_get_source_post_id($post_id);
    $name  = get_post_meta($source_id, '_referral_submitter_name', true);
    $email = get_post_meta($source_id, '_referral_submitter_email', true);

    // Only show the meta box if the source post was submitted via the referral form.
    if (!$name && !$email) {
        return;
    }

    add_meta_box(
        'cdcf_referral_submitter',
        'Referred by',
        'cdcf_render_referral_submitter_meta_box',
        'local_group',
        'side',
        'high'
    );
});

function cdcf_render_referral_submitter_meta_box(WP_Post $post): void {
    $source_id = cdcf_get_source_post_id($post->ID);
    $name  = esc_html(get_post_meta($source_id, '_referral_submitter_name', true));
    $email = esc_html(get_post_meta($source_id, '_referral_submitter_email', true));

    if ($name) {
        echo "<p><strong>{$name}</strong></p>";
    }
    if ($email) {
        printf(
            '<p><a href="mailto:%1$s">%1$s</a></p>',
            $email
        );
    }
}

// ─── Pending Local Groups: Menu Bubble + Dashboard Widget ───────────

/**
 * Add a pending-count bubble to the Local Groups menu item.
 */
add_action('admin_menu', function () {
    global $menu;

    $count = wp_count_posts('local_group')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    $bubble = sprintf(
        ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>',
        $count
    );

    foreach ($menu as &$item) {
        // $item[2] is the menu slug; for CPTs it's "edit.php?post_type=<slug>"
        if ($item[2] === 'edit.php?post_type=local_group') {
            $item[0] .= $bubble;
            break;
        }
    }
});

/**
 * Dashboard widget showing pending local group referrals.
 */
add_action('wp_dashboard_setup', function () {
    $count = wp_count_posts('local_group')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    wp_add_dashboard_widget(
        'cdcf_pending_local_groups',
        sprintf('Pending Local Group Referrals (%d)', $count),
        'cdcf_render_pending_local_groups_widget'
    );
});

function cdcf_render_pending_local_groups_widget(): void {
    $posts = get_posts([
        'post_type'   => 'local_group',
        'post_status' => 'pending',
        'numberposts' => 10,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);

    if (empty($posts)) {
        echo '<p>No pending referrals.</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
       . '<th>Group</th><th>Submitted by</th><th>Date</th><th></th>'
       . '</tr></thead><tbody>';

    foreach ($posts as $post) {
        $name  = esc_html(get_post_meta($post->ID, '_referral_submitter_name', true));
        $email = esc_html(get_post_meta($post->ID, '_referral_submitter_email', true));
        $date  = get_the_date('M j, Y', $post);
        $edit  = get_edit_post_link($post->ID);
        $title = esc_html($post->post_title);

        $submitter = $name;
        if ($email) {
            $submitter .= $name ? " ({$email})" : $email;
        }

        echo "<tr>"
           . "<td><strong>{$title}</strong></td>"
           . "<td>{$submitter}</td>"
           . "<td>{$date}</td>"
           . "<td><a href=\"{$edit}\" class=\"button button-small\">Review</a></td>"
           . "</tr>";
    }

    echo '</tbody></table>';

    $total = wp_count_posts('local_group')->pending ?? 0;
    if ($total > 10) {
        $url = admin_url('edit.php?post_type=local_group&post_status=pending');
        printf('<p><a href="%s">View all %d pending referrals &rarr;</a></p>', $url, $total);
    }
}

// ─── Restore Public Submissions to Pending on Untrash ────────────────

/**
 * When a publicly submitted post (project or local_group) is restored
 * from trash, WordPress sets it to "draft". This hook re-sets it to
 * "pending" so it reappears in the admin dashboard widget and menu bubble.
 */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
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
}, 10, 3);

// ─── Project Submission: Meta Box ────────────────────────────────────

/**
 * Show submitter info + repo URLs on the project edit screen.
 */
add_action('add_meta_boxes_project', function () {
    $post_id   = get_the_ID();
    $source_id = cdcf_get_source_post_id($post_id);
    $name  = get_post_meta($source_id, '_submission_submitter_name', true);
    $email = get_post_meta($source_id, '_submission_submitter_email', true);

    // Only show the meta box if the source post was submitted via the public form.
    if (!$name && !$email) {
        return;
    }

    add_meta_box(
        'cdcf_project_submitter',
        'Submitted by',
        'cdcf_render_project_submitter_meta_box',
        'project',
        'side',
        'high'
    );
});

function cdcf_render_project_submitter_meta_box(WP_Post $post): void {
    $source_id = cdcf_get_source_post_id($post->ID);
    $name      = esc_html(get_post_meta($source_id, '_submission_submitter_name', true));
    $email     = esc_html(get_post_meta($source_id, '_submission_submitter_email', true));
    $repo_json = get_post_meta($source_id, '_submission_repo_urls', true);

    if ($name) {
        echo "<p><strong>{$name}</strong></p>";
    }
    if ($email) {
        printf('<p><a href="mailto:%1$s">%1$s</a></p>', $email);
    }
    if ($repo_json) {
        $repos = json_decode($repo_json, true);
        if (is_array($repos) && count($repos) > 1) {
            echo '<p style="margin-top:8px"><strong>Additional Repository URLs:</strong></p><ul style="margin:4px 0 0 16px;list-style:disc">';
            // Skip the first URL since it's already in the ACF project_repo_url field.
            foreach (array_slice($repos, 1) as $repo) {
                $safe = esc_url($repo);
                printf('<li><a href="%1$s" target="_blank" rel="noopener">%1$s</a></li>', $safe);
            }
            echo '</ul>';
        }
    }
}

// ─── Pending Projects: Menu Bubble + Dashboard Widget ────────────────

/**
 * Add a pending-count bubble to the Projects menu item.
 */
add_action('admin_menu', function () {
    global $menu;

    $count = wp_count_posts('project')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    $bubble = sprintf(
        ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>',
        $count
    );

    foreach ($menu as &$item) {
        if ($item[2] === 'edit.php?post_type=project') {
            $item[0] .= $bubble;
            break;
        }
    }
});

/**
 * Dashboard widget showing pending project submissions.
 */
add_action('wp_dashboard_setup', function () {
    $count = wp_count_posts('project')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    wp_add_dashboard_widget(
        'cdcf_pending_projects',
        sprintf('Pending Project Submissions (%d)', $count),
        'cdcf_render_pending_projects_widget'
    );
});

function cdcf_render_pending_projects_widget(): void {
    $posts = get_posts([
        'post_type'   => 'project',
        'post_status' => 'pending',
        'numberposts' => 10,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);

    if (empty($posts)) {
        echo '<p>No pending project submissions.</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
       . '<th>Project</th><th>Submitted by</th><th>Date</th><th></th>'
       . '</tr></thead><tbody>';

    foreach ($posts as $post) {
        $name  = esc_html(get_post_meta($post->ID, '_submission_submitter_name', true));
        $email = esc_html(get_post_meta($post->ID, '_submission_submitter_email', true));
        $date  = get_the_date('M j, Y', $post);
        $edit  = get_edit_post_link($post->ID);
        $title = esc_html($post->post_title);

        $submitter = $name;
        if ($email) {
            $submitter .= $name ? " ({$email})" : $email;
        }

        echo "<tr>"
           . "<td><strong>{$title}</strong></td>"
           . "<td>{$submitter}</td>"
           . "<td>{$date}</td>"
           . "<td><a href=\"{$edit}\" class=\"button button-small\">Review</a></td>"
           . "</tr>";
    }

    echo '</tbody></table>';

    $total = wp_count_posts('project')->pending ?? 0;
    if ($total > 10) {
        $url = admin_url('edit.php?post_type=project&post_status=pending');
        printf('<p><a href="%s">View all %d pending submissions &rarr;</a></p>', $url, $total);
    }
}
