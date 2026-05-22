<?php
/**
 * Seed each admin user's Polylang language filter to the site's
 * default language on first wp-admin visit.
 *
 * Polylang renders each translation as its own row in admin list
 * tables, so a page with 6 translations shows as 6 rows. Seeding the
 * per-user pll_filter_content meta to the default language collapses
 * translation groups to a single row. Users keep full control via
 * Polylang's switcher in the toolbar — including "All languages" —
 * since we only seed the default once and never override later choices.
 *
 * Extracted from functions.php so the callback can be unit-tested
 * directly (Brain Monkey + Mockery).
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_seed_polylang_default_language(): void
{
    if (!function_exists('pll_default_language')) {
        return;
    }
    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }
    if (get_user_meta($user_id, '_cdcf_pll_default_filter_seeded', true)) {
        return;
    }
    update_user_meta($user_id, 'pll_filter_content', pll_default_language());
    update_user_meta($user_id, '_cdcf_pll_default_filter_seeded', '1');
}
