<?php
/**
 * The visitor's address for public REST endpoints.
 *
 * Every public request reaches WordPress from the Next.js server, so
 * $_SERVER['REMOTE_ADDR'] is that server's address for all visitors. The
 * Next.js routes forward the visitor's address in X-CDCF-Client-IP, signed
 * with an HMAC-SHA256 of the shared preview secret in
 * X-CDCF-Client-IP-Signature. It is trusted only when the signature holds
 * and the value is an IP address; otherwise the connection address is used
 * (direct callers, or a frontend with a different secret).
 */

defined('ABSPATH') || exit;

function cdcf_client_ip_secret(): string {
    return defined('CDCF_PREVIEW_SECRET')
        ? (string) CDCF_PREVIEW_SECRET
        : (string) (getenv('WP_PREVIEW_SECRET') ?: '');
}

function cdcf_request_client_ip(WP_REST_Request $request): string {
    $connection = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $secret     = cdcf_client_ip_secret();
    $ip         = trim((string) $request->get_header('x-cdcf-client-ip'));
    $signature  = trim((string) $request->get_header('x-cdcf-client-ip-signature'));
    if ($secret === '' || $ip === '' || $signature === '') {
        return $connection;
    }
    if (!hash_equals(hash_hmac('sha256', $ip, $secret), $signature)) {
        return $connection;
    }
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $connection;
}
