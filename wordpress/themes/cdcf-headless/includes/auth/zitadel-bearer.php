<?php
/**
 * Zitadel bearer-token authenticator for WP REST.
 *
 * Hooks into determine_current_user. When the request carries an
 * `Authorization: Bearer <token>` header and no earlier filter has
 * already authenticated the request, validates the token against
 * Zitadel's /oidc/v1/userinfo endpoint and resolves the WP user by
 * the email claim.
 *
 * Caches accepted (token-hash → user_id) pairs in a transient for a
 * short TTL (60s by default). The cache key is sha256(token) so raw
 * tokens never land in the WP options table.
 *
 * Falls through (returns $user_id unchanged) on any failure path so
 * Application Passwords, auth cookies, and other auth methods keep
 * working unaffected. First-time logins for a Zitadel user with no
 * matching WP account also fall through — no automatic provisioning
 * (per plan: a Zitadel admin manually creates the WP user + the
 * author_team_member link, then the user logs in).
 *
 * See ~/.claude/plans/cdcf-bio-edit-zitadel.md Phase 1b.
 */

defined('ABSPATH') || exit;

const CDCF_ZITADEL_USERINFO_URL = 'https://auth.catholicdigitalcommons.org/oidc/v1/userinfo';
const CDCF_ZITADEL_BEARER_CACHE_TTL = 60;
const CDCF_ZITADEL_USERINFO_TIMEOUT = 5;

// The OIDC client ID of the CDCF Website Web app, as provisioned by
// cdcf-infra's setup-zitadel.sh --provision-cdcf-website. Must be set in
// wp-config.php (or via a deploy-time constant injection) before any bearer
// token will be accepted — without it, audience verification fails closed
// and every Bearer auth attempt falls through. This guards against a token
// minted for a sibling umbrella property (LitCal, OntoKit, BibleGet) being
// replayed against cdcf-website's REST.
defined('CDCF_ZITADEL_EXPECTED_AUD') || define('CDCF_ZITADEL_EXPECTED_AUD', '');

/**
 * Pull a bearer token out of the incoming request's Authorization
 * header. Handles the three places PHP+Apache+FCGI might surface it
 * (HTTP_AUTHORIZATION is most common; REDIRECT_HTTP_AUTHORIZATION is
 * the Apache mod_rewrite + FCGI case; getallheaders() is the last
 * resort for environments that strip both).
 *
 * Returns the token string (sans "Bearer " prefix) or '' if none.
 */
function cdcf_zitadel_bearer_extract_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    $header = (string) $v;
                    break;
                }
            }
        }
    }
    if (stripos($header, 'Bearer ') !== 0) {
        return '';
    }
    return trim(substr($header, 7));
}

/**
 * Hash a token for transient cache keying so raw tokens never land
 * in the WP options table.
 */
function cdcf_zitadel_bearer_cache_key(string $token): string {
    return 'cdcf_zb_' . hash('sha256', $token);
}

/**
 * Decode a JWT's payload segment without signature verification, returning
 * the parsed claim array or null if the token is not a well-formed JWT.
 *
 * Signature verification is delegated to Zitadel's userinfo endpoint — by
 * the time we reach here on a 200 response, the signature has cryptographic-
 * ally bound the payload, so reading aud/iss/exp claims from the unverified
 * payload is integrity-safe (a tampered payload would have failed userinfo's
 * own signature check upstream).
 *
 * Reads only the middle base64url segment; the header and signature segments
 * are not consulted. Returns null if there are not exactly three segments,
 * if the middle segment is not valid base64url, or if it doesn't decode to
 * a JSON object.
 */
