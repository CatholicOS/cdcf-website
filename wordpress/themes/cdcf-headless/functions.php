<?php
/**
 * CDCF Headless Theme — functions.php
 *
 * Registers CPTs, ACF field groups, Polylang config,
 * CORS for GraphQL, and preview URL hooks.
 */

// ─── OPcache Invalidation Endpoint ──────────────────────────────────
//
// POST /wp-json/cdcf/v1/flush-opcache (Application Password auth)
// Invalidates OPcache for this file so new CPT registrations take effect
// after deploy without waiting for the OPcache TTL.
//
// Handler body lives in includes/handlers/flush-opcache.php so it can
// be unit-tested in isolation (Brain Monkey + Mockery). The CDCF_FUNCTIONS_FILE
// constant captures the runtime path to this file so the extracted
// handler can pass it to opcache_invalidate().

if (!defined('CDCF_FUNCTIONS_FILE')) {
    define('CDCF_FUNCTIONS_FILE', __FILE__);
}

require_once __DIR__ . '/includes/handlers/flush-opcache.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/flush-opcache', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_flush_opcache',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
});

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
            'menu_name'     => __('CDCF Projects', 'cdcf-headless'),
            'all_items'     => __('CDCF Projects', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'project',
        'graphql_plural_name' => 'projects',
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'menu_icon'    => 'dashicons-portfolio',
        'show_in_menu' => 'cdcf-projects',
        'has_archive'  => false,
        'rewrite'      => ['slug' => 'projects'],
    ]);

    // Team Member
    register_post_type('team_member', [
        'labels' => [
            'name'          => __('Team Members', 'cdcf-headless'),
            'singular_name' => __('Team Member', 'cdcf-headless'),
            'add_new'       => __('Add New Team Member', 'cdcf-headless'),
            'add_new_item'  => __('Add New Team Member', 'cdcf-headless'),
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
        'show_in_menu' => 'cdcf-community',
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
        'show_in_menu' => 'cdcf-community',
        'has_archive'  => false,
    ]);

    // Academic Collaboration
    register_post_type('acad_collab', [
        'labels' => [
            'name'          => __('Academic Collaborations', 'cdcf-headless'),
            'singular_name' => __('Academic Collaboration', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'academicCollaboration',
        'graphql_plural_name' => 'academicCollaborations',
        'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'menu_icon'    => 'dashicons-welcome-learn-more',
        'show_in_menu' => 'cdcf-community',
        'has_archive'  => false,
        // Align the permalink base with the Next.js route
        // (/academic-collaborations/<slug>) so the headless "View" link, built
        // from the registered rewrite slug, points at the live frontend.
        'rewrite'      => ['slug' => 'academic-collaborations'],
    ]);

    // Community Project
    register_post_type('community_project', [
        'labels' => [
            'name'          => __('Community Projects', 'cdcf-headless'),
            'singular_name' => __('Community Project', 'cdcf-headless'),
        ],
        'public'       => true,
        'show_in_rest'  => true,
        'show_in_graphql' => true,
        'graphql_single_name' => 'communityProject',
        'graphql_plural_name' => 'communityProjects',
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'menu_icon'    => 'dashicons-portfolio',
        'show_in_menu' => 'cdcf-projects',
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

    // Project Tag taxonomy (shared by project and community_project)
    register_taxonomy('project_tag', ['project', 'community_project'], [
        'labels' => [
            'name'          => __('Project Tags', 'cdcf-headless'),
            'singular_name' => __('Project Tag', 'cdcf-headless'),
        ],
        'public'              => true,
        'show_in_rest'        => true,
        'show_in_graphql'     => true,
        'graphql_single_name' => 'projectTag',
        'graphql_plural_name' => 'projectTags',
        'hierarchical'        => false,
    ]);
});

// ─── Admin sidebar grouping: Projects + Community parents ───────────
//
// The "Projects" parent groups the project + community_project CPTs;
// "Community" groups community_channel, local_group, and acad_collab.
// Each CPT's show_in_menu points at one of the parent slugs below.
// Clicking a parent redirects to its first child's list screen.

add_action('admin_menu', function () {
    $projects_hook = add_menu_page(
        __('Projects', 'cdcf-headless'),
        __('Projects', 'cdcf-headless'),
        'edit_posts',
        'cdcf-projects',
        '__return_null',
        'dashicons-portfolio',
        25
    );
    $community_hook = add_menu_page(
        __('Community', 'cdcf-headless'),
        __('Community', 'cdcf-headless'),
        'edit_posts',
        'cdcf-community',
        '__return_null',
        'dashicons-networking',
        26
    );

    add_action("load-{$projects_hook}", function () {
        wp_safe_redirect(admin_url('edit.php?post_type=project'));
        exit;
    });
    add_action("load-{$community_hook}", function () {
        wp_safe_redirect(admin_url('edit.php?post_type=community_channel'));
        exit;
    });
}, 9);

// Remove the auto-injected duplicate first submenu item that mirrors
// each parent (WP adds one labeled with the parent's menu_title at
// render time). Run late so it executes after CPT submenus register.
add_action('admin_menu', function () {
    remove_submenu_page('cdcf-projects', 'cdcf-projects');
    remove_submenu_page('cdcf-community', 'cdcf-community');
}, 999);

// ─── Team Members: council submenus (Board / Ecclesial / Technical) ─
//
// Hook bodies live in includes/admin/team-member-council.php so they
// can be unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/admin/team-member-council.php';

add_action('admin_menu', 'cdcf_register_team_member_council_submenus', 11);
add_action('pre_get_posts', 'cdcf_filter_team_member_council_query');
add_filter('submenu_file', 'cdcf_highlight_team_member_council_submenu');

// ─── Polylang: seed each admin's language filter to the default lang ─
//
// Hook body lives in includes/admin/polylang-default-seed.php so it can
// be unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/admin/polylang-default-seed.php';

add_action('admin_init', 'cdcf_seed_polylang_default_language');

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
//
// Handler bodies live in includes/handlers/relationship.php so they
// can be unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/handlers/relationship.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/relationship', [
        [
            'methods'             => 'GET',
            'callback'            => 'cdcf_rest_get_relationship',
            'permission_callback' => 'cdcf_relationship_permission_check',
            'args' => [
                'post_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'field'   => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'cdcf_rest_update_relationship',
            'permission_callback' => 'cdcf_relationship_permission_check',
            'args' => [
                'post_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'field'   => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'value'   => ['required' => true, 'type' => 'array'],
            ],
        ],
    ]);
});

