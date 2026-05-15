<?php
/**
 * Plugin Name: CDCF Redis Translations
 * Description: Redis Queue integration for async AI translations. Requires the redis-queue plugin.
 * Version:     1.0.0
 * Author:      Catholic Digital Commons Foundation
 * Requires PHP: 8.3
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/handlers.php';

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
        'permission_callback' => 'cdcf_process_queue_permission_check',
        'callback'            => 'cdcf_handle_process_queue',
        'args' => [
            'batch_size' => ['required' => false, 'type' => 'integer', 'default' => 10, 'sanitize_callback' => 'absint'],
        ],
    ]);

    register_rest_route('cdcf/v1', '/maintenance', [
        'methods'             => 'POST',
        'permission_callback' => 'cdcf_maintenance_permission_check',
        'callback'            => 'cdcf_handle_maintenance',
        'args' => [
            'action' => [
                'required' => true,
                'type'     => 'string',
            ],
            'duration_seconds' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 300,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});
