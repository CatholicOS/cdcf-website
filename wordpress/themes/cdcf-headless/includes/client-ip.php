<?php
/**
 * The visitor's address for public REST endpoints.
 *
 * Every public request reaches WordPress from the Next.js server, so
 * $_SERVER['REMOTE_ADDR'] is that server's address for all visitors. The
 * Next.js routes forward the visitor's address in X-CDCF-Client-IP, with
 * its HMAC-SHA256 under CDCF_CLIENT_IP_SECRET in X-CDCF-Client-IP-Signature.
 * It is trusted only when the signature holds and the value is an IP
 * address; otherwise the connection address is used (direct callers, a
 * frontend with a different secret, or no secret configured). The secret is
 * deliberately not the preview secret, which reaches browsers in preview
 * URLs.
 */

defined('ABSPATH') || exit;

function cdcf_client_ip_secret(): string {
    return defined('CDCF_CLIENT_IP_SECRET')
        ? (string) CDCF_CLIENT_IP_SECRET
        : (string) (getenv('CDCF_CLIENT_IP_SECRET') ?: '');
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