// ─── REST endpoint for linking Polylang translations ─────────────────
//
// Links existing posts as Polylang translations of each other.
//
// POST /wp-json/cdcf/v1/link-translations (Application Password auth)
// Body: { "translations": { "en": 544, "it": 546, "es": 547, ... } }

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/link-translations', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_link_translations',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'translations' => ['required' => true, 'type' => 'object'],
        ],
    ]);
});

require_once __DIR__ . '/includes/handlers/link-translations.php';

// ─── REST endpoint for updating project status across translations ───
//
// Sets the project_status ACF field on a project and all its Polylang
// translations in a single call.
//
// POST /wp-json/cdcf/v1/project-status (Application Password auth)
// Body: { "post_id": 123, "status": "incubating" }

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/project-status', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_update_project_status',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'post_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
            'status'  => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);
});

require_once __DIR__ . '/includes/handlers/project-status.php';

// ─── REST endpoint for creating a team member with translations ──────
//
// Creates an English team_member post, translates it to all configured
// languages via OpenAI, and appends each translation to the correct
// language version of the About page's relationship field (council).
//
// POST /wp-json/cdcf/v1/team-member (Application Password auth)
//
// Handler body lives in includes/handlers/team-member.php so it can
// be unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/handlers/team-member.php';

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
            'council'            => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'featured_image_id'  => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
            'collab_post_id'     => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
        ],
    ]);
});

// ─── REST endpoint for provisioning a low-privilege WordPress user ───
//
// Creates an author / contributor / subscriber user with a server-generated
// password and sends the standard set-password email. Unlike every other
// cdcf/v1 endpoint (editor baseline), this one gates on the custom
// `cdcf_create_limited_users` capability and hard-codes a role allowlist —
// see includes/handlers/create-user.php and includes/admin/limited-user-
// provisioning.php for the security rationale. Native `create_users` is
// never granted, so core POST /wp/v2/users stays 403 for the bot.
//
// POST /wp-json/cdcf/v1/create-user (Application Password auth)

require_once __DIR__ . '/includes/handlers/create-user.php';
require_once __DIR__ . '/includes/admin/limited-user-provisioning.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/create-user', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_create_user',
        'permission_callback' => function () {
            return current_user_can('cdcf_create_limited_users');
        },
        'args' => [
            'username'     => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_user'],
            'email'        => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'role'         => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            'display_name' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'first_name'   => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'last_name'    => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            // Optional: link the new author to their team_member bio card in
            // one call (best-effort — see cdcf_rest_create_user). 0 = no link.
            'team_member_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
        ],
    ]);
});

// Grant the custom cap from the per-user meta flag, and expose the
// admin-only toggle that sets it (handlers in includes/admin/).
add_filter('user_has_cap', 'cdcf_grant_limited_user_provisioning', 10, 4);
add_action('edit_user_profile', 'cdcf_render_limited_user_provisioning_field');
add_action('edit_user_profile_update', 'cdcf_save_limited_user_provisioning_field');

// ─── REST endpoint for linking an author to their team_member bio card ─
//
// Writes the `author_team_member` ACF relationship on the USER object so
// author pages reuse the team_member's translated bio/photo/role/socials.
// This needs a dedicated endpoint: /relationship is post-only, and ACF
// 6.x free doesn't expose user-located field groups via the core REST
// `acf` property (a PUT is silently dropped). See the handler file.
// Gated on the same author-provisioning capability as create-user.
//
// POST /wp-json/cdcf/v1/author-team-member (Application Password auth)

require_once __DIR__ . '/includes/handlers/author-team-member.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/author-team-member', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_link_author_team_member',
        'permission_callback' => function () {
            return current_user_can('cdcf_create_limited_users');
        },
        'args' => [
            'user_id'        => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
            // 0 clears the link; a positive id must reference a team_member post.
            'team_member_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
        ],
    ]);
});

// ─── REST endpoint for creating a community channel with translations ─
//
// Creates an English community_channel post, translates it to all configured
// languages via OpenAI, and appends each translation to the correct
// language version of the Community page's channels relationship field.
//
// POST /wp-json/cdcf/v1/community-channel (Application Password auth)
//
// Handler body lives in includes/handlers/community-channel.php so it can
// be unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/handlers/community-channel.php';

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

