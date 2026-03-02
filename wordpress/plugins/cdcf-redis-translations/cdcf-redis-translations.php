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
