<?php
/**
 * REST route handlers for /cdcf/v1/relationship.
 *
 * Extracted from inline closures and the bottom of functions.php so
 * they can be unit-tested with Brain Monkey + Mockery. The theme's
 * functions.php require_once's this file and references the named
 * functions in its register_rest_route() calls.
 */

defined('ABSPATH') || exit;

/**
 * Permission callback for both GET and POST /cdcf/v1/relationship.
 */
function cdcf_relationship_permission_check(): bool {
    return current_user_can('edit_posts');
}

/**
 * GET /cdcf/v1/relationship — read an ACF relationship field.
 */
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

/**
 * POST /cdcf/v1/relationship — update an ACF relationship field.
 */
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
