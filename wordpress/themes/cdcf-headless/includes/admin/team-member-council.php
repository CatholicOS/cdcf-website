<?php
/**
 * Admin-side hooks for the Team Members → council submenus.
 *
 * team_member posts have no council meta of their own — categorization
 * lives inverse on the About page's three ACF relationship fields:
 *   team_members      → Board of Directors
 *   ecclesial_council → Ecclesial Advisory Council
 *   technical_council → Technical Advisory Council
 *
 * Each submenu links to edit.php?post_type=team_member&cdcf_council=…
 * and the pre_get_posts filter below resolves that to the current
 * admin language's About page, restricting post__in to its members.
 *
 * Extracted from functions.php so the callbacks can be unit-tested
 * directly (Brain Monkey + Mockery).
 */

if (defined('ABSPATH') === false) {
    return;
}

const CDCF_COUNCIL_MAP = [
    'board'     => 'team_members',
    'ecclesial' => 'ecclesial_council',
    'technical' => 'technical_council',
];

/**
 * admin_menu callback: add three filtered submenus under Team Members.
 */
function cdcf_register_team_member_council_submenus(): void
{
    $parent = 'edit.php?post_type=team_member';
    $councils = [
        'board'     => __('Board of Directors', 'cdcf-headless'),
        'ecclesial' => __('Ecclesial Advisory Council', 'cdcf-headless'),
        'technical' => __('Technical Advisory Council', 'cdcf-headless'),
    ];
    foreach ($councils as $slug => $label) {
        add_submenu_page(
            $parent,
            $label,
            $label,
            'edit_posts',
            $parent . '&cdcf_council=' . $slug
        );
    }
}

/**
 * pre_get_posts callback: when ?cdcf_council=<slug> is present on a
 * team_member admin list query, restrict post__in to the matching
 * About-page relationship field's IDs.
 */
function cdcf_filter_team_member_council_query($query): void
{
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ($query->get('post_type') !== 'team_member') {
        return;
    }
    $council = isset($_GET['cdcf_council']) ? sanitize_key($_GET['cdcf_council']) : '';
    if (!isset(CDCF_COUNCIL_MAP[$council])) {
        return;
    }
    if (!function_exists('get_field')) {
        return;
    }

    $field_name = CDCF_COUNCIL_MAP[$council];
    $about_id   = cdcf_get_about_page_id_for_admin_lang();
    if (!$about_id) {
        $query->set('post__in', [0]);
        return;
    }

    $ids = get_field($field_name, $about_id, false);
    if (!is_array($ids) || empty($ids)) {
        $query->set('post__in', [0]);
        return;
    }

    $query->set('post__in', array_map('intval', $ids));
    $query->set('orderby', 'post__in');
}

/**
 * submenu_file filter: highlight the correct council submenu when
 * filtering by cdcf_council. WP would otherwise mark the default
 * "All Team Members" entry as active because it strips unknown query
 * args when matching $submenu_file.
 *
 * @param string|null $submenu_file Current submenu file slug from core.
 * @return string|null
 */
function cdcf_highlight_team_member_council_submenu($submenu_file)
{
    if (($_GET['post_type'] ?? '') !== 'team_member') {
        return $submenu_file;
    }
    $council = isset($_GET['cdcf_council']) ? sanitize_key($_GET['cdcf_council']) : '';
    if (!isset(CDCF_COUNCIL_MAP[$council])) {
        return $submenu_file;
    }
    return 'edit.php?post_type=team_member&cdcf_council=' . $council;
}

/**
 * Resolve the About page ID for the current admin language, falling
 * back to the English translation when there's no exact match.
 */
function cdcf_get_about_page_id_for_admin_lang(): int
{
    $about_pages = get_pages([
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'templates/about.php',
    ]);
    if (empty($about_pages)) {
        return 0;
    }

    $current = function_exists('pll_current_language') ? pll_current_language('slug') : 'en';
    foreach ([$current, 'en'] as $lang) {
        foreach ($about_pages as $page) {
            $page_lang = function_exists('pll_get_post_language') ? pll_get_post_language($page->ID, 'slug') : 'en';
            if ($page_lang === $lang) {
                return (int) $page->ID;
            }
        }
    }
    return (int) $about_pages[0]->ID;
}