// ─── REST endpoint for creating a local group with translations ──────
//
// Creates an English local_group post, translates it to all configured
// languages via OpenAI, and appends each translation to the correct
// language version of the Community page's local_groups relationship field.
//
// POST /wp-json/cdcf/v1/local-group (Application Password auth)
//
// Handler body lives in includes/handlers/local-group.php so it can be
// unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/handlers/local-group.php';

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

// ─── REST endpoint for creating an academic collaboration with translations ──
//
// Creates an English academic_collaboration post, translates it to all
// configured languages via OpenAI, and appends each translation to the
// correct language version of the Community page's academic_collaborations
// relationship field.
//
// POST /wp-json/cdcf/v1/academic-collaboration (Application Password auth)
//
// Handler body lives in includes/handlers/academic-collaboration.php so it
// can be unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/handlers/academic-collaboration.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/academic-collaboration', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_create_academic_collaboration',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'title'              => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'collab_description' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field'],
            'collab_university'  => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'collab_department'  => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'collab_location'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'collab_website_url' => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'featured_image_id'  => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
        ],
    ]);
});

// ─── Update Disposable Domains List ─────────────────────────────────
//
// POST /wp-json/cdcf/v1/update-disposable-domains (Application Password auth)
// Downloads the latest disposable email domain blocklist from the
// disposable-email-domains community list on GitHub and writes it to
// the theme directory.
//
// Handler body lives in includes/handlers/update-disposable-domains.php
// so it can be unit-tested in isolation (Brain Monkey + Mockery). The
// target file path is read from the CDCF_DISPOSABLE_DOMAINS_FILE
// constant so tests can redirect writes to a tmp path via the test
// bootstrap.

if (!defined('CDCF_DISPOSABLE_DOMAINS_FILE')) {
    define('CDCF_DISPOSABLE_DOMAINS_FILE', __DIR__ . '/disposable-domains.txt');
}

require_once __DIR__ . '/includes/handlers/update-disposable-domains.php';

// Spam-protection helpers (cdcf_check_ip_rbl, cdcf_is_disposable_email,
// cdcf_is_spam_content) used by every public-submission endpoint below.
// Required here — after CDCF_DISPOSABLE_DOMAINS_FILE is defined — so
// the disposable-domain lookup can read the blocklist file path.
require_once __DIR__ . '/includes/security.php';

// Footnote/fragment-anchor protection helper, applied at every wp_kses_post
// content sink below so colon-bearing anchors (#fn:…/#fnref:…) survive KSES.
require_once __DIR__ . '/includes/fragment-anchors.php';

// Zitadel bearer-token authenticator. Lets the Next.js frontend authenticate
// WP REST writes for the team-member bio self-edit flow without an
// Application Password round-trip. Priority 20 keeps it AFTER core's cookie
// and Application Password resolvers, so existing auth paths are untouched.
require_once __DIR__ . '/includes/auth/zitadel-bearer.php';
add_filter('determine_current_user', 'cdcf_zitadel_bearer_authenticate', 20);

// Bio self-edit endpoints — discovery + per-language edit. Authorization
// piggybacks on the author_team_member ACF link an admin sets via the
// /cdcf/v1/author-team-member endpoint; the bearer authenticator above
// resolves the WP user from the Zitadel access token.
require_once __DIR__ . '/includes/handlers/my-team-member.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/my-team-member', [
        'methods'             => 'GET',
        'callback'            => 'cdcf_rest_get_my_team_member',
        'permission_callback' => 'cdcf_rest_my_team_member_permission',
    ]);
    // GET reads + PATCH edits use the same ownership invariant (the
    // `author_team_member` link must point at SOME post in the Polylang
    // group containing {lang}'s post). Registering both methods on the
    // same path keeps URL contract + permission_callback consistent;
    // each method has its own args declaration so body sanitization
    // stays per-verb.
    register_rest_route('cdcf/v1', '/my-team-member/(?P<lang>[a-z]{2})', [
        [
            'methods'             => 'GET',
            'callback'            => 'cdcf_rest_get_my_team_member_lang',
            'permission_callback' => 'cdcf_rest_my_team_member_permission',
            'args' => [
                'lang' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static fn($v): bool => is_string($v) && (bool) preg_match('/^[a-z]{2}$/', $v),
                ],
            ],
        ],
        [
            'methods'             => 'PATCH',
            'callback'            => 'cdcf_rest_update_my_team_member',
            'permission_callback' => 'cdcf_rest_my_team_member_permission',
            'args' => [
                // The URL regex already constrains lang to two lowercase
                // letters; declaring it here adds sanitize/validate parity
                // with the other args (per AGENTS.md "Sanitization
                // convention") and gives a typed entry the handler reads
                // via $request['lang'].
                'lang' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static fn($v): bool => is_string($v) && (bool) preg_match('/^[a-z]{2}$/', $v),
                ],
                // Hostname allowlists for the social URLs are enforced in
                // the handler body (esc_url_raw can't constrain hostnames).
                // Empty string is allowed throughout: it clears the field.
                'content'             => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'wp_kses_post'],
                'member_title'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'member_linkedin_url' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
                'member_github_url'   => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            ],
        ],
    ]);
});

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/update-disposable-domains', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_update_disposable_domains',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ]);
});