function cdcf_zitadel_bearer_decode_jwt_payload(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    // base64url → base64; add padding (PHP's base64_decode tolerates missing
    // padding when strict=false, but be explicit so behaviour is stable).
    $b64 = strtr($parts[1], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $json = base64_decode($b64, true);
    if (!is_string($json)) {
        return null;
    }
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : null;
}

/**
 * Verify a JWT's `aud` claim contains the expected client ID. Accepts
 * either a string or string-array `aud` (RFC 7519 permits both). Uses
 * hash_equals to avoid timing leaks on the comparison.
 */
function cdcf_zitadel_bearer_audience_ok(array $claims, string $expected): bool {
    if ($expected === '') {
        return false; // Misconfigured — fail closed.
    }
    $aud = $claims['aud'] ?? null;
    $auds = is_array($aud) ? $aud : (is_string($aud) ? [$aud] : []);
    foreach ($auds as $candidate) {
        if (is_string($candidate) && hash_equals($expected, $candidate)) {
            return true;
        }
    }
    return false;
}

/**
 * determine_current_user filter callback.
 *
 * @param int|false $user_id Value passed by the previous filter — int
 *                           user ID if already authenticated, 0/false
 *                           if not.
 * @return int|false WP user ID on accept, $user_id unchanged otherwise.
 */
function cdcf_zitadel_bearer_authenticate($user_id) {
    if (is_int($user_id) && $user_id > 0) {
        return $user_id; // Prior filter already authenticated.
    }

    $token = cdcf_zitadel_bearer_extract_token();
    if ($token === '') {
        return $user_id; // No bearer token to process.
    }

    // Verify the token was minted for THIS app — not a sibling umbrella
    // property. Umbrella login names are emails (instance-wide via
    // UserEmailAsUsername=true), so a token issued for LitCal/OntoKit/
    // BibleGet would otherwise pass our email-based WP user lookup. We
    // decode the unverified payload only — signature verification is the
    // userinfo round-trip's job — and require the aud claim to match the
    // configured CDCF Website client ID.
    $claims = cdcf_zitadel_bearer_decode_jwt_payload($token);
    if (!is_array($claims)) {
        // Opaque token, or a malformed JWT. We require JWT access tokens
        // (configured via OIDC_TOKEN_TYPE_JWT in cdcf-infra) so this is a
        // hard reject.
        return $user_id;
    }
    if (!cdcf_zitadel_bearer_audience_ok($claims, CDCF_ZITADEL_EXPECTED_AUD)) {
        if (CDCF_ZITADEL_EXPECTED_AUD === '') {
            error_log('[cdcf-zitadel-bearer] CDCF_ZITADEL_EXPECTED_AUD not configured — rejecting all bearer tokens');
        }
        return $user_id;
    }

    $cache_key = cdcf_zitadel_bearer_cache_key($token);
    $cached = get_transient($cache_key);
    if (is_int($cached) && $cached > 0) {
        return $cached;
    }

    $response = wp_remote_post(CDCF_ZITADEL_USERINFO_URL, [
        'timeout' => CDCF_ZITADEL_USERINFO_TIMEOUT,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ]);
    if (is_wp_error($response)) {
        error_log('[cdcf-zitadel-bearer] userinfo request failed: ' . $response->get_error_message());
        return $user_id;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        // 401 is the common expected case (token expired/revoked) —
        // don't log spam for it.
        if ($code !== 401) {
            error_log('[cdcf-zitadel-bearer] userinfo returned HTTP ' . $code);
        }
        return $user_id;
    }

    $body = wp_remote_retrieve_body($response);
    $userinfo = json_decode((string) $body, true);
    if (!is_array($userinfo)) {
        error_log('[cdcf-zitadel-bearer] userinfo returned malformed JSON');
        return $user_id;
    }
    if (empty($userinfo['email_verified']) || empty($userinfo['email'])) {
        // Either no email at all, or unverified. We require both for
        // the email → WP user lookup to be trustworthy.
        return $user_id;
    }

    $user = get_user_by('email', sanitize_email((string) $userinfo['email']));
    if (!($user instanceof WP_User)) {
        return $user_id;
    }

    set_transient($cache_key, (int) $user->ID, CDCF_ZITADEL_BEARER_CACHE_TTL);
    return (int) $user->ID;
}

// Hook wiring lives in functions.php (alongside the require_once for this
// file) — keeping it out of here lets PHPUnit load the file without
// stubbing add_filter().
