<?php
/**
 * Projects → Review access: every council and Board seat from the
 * English About page with the WP user linked to it. A member without a
 * linked user cannot sign in to /review and receives no notifications;
 * link them via /cdcf/v1/author-team-member or the user-edit screen.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_COUNCIL_LABELS = [
    'technical' => 'Technical Council',
    'ecclesial' => 'Ecclesial Council',
    'board'     => 'Board of Directors',
];

function cdcf_review_access_admin_menu(): void {
    add_submenu_page(
        'cdcf-projects',
        'Review access',
        'Review access',
        'manage_options',
        'cdcf-review-access',
        'cdcf_review_access_render'
    );
}

function cdcf_review_access_render(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $rows = cdcf_review_roster();
    echo '<div class="wrap"><h1>' . esc_html('Review access') . '</h1>';
    if (cdcf_review_storage_dir()['fallback']) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html('Review attachments are stored under wp-content/uploads/cdcf-review/. Define CDCF_REVIEW_STORAGE_DIR in wp-config.php to keep them outside the web root, or make sure the web server denies that path.')
            . '</p></div>';
    }
    echo '<p>' . esc_html('Council and Board members review submitted projects at /review. Each one needs a WordPress user linked to their bio card.') . '</p>';
    if (!$rows) {
        echo '<p>' . esc_html('No council members are listed on the English About page.') . '</p></div>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html('Council') . '</th><th>'
        . esc_html('Member') . '</th><th>' . esc_html('Linked user') . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $user = $row['user_id'] > 0 ? get_userdata($row['user_id']) : false;
        $linked = $user
            ? '<a href="' . esc_url(get_edit_user_link($row['user_id'])) . '">' . esc_html($user->display_name)
                . '</a> &lt;' . esc_html($user->user_email) . '&gt;'
            : '<strong>' . esc_html('Not linked') . '</strong>';
        echo '<tr><td>' . esc_html(CDCF_REVIEW_COUNCIL_LABELS[$row['council']] ?? $row['council']) . '</td><td>'
            . esc_html($row['name']) . '</td><td>' . $linked . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