// ─── Public Referral Endpoint ────────────────────────────────────────
//
// Allows visitors to submit a local group referral for admin review.
// Creates a pending local_group post and sends an admin notification email.
//
// POST /wp-json/cdcf/v1/refer-local-group (public — no auth required)
//
// Handler bodies live in includes/handlers/ so they can be
// unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/handlers/refer-local-group.php';
require_once __DIR__ . '/includes/handlers/send-verification-code.php';

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

// Spam-protection helpers (cdcf_check_ip_rbl, cdcf_is_disposable_email,
// cdcf_is_spam_content) live in includes/security.php, required above.

// cdcf_rest_send_verification_code() lives in includes/handlers/send-verification-code.php

// cdcf_rest_refer_local_group() lives in includes/handlers/refer-local-group.php

// ─── Public Community Project Referral Endpoint ──────────────────────
//
// Allows visitors to refer a community project for admin review.
// Creates a pending community_project post and sends an admin notification email.
//
// POST /wp-json/cdcf/v1/refer-community-project (public — no auth required)
//
// Handler body lives in includes/handlers/refer-community-project.php.
// The shared /send-code handler was already required above for
// /refer-local-group; no second require needed here.

require_once __DIR__ . '/includes/handlers/refer-community-project.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/refer-community-project', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_refer_community_project',
        'permission_callback' => '__return_true',
        'args' => [
            'project_name'      => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'description'       => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'category'          => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'project_url'       => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'github_url'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'tags'              => ['required' => false, 'type' => 'array',  'default' => []],
            'submitter_name'    => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'submitter_email'   => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'verification_code' => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);

    register_rest_route('cdcf/v1', '/refer-community-project/send-code', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_send_verification_code',
        'permission_callback' => '__return_true',
        'args' => [
            'project_name'    => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'description'     => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'category'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'project_url'     => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'github_url'      => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'tags'            => ['required' => false, 'type' => 'array',  'default' => []],
            'submitter_name'  => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'submitter_email' => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'honeypot'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'elapsed_ms'      => ['required' => false, 'type' => 'number', 'default' => 0],
        ],
    ]);
});

// cdcf_rest_refer_community_project() lives in includes/handlers/refer-community-project.php

// ─── Public Project Submission Endpoint ───────────────────────────────
//
// Allows visitors to submit an open-source project for admin review.
// Creates a pending project post and sends an admin notification email.
//
// POST /wp-json/cdcf/v1/submit-project (public — no auth required)
//
// Handler bodies live in includes/handlers/. /submit-project/send-code
// has its own helper (separate IP-rate-limit transient prefix) — see
// the docblock in submit-project-send-code.php.

require_once __DIR__ . '/includes/handlers/submit-project.php';
require_once __DIR__ . '/includes/handlers/submit-project-send-code.php';

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/submit-project', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_submit_project',
        'permission_callback' => '__return_true',
        'args' => [
            'project_name'      => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'category'          => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'description'       => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'url'               => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'repo_urls'         => ['required' => false, 'type' => 'array',  'default' => []],
            'tags'              => ['required' => false, 'type' => 'array',  'default' => []],
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
            'category'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'description'     => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            'url'             => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'repo_urls'       => ['required' => false, 'type' => 'array',  'default' => []],
            'tags'            => ['required' => false, 'type' => 'array',  'default' => []],
            'submitter_name'  => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'submitter_email' => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'honeypot'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'elapsed_ms'      => ['required' => false, 'type' => 'number', 'default' => 0],
        ],
    ]);
});

// cdcf_rest_submit_project_send_code() lives in includes/handlers/submit-project-send-code.php

