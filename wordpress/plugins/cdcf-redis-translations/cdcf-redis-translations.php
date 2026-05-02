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

    register_rest_route('cdcf/v1', '/maintenance', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback' => function (WP_REST_Request $request) {
            $action = $request['action'] ?? '';
            if ($action !== 'begin' && $action !== 'end') {
                return new WP_Error(
                    'invalid_action',
                    "action must be 'begin' or 'end'",
                    ['status' => 400]
                );
            }

            if (class_exists('Redis') === false) {
                return new WP_Error(
                    'redis_unavailable',
                    'PHP Redis extension not installed',
                    ['status' => 500]
                );
            }

            try {
                $redis = new Redis();
                if ($redis->connect('127.0.0.1', 6379, 1.0) === false) {
                    return new WP_Error(
                        'redis_unavailable',
                        'Could not connect to Redis at 127.0.0.1:6379',
                        ['status' => 500]
                    );
                }
            } catch (\Throwable $e) {
                return new WP_Error('redis_unavailable', $e->getMessage(), ['status' => 500]);
            }

            if ($action === 'end') {
                $redis->del('cdcf:maintenance:until');
                return new WP_REST_Response(['ok' => true], 200);
            }

            // action === 'begin'
            $duration = (int) ($request['duration_seconds'] ?? 300);
            $duration = max(60, min(600, $duration));
            $redis->setex('cdcf:maintenance:until', $duration, '1');
            return new WP_REST_Response([
                'ok'       => true,
                'until'    => time() + $duration,
                'duration' => $duration,
            ], 200);
        },
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
