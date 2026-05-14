<?php
/**
 * REST route handlers for /cdcf/v1/relationship.
 *
 * Extracted from inline closures and the bottom of functions.php so
 * they can be unit-tested with Brain Monkey + Mockery. The theme's
 * functions.php require_once's this file and references the named
 * functions in its register_rest_route() calls.
 */

if (defined('ABSPATH') === false) {
    return;
}

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
    $post_id = absint($request['post_id']);
    $field   = $request['field'];

    if (function_exists('get_field') === false) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    if ($post_id === 0 || get_post($post_id) === null) {
        return new WP_Error('post_not_found', 'Post not found.', ['status' => 404]);
    }

    $acf_field = acf_get_field($field);
    if ($acf_field === false || $acf_field['type'] !== 'relationship') {
        return new WP_Error('invalid_field', 'Field is not a relationship field.', ['status' => 400]);
    }

    $value = get_field($field, $post_id, false); // raw IDs
    return rest_ensure_response([
        'post_id' => $post_id,
        'field'   => $field,
        'value'   => is_array($value) === true ? $value : [],
    ]);
}

/**
 * POST /cdcf/v1/relationship — update an ACF relationship field.
 */
function cdcf_rest_update_relationship(WP_REST_Request $request) {
    $post_id = absint($request['post_id']);
    $field   = $request['field'];
    $value   = $request['value'];

    if (function_exists('update_field') === false) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    if ($post_id === 0 || get_post($post_id) === null) {
        return new WP_Error('post_not_found', 'Post not found.', ['status' => 404]);
    }

    $acf_field = acf_get_field($field);
    if ($acf_field === false || $acf_field['type'] !== 'relationship') {
        return new WP_Error('invalid_field', 'Field is not a relationship field.', ['status' => 400]);
    }

    // Sanitize to array of positive integers. absint() coerces first so
    // non-numeric inputs (e.g. "abc") become 0 and then get filtered,
    // rather than sneaking through as 0 in the stored field.
    $value = array_values(
        array_filter(
            array_map('absint', (array) $value),
            static fn(int $v): bool => $v > 0
        )
    );
    update_field($field, $value, $post_id);

    return rest_ensure_response(['post_id' => $post_id, 'field' => $field, 'value' => $value, 'updated' => true]);
}