// cdcf_rest_submit_project() lives in includes/handlers/submit-project.php

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
                    'archived'   => 'Archived',
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
            [
                'key'   => 'field_project_leads',
                'label' => 'Project Leads',
                'name'  => 'project_leads',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
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
                'label' => 'Role (unused)',
                'name'  => 'member_role',
                'type'  => 'text',
                'instructions' => 'Currently not displayed anywhere on the site. Leave empty. (Use the Title field for the line shown under the name.)',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_member_title',
                'label' => 'Title',
                'name'  => 'member_title',
                'type'  => 'text',
                'instructions' => 'Subheader shown directly under the name on the bio card (and as the subtitle on the author page) — the person\'s position or affiliation, e.g. "Foundation President" or "Professor of Theology, University of Notre Dame". Not an honorific prefix ("Dr.", "Rev.").',
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

    // ── Author Profile (User) Fields ──
    // Links a WordPress user (an article author) to their Team Member entry so
    // author pages reuse the team_member's translated bio (its content), photo
    // (featured image), role, and social links across all locales. Optional —
    // authors without a link fall back to core user fields (display name,
    // Biographical Info, Gravatar).
    acf_add_local_field_group([
        'key'   => 'group_author_profile',
        'title' => 'Author Profile',
        'fields' => [
            [
                'key'          => 'field_author_team_member',
                'label'        => 'Team Member Profile',
                'name'         => 'author_team_member',
                'type'         => 'relationship',
                'instructions' => 'Optional. Link this author to their Team Member entry to show a translated bio, photo, role, and social links on their author page.',
                'post_type'    => ['team_member'],
                'max'          => 1,
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'user_form', 'operator' => '==', 'value' => 'all']],
        ],
        // NOTE: ACF 6.x (free) does NOT expose user-located field groups via
        // the core REST `acf` property regardless of show_in_rest, so this
        // field stays GraphQL-only. To set author_team_member programmatically
        // use POST /cdcf/v1/author-team-member (update_field on "user_{id}").
        'show_in_graphql' => true,
        'graphql_field_name' => 'authorProfile',
        'graphql_types' => ['User'],
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

    // ── Academic Collaboration CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_academic_collaboration',
        'title' => 'Collaboration Details',
        'fields' => [
            [
                'key'   => 'field_collab_university',
                'label' => 'University',
                'name'  => 'collab_university',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_collab_department',
                'label' => 'Department',
                'name'  => 'collab_department',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_collab_description',
                'label' => 'Description',
                'name'  => 'collab_description',
                'type'  => 'textarea',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_collab_location',
                'label' => 'Location',
                'name'  => 'collab_location',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_collab_website_url',
                'label' => 'Website URL',
                'name'  => 'collab_website_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_collab_projects',
                'label' => 'Related Projects',
                'name'  => 'collab_projects',
                'type'  => 'relationship',
                'post_type' => ['project'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_collab_governance',
                'label' => 'Governance Contacts',
                'name'  => 'collab_governance',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'acad_collab']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'collaborationFields',
    ]);

    // ── Community Project CPT Fields ──

    acf_add_local_field_group([
        'key'   => 'group_community_project',
        'title' => 'Community Project Details',
        'fields' => [
            [
                'key'   => 'field_community_project_category',
                'label' => 'Category',
                'name'  => 'project_category',
                'type'  => 'text',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_community_project_url',
                'label' => 'Project URL',
                'name'  => 'project_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_community_project_github_url',
                'label' => 'GitHub URL',
                'name'  => 'project_github_url',
                'type'  => 'url',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'community_project']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'communityProjectFields',
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
            [
                'key'   => 'field_projects_community_projects',
                'label' => 'Community Projects',
                'name'  => 'community_projects',
                'type'  => 'relationship',
                'post_type' => ['community_project'],
                'return_format' => 'object',
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
                'key'   => 'field_community_academic_collaborations',
                'label' => 'Academic Collaborations',
                'name'  => 'academic_collaborations',
                'type'  => 'relationship',
                'post_type' => ['acad_collab'],
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
    $templates['templates/governance-toc.php'] = 'Governance TOC';
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
    $post_types['acad_collab'] = 'acad_collab';
    $post_types['community_project'] = 'community_project';
    return $post_types;
}, 10, 2);

add_filter('pll_get_taxonomies', function ($taxonomies) {
    $taxonomies['project_tag'] = 'project_tag';
    return $taxonomies;
}, 10, 2);

// ─── Custom GraphQL fields ──────────────────────────────────────────

add_action('graphql_register_types', function () {
    register_graphql_field('Project', 'projectRepoUrls', [
        'type'        => ['list_of' => 'String'],
        'description' => 'All repository URLs for the project',
        'resolve'     => function ($project) {
            $post_id = $project->databaseId;

            // Try private meta first (JSON array from submission form).
            $json = get_post_meta($post_id, '_submission_repo_urls', true);
            if ($json) {
                $urls = json_decode($json, true);
                if (is_array($urls) && !empty($urls)) {
                    return $urls;
                }
            }

            // Fall back to the single ACF repo URL field.
            $single = get_field('project_repo_url', $post_id);
            if ($single) {
                return [$single];
            }

            return null;
        },
    ]);
});

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

// ─── Published permalink → Next.js frontend ─────────────────────────
// Make the admin "View Post"/"View Page" links (and admin bar / post-list /
// block-editor permalink) point at the live headless frontend instead of the
// dead WP-side URL, for every post type with a frontend route. Bodies live in
// includes/frontend-permalinks.php so they're testable.
require_once __DIR__ . '/includes/frontend-permalinks.php';

add_filter('post_link', 'cdcf_frontend_permalink', 10, 2);      // posts
add_filter('page_link', 'cdcf_frontend_permalink', 10, 2);      // pages
add_filter('post_type_link', 'cdcf_frontend_permalink', 10, 2); // project, acad_collab

// ─── Preview URL → Next.js draft mode ────────────────────────────────

add_filter('preview_post_link', function ($preview_link, $post) {
    // Only post and page have by-id preview support on the Next.js frontend
    // (the blog route and the catch-all page route). Other public CPTs
    // (project, team_member, academic_collaboration, …) have no by-id preview
    // path, so leave their preview link untouched rather than redirect to a
    // 404 on the headless frontend.
    if (!in_array($post->post_type, CDCF_FRONTEND_PREVIEWABLE_TYPES, true)) {
        return $preview_link;
    }
    return cdcf_build_frontend_preview_url($post);
}, 10, 2);

// The editor's "View" link / hamburger menu / post-list "View" row action on
// a draft all read get_permalink(). The permalink filter routes them at this
// admin-post.php handler instead of the default ?p=<id> link. admin_post_
// prefix means WP core gates the request as logged-in only; the handler
// further capability-checks edit_post before redirecting to the frontend
// /api/preview URL (secret added server-side, never embedded in the permalink).
add_action('admin_post_cdcf_preview_redirect', 'cdcf_redirect_to_frontend_preview');

// ─── Application Password auth for WPGraphQL ─────────────────────────
// WordPress core only honours Application Passwords on requests it considers
// "API requests" (REST and XML-RPC). WPGraphQL's /graphql endpoint is neither,
// so without this the headless frontend cannot authenticate to fetch draft /
// preview content. Opt /graphql in so Basic-auth app passwords work there too.
add_filter('application_password_is_api_request', function ($is_api_request) {
    if ($is_api_request) {
        return true;
    }

    $uri = isset($_SERVER['REQUEST_URI'])
        ? wp_unslash($_SERVER['REQUEST_URI'])
        : '';

    return is_string($uri) && strpos($uri, '/graphql') !== false;
});

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
        // Status polling: after the AJAX enqueue returns, the badge starts
        // at "⏳ Queued" and we poll GET /cdcf/v1/translation-status until
        // the worker reports "completed" / "failed", at which point the
        // badge flips. The status meta is written by the worker
        // (cdcf_process_translation) so this poll is a thin read.
        var STATUS_POLL_URL = '<?php echo esc_url_raw(rest_url('cdcf/v1/translation-status')); ?>';
        var STATUS_NONCE    = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
        var EDIT_URL_BASE   = '<?php echo admin_url('post.php?action=edit&post='); ?>';
        var POLL_INTERVAL_MS = 5000;
        var POLL_MAX_ATTEMPTS = 60; // ≈5 min before giving up + leaving "Queued"

        function setBadgeQueued(status, postId) {
            // postId may be 0 (target post hadn't been resolved yet); only
            // emit the Edit link when we actually have one.
            status.style.color = '#0073aa';
            status.textContent = '⏳ Queued';
            if (postId) {
                status.textContent = '⏳ Queued — ';
                var a = document.createElement('a');
                a.href = EDIT_URL_BASE + encodeURIComponent(postId);
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = 'Edit';
                status.appendChild(a);
            }
        }

        function setBadgeCompleted(status, postId) {
            status.style.color = '#46b450';
            status.textContent = '✅ Done';
            if (postId) {
                status.textContent = '✅ Done — ';
                var a = document.createElement('a');
                a.href = EDIT_URL_BASE + encodeURIComponent(postId);
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = 'Edit';
                status.appendChild(a);
            }
        }

        function setBadgeFailed(status, message) {
            status.style.color = '#dc3232';
            // textContent (not innerHTML) — server-returned error strings
            // are not trusted as markup.
            status.textContent = '❌ Failed' + (message ? ': ' + message : '');
        }

        function pollStatus(btn, status, postId) {
            if (!postId) return;
            var attempts = 0;
            var timer = setInterval(function() {
                attempts++;
                if (attempts > POLL_MAX_ATTEMPTS) {
                    clearInterval(timer);
                    return; // leave the "Queued" badge so the user can reload
                }
                fetch(STATUS_POLL_URL + '?post_id=' + encodeURIComponent(postId), {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': STATUS_NONCE },
                })
                    .then(function(r) { return r.ok ? r.json() : null; })
                    .then(function(resp) {
                        if (!resp || !resp.status) return;
                        if (resp.status === 'completed' || resp.status === 'unknown') {
                            clearInterval(timer);
                            setBadgeCompleted(status, postId);
                            btn.disabled = false;
                        } else if (resp.status === 'failed') {
                            clearInterval(timer);
                            setBadgeFailed(status, resp.error || '');
                            btn.disabled = false;
                        }
                        // "enqueued" / "processing" → keep polling
                    })
                    .catch(function() { /* transient network blip — try again next tick */ });
            }, POLL_INTERVAL_MS);
        }

        function translateOne(btn) {
            var status = btn.parentElement.querySelector('.cdcf-ai-translate-status');
            btn.disabled = true;
            status.textContent = 'Queuing…';
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
                        var postId = (resp.data && resp.data.post_id) ? resp.data.post_id : 0;
                        if (postId) {
                            btn.dataset.postId = postId;
                        }
                        setBadgeQueued(status, postId);
                        pollStatus(btn, status, postId);
                    } else {
                        status.textContent = resp.data || 'Error';
                        status.style.color = '#dc3232';
                        btn.disabled = false;
                        throw new Error(resp.data);
                    }
                })
                .catch(function(err) {
                    if (!status.textContent || status.textContent === 'Queuing…') {
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

        // "Translate All" button — POST to cdcf_ai_translate_all so the five
        // target-language drafts are created and linked into Polylang in ONE
        // atomic call. Replaces the prior fan-out of five concurrent
        // cdcf_ai_translate requests, which lost-update the Polylang
        // translation group (read-modify-write race; 2-3 of 5 languages
        // ended up orphaned). The server returns the per-language post_ids
        // map; we then start each per-language status poll the same way the
        // single-language flow does, so the UI behaviour is unchanged.
        var allBtn = document.getElementById('cdcf-ai-translate-all');
        if (allBtn) {
            allBtn.addEventListener('click', function() {
                if (!confirm('This will queue translations for ALL languages (existing ones are overwritten when the worker runs). Continue?')) return;
                allBtn.disabled = true;
                var buttons = Array.from(document.querySelectorAll('.cdcf-ai-translate-btn'));
                buttons.forEach(function(btn) {
                    var s = btn.parentElement.querySelector('.cdcf-ai-translate-status');
                    btn.disabled = true;
                    s.textContent = 'Queuing…';
                    s.style.color = '#0073aa';
                });

                // sourceId is the same on every per-language button — they all
                // descend from the same source post — so just read it off
                // the first one.
                var sourceId = buttons.length > 0 ? buttons[0].dataset.sourceId : '';
                var data = new FormData();
                data.append('action', 'cdcf_ai_translate_all');
                data.append('source_id', sourceId);
                data.append('_wpnonce', document.getElementById('cdcf_ai_translate_nonce').value);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) {
                            allBtn.textContent = 'Failed to queue: ' + (resp.data || 'unknown error');
                            allBtn.disabled = false;
                            buttons.forEach(function(btn) {
                                var s = btn.parentElement.querySelector('.cdcf-ai-translate-status');
                                s.textContent = '';
                                btn.disabled = false;
                            });
                            return;
                        }
                        var postIds = (resp.data && resp.data.post_ids) || {};
                        var serverErrors = (resp.data && resp.data.errors) || [];
                        var queuedCount = 0;
                        buttons.forEach(function(btn) {
                            var lang = btn.dataset.targetLang;
                            var pid = postIds[lang];
                            var s = btn.parentElement.querySelector('.cdcf-ai-translate-status');
                            if (pid) {
                                btn.dataset.postId = pid;
                                setBadgeQueued(s, pid);
                                pollStatus(btn, s, pid);
                                queuedCount++;
                            } else {
                                // Server reported a per-language create failure (rare;
                                // recorded in resp.data.errors). Surface it on that
                                // language's badge.
                                setBadgeFailed(s, 'enqueue failed');
                                btn.disabled = false;
                            }
                        });
                        if (queuedCount === buttons.length) {
                            allBtn.textContent = 'All queued — translations will appear shortly.';
                        } else {
                            allBtn.textContent = (buttons.length - queuedCount) + ' of ' + buttons.length + ' failed to queue — see per-language status.';
                            allBtn.disabled = false;
                        }
                        if (serverErrors.length) {
                            console.warn('cdcf_ai_translate_all server-side errors:', serverErrors);
                        }
                    })
                    .catch(function(err) {
                        allBtn.textContent = 'Failed to queue (network error)';
                        allBtn.disabled = false;
                        buttons.forEach(function(btn) {
                            var s = btn.parentElement.querySelector('.cdcf-ai-translate-status');
                            s.textContent = '';
                            btn.disabled = false;
                        });
                        console.error(err);
                    });
            });
        }
    })();
    </script>
    <?php
}

