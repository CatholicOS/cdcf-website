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
