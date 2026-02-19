<?php
/**
 * CDCF WordPress Web Initialization Script
 *
 * Equivalent of init.sh for environments without WP-CLI.
 * Run via browser: https://cms.example.com/web-init.php?secret=YOUR_SECRET
 * The script self-deletes after successful execution.
 */

// ── Security: require secret token ──
$secret = $_GET['secret'] ?? '';
if ( empty( $secret ) || $secret !== 'CHANGE_ME' ) {
    http_response_code( 403 );
    die( 'Forbidden: invalid or missing secret.' );
}

header( 'Content-Type: text/plain; charset=utf-8' );

// ── Bootstrap WordPress ──
define( 'ABSPATH', __DIR__ . '/' );
require_once ABSPATH . 'wp-load.php';

echo "=== CDCF WordPress Web Initialization ===\n\n";

// ── Idempotency: check if pages already exist ──
$existing = get_page_by_path( 'home' );
if ( $existing ) {
    echo "Home page already exists (ID {$existing->ID}). Setup has already run.\n";
    echo "Delete existing pages first if you want to re-run.\n";
    exit;
}

// ── Activate theme ──
echo "Activating theme...\n";
switch_theme( 'cdcf-headless' );

// ── Flush rewrite rules ──
echo "Flushing rewrite rules...\n";
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->flush_rules( true );

// ── Configure Polylang Languages ──
echo "\nConfiguring languages...\n";
if ( function_exists( 'PLL' ) ) {
    $langs = [
        [ 'name' => 'English',   'slug' => 'en', 'locale' => 'en_US', 'flag' => 'us' ],
        [ 'name' => 'Italiano',  'slug' => 'it', 'locale' => 'it_IT', 'flag' => 'it' ],
        [ 'name' => 'Español',   'slug' => 'es', 'locale' => 'es_ES', 'flag' => 'es' ],
        [ 'name' => 'Français',  'slug' => 'fr', 'locale' => 'fr_FR', 'flag' => 'fr' ],
        [ 'name' => 'Português', 'slug' => 'pt', 'locale' => 'pt_BR', 'flag' => 'br' ],
        [ 'name' => 'Deutsch',   'slug' => 'de', 'locale' => 'de_DE', 'flag' => 'de' ],
    ];
    $order = 0;
    foreach ( $langs as $lang ) {
        $result = PLL()->model->add_language( array_merge( $lang, [
            'rtl'        => 0,
            'term_group' => $order++,
        ] ) );
        if ( is_wp_error( $result ) ) {
            echo "  {$lang['name']}: {$result->get_error_message()}\n";
        } else {
            echo "  {$lang['name']}: created\n";
        }
    }
} else {
    echo "  Polylang not active, skipping languages.\n";
}

// ── Helper to assign language to a post ──
function cdcf_set_post_language( $post_id, $slug ) {
    if ( function_exists( 'pll_set_post_language' ) ) {
        pll_set_post_language( $post_id, $slug );
    }
}

// ── Create Pages ──
echo "\nCreating pages...\n";

$pages = [
    'home'      => [ 'title' => 'Home',      'template' => 'templates/home.php' ],
    'about'     => [ 'title' => 'About',     'template' => 'templates/about.php' ],
    'projects'  => [ 'title' => 'Projects',  'template' => 'templates/projects.php' ],
    'community' => [ 'title' => 'Community', 'template' => 'templates/community.php' ],
    'blog'      => [ 'title' => 'Blog',      'template' => 'templates/blog.php' ],
    'contact'   => [ 'title' => 'Contact',   'template' => 'templates/contact.php' ],
];

$page_ids = [];
foreach ( $pages as $slug => $info ) {
    $id = wp_insert_post( [
        'post_type'   => 'page',
        'post_title'  => $info['title'],
        'post_name'   => $slug,
        'post_status' => 'publish',
    ] );
    update_post_meta( $id, '_wp_page_template', $info['template'] );
    cdcf_set_post_language( $id, 'en' );
    $page_ids[ $slug ] = $id;
    echo "  {$info['title']} (ID: $id)\n";
}

// Static front page
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $page_ids['home'] );
echo "  Set Home as static front page.\n";