// ── AJAX handler ──
//
// Handler body lives in includes/admin/ai-translate.php so it can be
// unit-tested in isolation (Brain Monkey + Mockery).
require_once __DIR__ . '/includes/admin/ai-translate.php';
add_action('wp_ajax_cdcf_ai_translate', 'cdcf_ajax_ai_translate');

// ─── Background translation processor (WP Cron) ─────────────────────
//
// Pipeline (chunking, OpenAI client + bounded retries, post-write
// orchestrator) lives in includes/translation.php so it can be
// unit-tested in isolation. Required here — after CDCF_TRANSLATABLE_ACF_TYPES
// and CDCF_LOCALE_NAMES are defined above — because the orchestrator
// consults both at runtime.
require_once __DIR__ . '/includes/translation-status.php';
require_once __DIR__ . '/includes/translation.php';
add_action('cdcf_async_translate', 'cdcf_process_translation', 10, 3);

// ─── REST endpoint for AI translation ────────────────────────────────
//
// Mirrors the admin-ajax cdcf_ai_translate handler but uses REST API
// authentication (Application Passwords) instead of cookie + nonce.
//
// POST /wp-json/cdcf/v1/translate { source_id: 255, target_lang: "it", post_id: 0 }
//
// Handler body lives in includes/handlers/translate.php so it can be
// unit-tested in isolation (Brain Monkey + Mockery).

