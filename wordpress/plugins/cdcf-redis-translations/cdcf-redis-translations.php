<?php
/**
 * Plugin Name: CDCF Redis Translations
 * Description: Redis Queue integration for async AI translations. Requires the redis-queue plugin.
 * Version:     1.0.0
 * Author:      Catholic Digital Commons Foundation
 * Requires PHP: 8.3
 */

defined('ABSPATH') || exit;

add_action('plugins_loaded', function () {
    if (!function_exists('redis_queue')) {
        return;
    }

    require_once __DIR__ . '/includes/class-translation-job.php';
    require_once __DIR__ . '/includes/functions.php';
}, 20);

// REST endpoint to trigger queue processing (for cron via curl).
add_action('rest_api_init', function () {
    register_rest_route('cdcf/v1', '/process-queue', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback' => function () {
            if (!function_exists('redis_queue')) {
                return new WP_REST_Response(['processed' => 0, 'error' => 'redis_queue not available'], 200);
            }
            redis_queue()->process_jobs();
            return new WP_REST_Response(['processed' => true], 200);
        },
    ]);
});