// ── Seed ACF Content ──
echo "\nSeeding ACF fields...\n";
if ( function_exists( 'update_field' ) ) {

    // Home
    $id = $page_ids['home'];
    update_field( 'hero_bg_style',            'gradient',  $id );
    update_field( 'hero_show_logo',           true,        $id );
    update_field( 'hero_alignment',           'center',    $id );
    update_field( 'hero_tagline',             'Catholic Digital Commons Foundation', $id );
    update_field( 'hero_subtitle',            '<p>Building open-source tools and fostering collaboration for the Catholic digital community.</p>', $id );
    update_field( 'hero_primary_btn_label',   'Explore Projects', $id );
    update_field( 'hero_primary_btn_url',     '/projects',        $id );
    update_field( 'hero_secondary_btn_label', 'Join the Community', $id );
    update_field( 'hero_secondary_btn_url',   '/community',        $id );
    update_field( 'cta_style',               'banner',   $id );
    update_field( 'cta_heading',             'Join Us in Building the Catholic Digital Commons', $id );
    update_field( 'cta_description',         '<p>Whether you are a developer, designer, translator, or simply passionate about the Church\'s digital mission, there is a place for you.</p>', $id );
    update_field( 'cta_primary_btn_label',   'Get Involved', $id );
    update_field( 'cta_primary_btn_url',     '/community',   $id );
    update_field( 'stats_bg_color',          'navy',     $id );
    echo "  Home fields set.\n";

    // About
    $id = $page_ids['about'];
    update_field( 'hero_bg_style',           'gradient',    $id );
    update_field( 'hero_show_logo',          false,         $id );
    update_field( 'hero_alignment',          'center',      $id );
    update_field( 'hero_tagline',            'About CDCF',  $id );
    update_field( 'hero_subtitle',           '<p>Learn about our mission, governance, and the people behind the Catholic Digital Commons Foundation.</p>', $id );
    update_field( 'cta_style',              'card',         $id );
    update_field( 'cta_heading',            'Want to Contribute?', $id );
    update_field( 'cta_description',        '<p>We welcome contributions from developers, translators, designers, and anyone passionate about Catholic digital tools.</p>', $id );
    update_field( 'cta_primary_btn_label',  'See Open Issues', $id );
    update_field( 'cta_primary_btn_url',    'https://github.com/CatholicOS-org', $id );
    echo "  About fields set.\n";

    // Projects
    $id = $page_ids['projects'];
    update_field( 'hero_bg_style',           'gradient',      $id );
    update_field( 'hero_show_logo',          false,           $id );
    update_field( 'hero_alignment',          'center',        $id );
    update_field( 'hero_tagline',            'Our Projects',  $id );
    update_field( 'hero_subtitle',           '<p>Explore open-source projects built by and for the Catholic community.</p>', $id );
    update_field( 'show_filters',            true,   $id );
    update_field( 'grid_columns',            '3',    $id );
    update_field( 'cta_style',              'banner', $id );
    update_field( 'cta_heading',            'Have a Project Idea?', $id );
    update_field( 'cta_description',        '<p>We are always looking for new projects that serve the Catholic community. Submit your proposal or contribute to an existing project.</p>', $id );
    update_field( 'cta_primary_btn_label',  'Propose a Project', $id );
    update_field( 'cta_primary_btn_url',    '/contact', $id );
    echo "  Projects fields set.\n";

    // Community
    $id = $page_ids['community'];
    update_field( 'hero_bg_style',           'gradient',    $id );
    update_field( 'hero_show_logo',          false,         $id );
    update_field( 'hero_alignment',          'center',      $id );
    update_field( 'hero_tagline',            'Community',   $id );
    update_field( 'hero_subtitle',           '<p>Connect with Catholic developers, designers, and digital missionaries from around the world.</p>', $id );
    update_field( 'cta_style',              'banner',       $id );
    update_field( 'cta_heading',            'Stay Connected', $id );
    update_field( 'cta_description',        '<p>Follow us on social media and join our mailing list for updates on projects, events, and opportunities.</p>', $id );
    update_field( 'cta_primary_btn_label',  'Subscribe',    $id );
    update_field( 'cta_primary_btn_url',    '/contact',     $id );
    echo "  Community fields set.\n";

    // Blog
    $id = $page_ids['blog'];
    update_field( 'hero_bg_style',  'gradient', $id );
    update_field( 'hero_show_logo', false,      $id );
    update_field( 'hero_alignment', 'center',   $id );
    update_field( 'hero_tagline',   'Blog',     $id );
    update_field( 'hero_subtitle',  '<p>News, updates, and reflections from the Catholic Digital Commons Foundation.</p>', $id );
    update_field( 'max_posts',      6,          $id );
    echo "  Blog fields set.\n";

    // Contact
    $id = $page_ids['contact'];
    update_field( 'hero_bg_style',           'gradient',     $id );
    update_field( 'hero_show_logo',          false,          $id );
    update_field( 'hero_alignment',          'center',       $id );
    update_field( 'hero_tagline',            'Contact Us',   $id );
    update_field( 'hero_subtitle',           '<p>Get in touch with the Catholic Digital Commons Foundation.</p>', $id );
    update_field( 'contact_body',            '<h2>Get in Touch</h2><p>We would love to hear from you. Whether you have questions, suggestions, or want to contribute, reach out to us.</p><p>Email: <a href="mailto:info@catholicdigitalcommons.org">info@catholicdigitalcommons.org</a></p>', $id );
    update_field( 'cta_style',              'inline',        $id );
    update_field( 'cta_heading',            'Prefer to Chat?', $id );
    update_field( 'cta_description',        '<p>Join our community channels for real-time conversation.</p>', $id );
    update_field( 'cta_primary_btn_label',  'Join the Community', $id );
    update_field( 'cta_primary_btn_url',    '/community',    $id );
    echo "  Contact fields set.\n";

} else {
    echo "  ACF not active, skipping field seeding.\n";
}

