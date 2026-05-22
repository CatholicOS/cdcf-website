<?php
/**
 * REST route handler for /cdcf/v1/project-status.
 *
 * Sets the project_status ACF field on a project and all of its
 * Polylang translations in a single call, so a workflow state change
 * (incubating → active → archived) doesn't have to be replicated by
 * hand across every language.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_update_project_status(WP_REST_Request $request) {
    if (!function_exists('update_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $post_id = $request['post_id'];
    $status  = $request['status'];

    $allowed = ['incubating', 'active', 'archived'];
    if (!in_array($status, $allowed, true)) {
        return new WP_Error('invalid_status', 'status must be one of: ' . implode(', ', $allowed), ['status' => 400]);
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'project') {
        return new WP_Error('invalid_post', 'Post not found or is not a project.', ['status' => 404]);
    }

    // Collect all translation IDs (including the given post itself).
    $post_ids = [$post_id];
    if (function_exists('pll_get_post_translations')) {
        $translations = pll_get_post_translations($post_id);
        $post_ids = array_values($translations);
        if (!in_array($post_id, $post_ids, true)) {
            $post_ids[] = $post_id;
        }
    }

    $updated = [];
    foreach ($post_ids as $pid) {
        update_field('project_status', $status, $pid);
        $updated[] = $pid;
    }

    return rest_ensure_response([
        'success'      => true,
        'status'       => $status,
        'updated_posts' => $updated,
    ]);
}
