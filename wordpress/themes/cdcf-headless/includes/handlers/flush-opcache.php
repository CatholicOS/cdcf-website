<?php
/**
 * REST route handler for /cdcf/v1/flush-opcache.
 *
 * Invalidates OPcache for the theme's functions.php so new CPT
 * registrations / hook changes take effect after deploy without
 * waiting for the OPcache TTL. Also flushes rewrite rules.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_flush_opcache(WP_REST_Request $request) {
    unset($request); // unused — endpoint takes no body
    $flushed = [];
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(CDCF_FUNCTIONS_FILE, true);
        $flushed[] = 'functions.php';
    }
    if (function_exists('opcache_reset')) {
        opcache_reset();
        $flushed[] = 'full-reset';
    }
    flush_rewrite_rules();
    $flushed[] = 'rewrite-rules';
    return rest_ensure_response(['flushed' => $flushed]);
}