// ── Create Sample CPT Entries ──
echo "\nCreating sample content...\n";

// Stat items
$stat_ids = [];
$stats = [
    [ 'title' => 'Open Source Projects', 'number' => '12+', 'label' => 'Open Source Projects' ],
    [ 'title' => 'Contributors',         'number' => '50+', 'label' => 'Contributors' ],
    [ 'title' => 'Languages',            'number' => '6',   'label' => 'Languages' ],
];
foreach ( $stats as $stat ) {
    $sid = wp_insert_post( [
        'post_type'   => 'stat_item',
        'post_title'  => $stat['title'],
        'post_status' => 'publish',
    ] );
    cdcf_set_post_language( $sid, 'en' );
    if ( function_exists( 'update_field' ) ) {
        update_field( 'stat_number', $stat['number'], $sid );
        update_field( 'stat_label',  $stat['label'],  $sid );
    }
    $stat_ids[] = $sid;
    echo "  Stat: {$stat['title']} (ID: $sid)\n";
}

// Link stats to Home
if ( function_exists( 'update_field' ) ) {
    update_field( 'stats', $stat_ids, $page_ids['home'] );
}

// Projects
$p1 = wp_insert_post( [
    'post_type'    => 'project',
    'post_title'   => 'Liturgical Calendar API',
    'post_content' => '<p>A comprehensive API for the liturgical calendar of the Roman Rite, providing data for feast days, liturgical seasons, and more.</p>',
    'post_status'  => 'publish',
] );
cdcf_set_post_language( $p1, 'en' );

$p2 = wp_insert_post( [
    'post_type'    => 'project',
    'post_title'   => 'Catholic Lectionary',
    'post_content' => '<p>An open-source lectionary application providing daily and Sunday readings in multiple languages.</p>',
    'post_status'  => 'publish',
] );
cdcf_set_post_language( $p2, 'en' );

if ( function_exists( 'update_field' ) ) {
    update_field( 'project_status',   'active',     $p1 );
    update_field( 'project_license',  'Apache-2.0', $p1 );
    update_field( 'project_category', 'API',        $p1 );
    update_field( 'project_repo_url', 'https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI', $p1 );
    update_field( 'project_status',   'incubating',  $p2 );
    update_field( 'project_license',  'MIT',         $p2 );
    update_field( 'project_category', 'Application', $p2 );
    update_field( 'featured_projects', [ $p1, $p2 ], $page_ids['home'] );
}
echo "  Projects created (IDs: $p1, $p2)\n";

