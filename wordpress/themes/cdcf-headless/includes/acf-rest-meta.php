<?php
/**
 * ACF fields as REST-writable post meta.
 *
 * ACF Free exposes fields for reading via show_in_rest but doesn't register
 * them with register_post_meta(), so WordPress rejects writes through the
 * REST API. This bridges that gap.
 *
 * A raw meta write stores the value but not ACF's `_<name>` reference to
 * the field key, which ACF's own editor writes on save. Without that link
 * get_field_objects() does not see the field, so the AI translation skips
 * it. After each REST insert or update the bridge writes the link for every
 * field the request set.
 */

defined('ABSPATH') || exit;

/**
 * The bridged fields: REST-visible scalar fields of every local group,
 * as [post_type => [field_name => field_key]].
 */
function cdcf_acf_rest_meta_fields(): array {
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return [];
    }

    // Complex types (relationship, image…) store serialized arrays that
    // can't be represented as string meta and break page saves.
    $skip_types = ['relationship', 'image', 'file', 'gallery', 'repeater', 'flexible_content', 'group'];

    $fields = [];
    foreach (acf_get_field_groups() as $group) {
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

        foreach (acf_get_fields($group['key']) as $field) {
            if (empty($field['show_in_rest']) || in_array($field['type'], $skip_types, true)) {
                continue;
            }
            foreach ($post_types as $pt) {
                $fields[$pt][$field['name']] = $field['key'];
            }
        }
    }
    return $fields;
}

/**
 * Write ACF's `_<name>` → field-key link for each bridged field the REST
 * request set, as ACF's editor does on save.
 *
 * @param array $links field_name => field_key for the post's type.
 */
function cdcf_acf_rest_write_field_links(WP_Post $post, WP_REST_Request $request, array $links): void {
    $meta = $request->get_param('meta');
    if (!is_array($meta)) {
        return;
    }
    foreach (array_keys($meta) as $name) {
        if (isset($links[$name])) {
            update_post_meta($post->ID, '_' . $name, $links[$name]);
        }
    }
}

/** Register the bridged fields as post meta and hook the link writer. */
function cdcf_register_acf_rest_meta(): void {
    foreach (cdcf_acf_rest_meta_fields() as $pt => $links) {
        foreach (array_keys($links) as $name) {
            register_post_meta($pt, $name, [
                'type'          => 'string',
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
        }
        add_action(
            "rest_after_insert_{$pt}",
            function (WP_Post $post, WP_REST_Request $request) use ($links) {
                cdcf_acf_rest_write_field_links($post, $request, $links);
            },
            10,
            2
        );
    }
}
