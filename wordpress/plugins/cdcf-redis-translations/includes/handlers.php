<?php
/**
 * REST route handlers for /cdcf/v1/*.
 *
 * Extracted from the inline closures in cdcf-redis-translations.php so
 * they can be unit-tested with Brain Monkey + Mockery. The plugin
 * entry require_once's this file and references the named functions in
 * its register_rest_route() calls.
 */

defined('ABSPATH') || exit;

/**
 * Permission callback for /cdcf/v1/process-queue.
 */
function cdcf_process_queue_permission_check(): bool {
    return current_user_can('manage_options');
}

/**
 * Handler for POST /cdcf/v1/process-queue.
 */
function cdcf_handle_process_queue(WP_REST_Request $request) {
    if (!function_exists('redis_queue')) {
        return new WP_REST_Response(['processed' => 0, 'error' => 'redis_queue not available'], 200);
    }
    ignore_user_abort(true);
    $batch_size = intval($request['batch_size'] ?? 10);
    $batch_size = max(1, min($batch_size, 50));
    $processor = redis_queue()->get_job_processor();
    $result = $processor->process_jobs(['default'], $batch_size);
    return new WP_REST_Response(['processed' => $result], 200);
}

/**
 * Permission callback for /cdcf/v1/maintenance.
 */
function cdcf_maintenance_permission_check(): bool {
    return current_user_can('manage_options');
}

/**
 * Handler for POST /cdcf/v1/maintenance.
 */
function cdcf_handle_maintenance(WP_REST_Request $request) {
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
    if ($redis->setex('cdcf:maintenance:until', $duration, '1') === false) {
        return new WP_Error(
            'redis_write_failed',
            'Failed to set maintenance flag in Redis',
            ['status' => 500]
        );
    }
    return new WP_REST_Response([
        'ok'       => true,
        'until'    => time() + $duration,
        'duration' => $duration,
    ], 200);
}