// Team member
$m1 = wp_insert_post( [
    'post_type'    => 'team_member',
    'post_title'   => "John R. D'Orazio",
    'post_content' => '<p>Founder and lead developer of the Catholic Digital Commons Foundation.</p>',
    'post_status'  => 'publish',
] );
cdcf_set_post_language( $m1, 'en' );

if ( function_exists( 'update_field' ) ) {
    update_field( 'member_role',       'Founder',        $m1 );
    update_field( 'member_title',      'Lead Developer', $m1 );
    update_field( 'member_github_url', 'https://github.com/JohnRDOrazio', $m1 );
    update_field( 'team_members',       [ $m1 ], $page_ids['about'] );
    update_field( 'governance_columns', '3',     $page_ids['about'] );
}
echo "  Team member created (ID: $m1)\n";

// Community channels
$ch1 = wp_insert_post( [
    'post_type'   => 'community_channel',
    'post_title'  => 'GitHub Discussions',
    'post_status' => 'publish',
] );
cdcf_set_post_language( $ch1, 'en' );

$ch2 = wp_insert_post( [
    'post_type'   => 'community_channel',
    'post_title'  => 'Catholic Coders Guild by Clairvo',
    'post_status' => 'publish',
] );
cdcf_set_post_language( $ch2, 'en' );

$ch3 = wp_insert_post( [
    'post_type'   => 'community_channel',
    'post_title'  => 'Catholic Devs',
    'post_status' => 'publish',
] );
cdcf_set_post_language( $ch3, 'en' );

if ( function_exists( 'update_field' ) ) {
    update_field( 'channel_icon',        '💬', $ch1 );
    update_field( 'channel_url',         'https://github.com/orgs/CatholicOS/discussions', $ch1 );
    update_field( 'channel_description', 'Join discussions about CDCF projects and initiatives on GitHub.', $ch1 );

    update_field( 'channel_icon',        '🎮', $ch2 );
    update_field( 'channel_url',         'https://discord.gg/q4vg3tCe', $ch2 );
    update_field( 'channel_description', 'A Discord server for Catholic coders to collaborate and share ideas.', $ch2 );

    update_field( 'channel_icon',        '💼', $ch3 );
    update_field( 'channel_url',         'https://join.slack.com/t/catholicdevs/shared_invite/zt-1tovdt4om-YNoPduN0rQub5zBsbucj2w', $ch3 );
    update_field( 'channel_description', 'A Slack workspace for Catholic developers to connect and collaborate.', $ch3 );

    update_field( 'channels', [ $ch1, $ch2, $ch3 ], $page_ids['community'] );
    update_field( 'members',  [ $m1 ],  $page_ids['community'] );
}
echo "  Community channels created (IDs: $ch1, $ch2, $ch3)\n";

// Blog post
$bp1 = wp_insert_post( [
    'post_type'    => 'post',
    'post_title'   => 'Welcome to the Catholic Digital Commons Foundation',
    'post_content' => '<p>We are excited to launch the Catholic Digital Commons Foundation, a community-driven initiative to build open-source tools for the Catholic Church.</p><p>Our mission is to foster collaboration among Catholic developers, designers, translators, and digital missionaries to create software that serves the universal Church.</p>',
    'post_status'  => 'publish',
] );
cdcf_set_post_language( $bp1, 'en' );
echo "  Blog post created (ID: $bp1)\n";

// ── Clean Up Defaults ──
echo "\nCleaning up defaults...\n";
wp_delete_post( 1, true ); // "Hello World" post
wp_delete_post( 2, true ); // Sample page
echo "  Deleted default post and page.\n";

// ── Enable public GraphQL introspection ──
update_option( 'graphql_general_settings', [ 'public_introspection_enabled' => 'on' ] );
echo "  Enabled GraphQL public introspection.\n";

// ── Self-delete ──
echo "\n=========================================\n";
echo "  WordPress initialization complete!\n";
echo "=========================================\n\n";
echo "Self-deleting this script...\n";
unlink( __FILE__ );
echo "Done. This script has been removed.\n";
