<?php
/**
 * Zitadel bearer-token authenticator for WP REST.
 *
 * Hooks into determine_current_user. When the request carries an
 * `Authorization: Bearer <token>` header and no earlier filter has
 * already authenticated the request, validates the token against
 * Zitadel's /oidc/v1/userinfo endpoint and resolves the WP user.
 *
 * Resolution order (see cdcf_zitadel_bearer_resolve_user):
 *   1. By Zitadel `sub` claim (user_meta `cdcf_zitadel_sub`) — the
 *      immutable cross-system identity primary key. If the claim's
 *      email differs from `user_email`, WP is updated to match.
 *   2. By email (one-time migration for users created before sub
 *      binding existed) — writes the sub user-meta on match.
 *   3. Auto-provision a Subscriber when both lookups miss
 *      (Phase 5 — supersedes the prior "no auto-provisioning"
 *      stance for the Subscriber path; elevated roles still flow
 *      through Phase 6's admin-approval workflow).
 *
 * Caches accepted (token-hash → user_id) pairs in a transient for a
 * short TTL (60s by default). The cache key is sha256(token) so raw
 * tokens never land in the WP options table.
 *
 * Falls through (returns $user_id unchanged) on any failure path so
 * Application Passwords, auth cookies, and other auth methods keep
 * working unaffected. Hard fall-through conditions: opaque/non-JWT
 * token, audience mismatch, non-200 userinfo, malformed JSON,
 * `email_verified !== true`, and missing `sub` claim.
 *
 * See ~/.claude/plans/cdcf-role-mirroring.md Phase 5 Track B.
 */

defined('ABSPATH') || exit;

const CDCF_ZITADEL_USERINFO_URL = 'https://auth.catholicdigitalcommons.org/oidc/v1/userinfo';
const CDCF_ZITADEL_BEARER_CACHE_TTL = 60;
const CDCF_ZITADEL_USERINFO_TIMEOUT = 5;

// Comma-separated allow-list of OIDC client IDs we accept on bearer
// tokens — as provisioned by cdcf-infra's
// setup-zitadel.sh --provision-cdcf-website. The CDCF Org owns TWO
// confidential apps: one for production (prod origin only, devMode=false)
// and one for non-production (staging + localhost dev, devMode=true), so
// the shared WP backend must accept both client IDs. See cdcf-infra
// auth/handoffs/cdcf-website.md and issue #173 for the full shape.
//
// Must be set in wp-config.php before any bearer token will be accepted —
// without it (or with only whitespace), audience verification fails closed
// and every Bearer auth attempt falls through. This is what blocks tokens
// minted for sibling umbrella properties (LitCal, OntoKit, BibleGet) from
// being replayed against cdcf-website's REST.
//
// Example wp-config.php line:
//   define('CDCF_ZITADEL_EXPECTED_AUD', '<prod_client_id>,<nonprod_client_id>');
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
 * Verify a JWT's `aud` claim contains at least one of the allow-listed
 * client IDs. Accepts either a string or string-array `aud` (RFC 7519
 * permits both). Uses hash_equals to avoid timing leaks on each
 * comparison.
 *
 * The shared WP backend serves both the production and the non-production
 * Next.js frontends, each of which is registered as its own confidential
 * client under the CDCF Org and therefore mints tokens with a different
 * `aud` claim. Accepting both client IDs at the WP layer is what lets a
 * single deploy serve catholicdigitalcommons.org + staging.* + localhost
 * dev — see issue #173.
 *
 * @param array<string,mixed> $claims  Decoded JWT payload.
 * @param array<int,string>   $allowed Allow-list of accepted client IDs.
 *                                     Empty/whitespace entries must be
 *                                     stripped by the caller; an empty
 *                                     allow-list reaches this function
 *                                     as `[]` and fails closed.
 */
