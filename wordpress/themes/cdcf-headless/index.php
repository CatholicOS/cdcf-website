<?php
/**
 * Headless theme — redirect all front-end requests to Next.js.
 */

$frontend_url = defined('CDCF_FRONTEND_URL')
    ? CDCF_FRONTEND_URL
    : 'http://localhost:3000';

wp_redirect($frontend_url . $_SERVER['REQUEST_URI'], 301);
exit;
