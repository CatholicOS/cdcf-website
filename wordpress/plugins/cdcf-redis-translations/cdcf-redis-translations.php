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
        'callback' => function (WP_REST_Request $request) {
            if (!function_exists('redis_queue')) {
                return new WP_REST_Response(['processed' => 0, 'error' => 'redis_queue not available'], 200);
            }
            ignore_user_abort(true);
            $batch_size = intval($request['batch_size'] ?? 10);
            $batch_size = max(1, min($batch_size, 50));
            $processor = redis_queue()->get_job_processor();
            $result = $processor->process_jobs(['default'], $batch_size);
            return new WP_REST_Response(['processed' => $result], 200);
        },
        'args' => [
            'batch_size' => ['required' => false, 'type' => 'integer', 'default' => 10, 'sanitize_callback' => 'absint'],
        ],
    ]);
});