function cdcf_zitadel_bearer_audience_ok(array $claims, array $allowed): bool {
    if (empty($allowed)) {
        return false; // Misconfigured — fail closed.
    }
    $aud = $claims['aud'] ?? null;
    $token_auds = is_array($aud) ? $aud : (is_string($aud) ? [$aud] : []);
    foreach ($token_auds as $token_aud) {
        if (!is_string($token_aud)) {
            continue;
        }
        foreach ($allowed as $allowed_aud) {
            if (is_string($allowed_aud) && hash_equals($allowed_aud, $token_aud)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Parse the CDCF_ZITADEL_EXPECTED_AUD constant into the allow-list.
 * Accepts comma-separated values, trims whitespace, drops empty entries.
 * Returns [] when the constant is unset, empty, or whitespace-only —
 * which makes every audience check fail closed.
 */
function cdcf_zitadel_bearer_parse_allowed_auds(string $raw): array {
    if ($raw === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
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
    $allowed = cdcf_zitadel_bearer_parse_allowed_auds(CDCF_ZITADEL_EXPECTED_AUD);
    if (!cdcf_zitadel_bearer_audience_ok($claims, $allowed)) {
        if (empty($allowed)) {
            error_log('[cdcf-zitadel-bearer] CDCF_ZITADEL_EXPECTED_AUD not configured (or only whitespace) — rejecting all bearer tokens');
        }
        return $user_id;
    }

    $cache_key = cdcf_zitadel_bearer_cache_key($token);
    $cached = get_transient($cache_key);
    // DB-backed transients round-trip the value through MySQL and can come
    // back as a numeric string even when set as int — accept either shape
    // so the 60s cache actually hits in the no-object-cache (default) case.
    if (is_numeric($cached) && (int) $cached > 0) {
        return (int) $cached;
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
    // Strict boolean true — reject non-boolean truthy payloads (e.g.
    // string "true", 1) so a spec-violating IdP can't trick the
    // verified-email gate. The umbrella Zitadel emits a real bool.
    if (($userinfo['email_verified'] ?? null) !== true || empty($userinfo['email'])) {
        // Either no email at all, or unverified. We require both for
        // the email → WP user lookup to be trustworthy AND for
        // auto-provisioning to be safe.
        return $user_id;
    }
    // The Zitadel `sub` claim is the immutable primary key for the
    // cross-system user identity. We refuse to auto-provision (or even
    // resolve by email) without it because email is mutable in Zitadel,
    // and using email-only would silently create a second WP user every
    // time the operator changed their Zitadel email.
    if (empty($userinfo['sub']) || !is_string($userinfo['sub'])) {
        error_log('[cdcf-zitadel-bearer] userinfo missing sub claim');
        return $user_id;
    }

    // Pull the OIDC standard profile claims that map to WP user fields
    // on auto-provision. Each falls back to '' when absent — the
    // downstream resolver handles missing pieces (e.g. empty display_name
    // falls back to email so the WP user row is never literally blank).
    $resolved_id = cdcf_zitadel_bearer_resolve_user(
        (string) $userinfo['sub'],
        sanitize_email((string) $userinfo['email']),
        [
            'display_name' => is_string($userinfo['name'] ?? null) ? $userinfo['name'] : '',
            'first_name'   => is_string($userinfo['given_name'] ?? null) ? $userinfo['given_name'] : '',
            'last_name'    => is_string($userinfo['family_name'] ?? null) ? $userinfo['family_name'] : '',
        ]
    );
    if ($resolved_id <= 0) {
        return $user_id;
    }

    set_transient($cache_key, $resolved_id, CDCF_ZITADEL_BEARER_CACHE_TTL);
    return $resolved_id;
}

/**
 * Resolve (or auto-provision) the WP user for the given Zitadel
 * identity. Returns the WP user id, or 0 on hard failure (in which
 * case the caller falls through and the request remains unauthenticated).
 *
 * Lookup order:
 *  1. By Zitadel `sub` claim, stored as user meta `cdcf_zitadel_sub`.
 *     The sub is immutable across Zitadel email changes, so it's the
 *     primary key for cross-system identity. If found and the claim's
 *     email differs from the WP `user_email`, update WP to match — the
 *     email-drift sync that prevents a second WP user from being
 *     created when a Zitadel user changes their email.
 *  2. By email (one-time migration for WP users created before this
 *     change). On match, write the sub user-meta so future requests
 *     take the fast path.
 *  3. Auto-provision a Subscriber. WP `user_login` = full email
 *     (immutable per WP, matches the umbrella Zitadel
 *     `UserEmailAsUsername=true` invariant at creation time). Random
 *     password is generated and never surfaced — sign-in must always
 *     flow through Zitadel.
 *
 * The $profile array carries the OIDC profile claims used on
 * auto-provision (display_name from `name`, first_name from
 * `given_name`, last_name from `family_name`). Each key is a string,
 * may be empty, and is ignored on the sub-hit / email-hit paths
 * (those return an existing WP user we don't want to overwrite).
 *
 * See Phase 5 of ~/.claude/plans/cdcf-role-mirroring.md for the design.
 *
 * @param array{display_name:string,first_name:string,last_name:string} $profile
 */
function cdcf_zitadel_bearer_resolve_user(string $sub, string $email, array $profile): int {
    // 1. sub primary key.
    $user = cdcf_zitadel_bearer_user_by_sub($sub);
    if ($user instanceof WP_User) {
        if ($email !== '' && strcasecmp((string) $user->user_email, $email) !== 0) {
            $upd = wp_update_user(['ID' => (int) $user->ID, 'user_email' => $email]);
            if (is_wp_error($upd)) {
                error_log('[cdcf-zitadel-bearer] email drift sync failed for user ' . $user->ID . ': ' . $upd->get_error_message());
            }
        }
        return (int) $user->ID;
    }

    // 2. Email fallback (migration of pre-existing users).
    if ($email === '' || !is_email($email)) {
        return 0;
    }
    $user = get_user_by('email', $email);
    if ($user instanceof WP_User) {
        update_user_meta((int) $user->ID, 'cdcf_zitadel_sub', $sub);
        return (int) $user->ID;
    }

    // 3. Auto-provision a Subscriber.
    return cdcf_zitadel_bearer_auto_provision_subscriber($sub, $email, $profile);
}

/**
 * Find the WP user whose `cdcf_zitadel_sub` user-meta equals $sub.
 * Returns null if no match. Reads via get_users() with a single-row
 * limit so we never return ambiguous results.
 */
function cdcf_zitadel_bearer_user_by_sub(string $sub): ?WP_User {
    if ($sub === '') {
        return null;
    }
    $matches = get_users([
        'meta_key'    => 'cdcf_zitadel_sub',
        'meta_value'  => $sub,
        'number'      => 1,
        'count_total' => false,
        'fields'      => 'all',
    ]);
    if (empty($matches) || !($matches[0] instanceof WP_User)) {
        return null;
    }
    return $matches[0];
}

/**
 * wp_insert_user() a Subscriber for the given Zitadel identity and
 * bind the sub user-meta. On race (two parallel sign-ins for the same
 * sub each reaching the auto-provision branch), the second
 * wp_insert_user fails with user_email_exists; we re-resolve by sub
 * — populated by the winner — and return that id rather than creating
 * a duplicate.
 *
 * Returns the WP user id on success, 0 on hard failure.
 *
 * @param array{display_name:string,first_name:string,last_name:string} $profile
 */
function cdcf_zitadel_bearer_auto_provision_subscriber(string $sub, string $email, array $profile): int {
    // Empty first/last/display are passed through unset rather than as
    // '' so wp_insert_user uses its own defaults (and a missing
    // display_name falls back to the email) instead of overwriting with
    // blanks — keeps a partial-profile sign-up from producing a
    // visibly-empty WP user row.
    $args = [
        'user_login'   => $email,
        'user_email'   => $email,
        'role'         => 'subscriber',
        'user_pass'    => wp_generate_password(64, true, true),
        'display_name' => $profile['display_name'] !== '' ? $profile['display_name'] : $email,
    ];
    if ($profile['first_name'] !== '') {
        $args['first_name'] = $profile['first_name'];
    }
    if ($profile['last_name'] !== '') {
        $args['last_name'] = $profile['last_name'];
    }
    $result = wp_insert_user($args);

    if (is_wp_error($result)) {
        // Race with a parallel auto-provision: try the sub primary
        // key once more; if the winner already bound, that's our id.
        $racer = cdcf_zitadel_bearer_user_by_sub($sub);
        if ($racer instanceof WP_User) {
            return (int) $racer->ID;
        }
        // Also try email — the parallel writer might have bound by
        // email-fallback rather than auto-provision (different path,
        // same outcome).
        $by_email = get_user_by('email', $email);
        if ($by_email instanceof WP_User) {
            update_user_meta((int) $by_email->ID, 'cdcf_zitadel_sub', $sub);
            return (int) $by_email->ID;
        }
        error_log('[cdcf-zitadel-bearer] auto-provision failed for ' . $email . ': ' . $result->get_error_message());
        return 0;
    }

    update_user_meta((int) $result, 'cdcf_zitadel_sub', $sub);
    return (int) $result;
}

// Hook wiring lives in functions.php (alongside the require_once for this
// file) — keeping it out of here lets PHPUnit load the file without
// stubbing add_filter().