require_once __DIR__ . '/includes/handlers/translate.php';
require_once __DIR__ . '/includes/handlers/translate-all.php';
require_once __DIR__ . '/includes/handlers/deploy-translation.php';
require_once __DIR__ . '/includes/handlers/translation-status.php';
add_action('wp_ajax_cdcf_ai_translate_all', 'cdcf_ajax_ai_translate_all');

add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/translate', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => 'cdcf_rest_translate',
        'args' => [
            'source_id'   => ['required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint'],
            'target_lang' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'post_id'     => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
        ],
    ]);

    register_rest_route('cdcf/v1', '/translate-all', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => 'cdcf_rest_translate_all',
        'args' => [
            'source_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
        ],
    ]);

    register_rest_route('cdcf/v1', '/deploy-translation', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => 'cdcf_rest_deploy_translation',
        'args' => [
            'source_id'   => ['required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint'],
            'target_lang' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'title'       => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'content'     => ['required' => true,  'type' => 'string'],
        ],
    ]);

    register_rest_route('cdcf/v1', '/translation-status', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => 'cdcf_rest_translation_status',
        'args' => [
            'post_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
        ],
    ]);
});


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
        <p>Translating <strong><?php echo (int) count($post_ids); ?></strong> media item(s)
           into <strong><?php echo (int) count($target_langs); ?></strong> language(s).
           Total API calls: <strong><?php echo (int) (count($post_ids) * count($target_langs)); ?></strong>.</p>

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

            // Mark all tasks as in-progress
            tasks.forEach(function(task) {
                task.cell.textContent = '…';
                task.cell.style.color = '#0073aa';
            });
            updateOverall();

            Promise.all(tasks.map(function(task) {
                var data = new FormData();
                data.append('action', 'cdcf_ai_translate');
                data.append('source_id', task.sourceId);
                data.append('target_lang', task.targetLang);
                data.append('post_id', task.postId);
                data.append('_wpnonce', nonce);

                return fetch(ajaxurl, { method: 'POST', body: data })
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
                    });
            })).then(function() {
                overallStatus.textContent = 'Complete! ' + done + ' translated' + (failed ? ', ' + failed + ' failed' : '') + '.';
                startBtn.textContent = 'Done';
            });
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
 *   define('CDCF_FRONTEND_URL',       'https://catholicdigitalcommons.org');
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
        : (getenv('WP_PREVIEW_SECRET') ?: '');

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

