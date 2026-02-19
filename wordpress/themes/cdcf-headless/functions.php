<?php
/**
 * CDCF Headless Theme — functions.php
 *
 * Registers CPTs, ACF field groups, Polylang config,
 * CORS for GraphQL, and preview URL hooks.
 */

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
        'supports'     => ['title', 'editor', 'thumbnail', 'excerpt'],
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
        'supports'     => ['title', 'editor', 'thumbnail'],
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
        'supports'     => ['title', 'thumbnail'],
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
        'supports'     => ['title'],
        'menu_icon'    => 'dashicons-networking',
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
        'supports'     => ['title'],
        'menu_icon'    => 'dashicons-chart-bar',
        'has_archive'  => false,
    ]);
});

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
            ],
            [
                'key'     => 'field_hero_show_logo',
                'label'   => 'Show Logo',
                'name'    => 'hero_show_logo',
                'type'    => 'true_false',
                'default_value' => 1,
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_hero_tagline',
                'label' => 'Tagline',
                'name'  => 'hero_tagline',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_hero_subtitle',
                'label' => 'Subtitle',
                'name'  => 'hero_subtitle',
                'type'  => 'wysiwyg',
                'media_upload' => 0,
                'tabs'  => 'all',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_hero_primary_btn_label',
                'label' => 'Primary Button Label',
                'name'  => 'hero_primary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_hero_primary_btn_url',
                'label' => 'Primary Button URL',
                'name'  => 'hero_primary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_hero_secondary_btn_label',
                'label' => 'Secondary Button Label',
                'name'  => 'hero_secondary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_hero_secondary_btn_url',
                'label' => 'Secondary Button URL',
                'name'  => 'hero_secondary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_cta_heading',
                'label' => 'CTA Heading',
                'name'  => 'cta_heading',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_cta_description',
                'label' => 'CTA Description',
                'name'  => 'cta_description',
                'type'  => 'wysiwyg',
                'media_upload' => 0,
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_cta_primary_btn_label',
                'label' => 'Primary Button Label',
                'name'  => 'cta_primary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_cta_primary_btn_url',
                'label' => 'Primary Button URL',
                'name'  => 'cta_primary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_cta_secondary_btn_label',
                'label' => 'Secondary Button Label',
                'name'  => 'cta_secondary_btn_label',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_cta_secondary_btn_url',
                'label' => 'Secondary Button URL',
                'name'  => 'cta_secondary_btn_url',
                'type'  => 'url',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_project_repo_url',
                'label' => 'Repository URL',
                'name'  => 'project_repo_url',
                'type'  => 'url',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_project_url',
                'label' => 'Project Website URL',
                'name'  => 'project_url',
                'type'  => 'url',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_project_license',
                'label' => 'License',
                'name'  => 'project_license',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_project_category',
                'label' => 'Category',
                'name'  => 'project_category',
                'type'  => 'text',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_member_title',
                'label' => 'Title',
                'name'  => 'member_title',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_member_linkedin_url',
                'label' => 'LinkedIn URL',
                'name'  => 'member_linkedin_url',
                'type'  => 'url',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_member_github_url',
                'label' => 'GitHub URL',
                'name'  => 'member_github_url',
                'type'  => 'url',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_sponsor_url',
                'label' => 'Website URL',
                'name'  => 'sponsor_url',
                'type'  => 'url',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_channel_url',
                'label' => 'Channel URL',
                'name'  => 'channel_url',
                'type'  => 'url',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_channel_description',
                'label' => 'Description',
                'name'  => 'channel_description',
                'type'  => 'textarea',
                'show_in_graphql' => true,
            ],
        ],
        'location' => [
            [['param' => 'post_type', 'operator' => '==', 'value' => 'community_channel']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'channelFields',
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
            ],
            [
                'key'   => 'field_stat_number',
                'label' => 'Number',
                'name'  => 'stat_number',
                'type'  => 'text',
                'show_in_graphql' => true,
            ],
            [
                'key'   => 'field_stat_label',
                'label' => 'Label',
                'name'  => 'stat_label',
                'type'  => 'text',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_home_stats',
                'label' => 'Stats',
                'name'  => 'stats',
                'type'  => 'relationship',
                'post_type' => ['stat_item'],
                'return_format' => 'object',
                'show_in_graphql' => true,
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
                'label' => 'Team Members',
                'name'  => 'team_members',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
                'show_in_graphql' => true,
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
            ],
            [
                'key'   => 'field_community_members',
                'label' => 'Team Members',
                'name'  => 'members',
                'type'  => 'relationship',
                'post_type' => ['team_member'],
                'return_format' => 'object',
                'show_in_graphql' => true,
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