// ─── Submission Lifecycle (helpers + transition hooks) ──────────────
//
// Helpers (cdcf_get_source_post_id, cdcf_is_public_submission,
// cdcf_enqueue_translations_for_submission) + the two
// transition_post_status callbacks below live in
// includes/admin/submission-lifecycle.php so they can be
// unit-tested in isolation.
require_once __DIR__ . '/includes/admin/submission-lifecycle.php';

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
    global $menu, $submenu;

    $count = wp_count_posts('local_group')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    $bubble = sprintf(
        ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>',
        $count
    );

    // Bubble the parent "Community" item (shown when sidebar is collapsed)
    foreach ($menu as &$item) {
        if ($item[2] === 'cdcf-community') {
            $item[0] .= $bubble;
            break;
        }
    }
    unset($item);

    // Bubble the Local Groups submenu entry
    if (!empty($submenu['cdcf-community'])) {
        foreach ($submenu['cdcf-community'] as &$sub) {
            if ($sub[2] === 'edit.php?post_type=local_group') {
                $sub[0] .= $bubble;
                break;
            }
        }
    }
}, 50);

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

// ─── Pending Community Projects: Menu Bubble + Dashboard Widget ─────

/**
 * Add a pending-count bubble to the Community Projects menu item.
 */
add_action('admin_menu', function () {
    global $menu, $submenu;

    $count = wp_count_posts('community_project')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    $bubble = sprintf(
        ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>',
        $count
    );

    // Bubble the parent "Community" item (shown when sidebar is collapsed)
    foreach ($menu as &$item) {
        if ($item[2] === 'cdcf-community') {
            $item[0] .= $bubble;
            break;
        }
    }
    unset($item);

    // Bubble the Community Projects submenu entry
    if (!empty($submenu['cdcf-community'])) {
        foreach ($submenu['cdcf-community'] as &$sub) {
            if ($sub[2] === 'edit.php?post_type=community_project') {
                $sub[0] .= $bubble;
                break;
            }
        }
    }
}, 50);

/**
 * Dashboard widget showing pending community project referrals.
 */
add_action('wp_dashboard_setup', function () {
    $count = wp_count_posts('community_project')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    wp_add_dashboard_widget(
        'cdcf_pending_community_projects',
        sprintf('Pending Community Project Referrals (%d)', $count),
        'cdcf_render_pending_community_projects_widget'
    );
});

function cdcf_render_pending_community_projects_widget(): void {
    $posts = get_posts([
        'post_type'   => 'community_project',
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
       . '<th>Project</th><th>Submitted by</th><th>Date</th><th></th>'
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

    $total = wp_count_posts('community_project')->pending ?? 0;
    if ($total > 10) {
        $url = admin_url('edit.php?post_type=community_project&post_status=pending');
        printf('<p><a href="%s">View all %d pending referrals &rarr;</a></p>', $url, $total);
    }
}

// ─── Restore Public Submissions to Pending on Untrash ────────────────

// cdcf_repend_submission_on_untrash() lives in includes/admin/submission-lifecycle.php
add_action('transition_post_status', 'cdcf_repend_submission_on_untrash', 10, 3);

// ─── Auto-Translate Public Submissions on Approval ───────────────────
//
// When an admin publishes a public-submission post (project,
// community_project, or local_group whose source has submitter meta),
// the hook below creates draft sibling posts in it/es/fr/pt/de, links
// them via Polylang, and enqueues background AI translations. The
// existing worker (cdcf_process_translation) auto-publishes each
// translation when its source is `publish`.
//
// Helpers + callback live in includes/admin/submission-lifecycle.php
// (required above). Priority 20 here so it runs after all priority-10
// hooks (sitemap revalidation and the untrash/re-pend hook).
add_action('transition_post_status', 'cdcf_enqueue_translations_on_publish', 20, 3);

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
    global $menu, $submenu;

    $count = wp_count_posts('project')->pending ?? 0;
    if ($count < 1) {
        return;
    }

    $bubble = sprintf(
        ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>',
        $count
    );

    foreach ($menu as &$item) {
        if ($item[2] === 'cdcf-projects') {
            $item[0] .= $bubble;
            break;
        }
    }
    unset($item);

    if (!empty($submenu['cdcf-projects'])) {
        foreach ($submenu['cdcf-projects'] as &$sub) {
            if ($sub[2] === 'edit.php?post_type=project') {
                $sub[0] .= $bubble;
                break;
            }
        }
    }
}, 50);

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
