<?php
/**
 * Council review — the submitter's status-page token.
 *
 * Only the SHA-256 hash is stored. Every submitter email carries a fresh
 * token, but its hash replaces the previous one only once that email has
 * actually been sent (cdcf_review_commit_token), so a failed email never
 * kills the submitter's working link. Commits are ordered by issue time:
 * a late email for an older token never replaces a newer one. The expiry
 * is set at decision time (Phase 4).
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_TOKEN_HASH_META    = '_cdcf_review_token_hash';
const CDCF_REVIEW_TOKEN_EXPIRES_META = '_cdcf_review_token_expires';
const CDCF_REVIEW_TOKEN_ISSUED_META  = '_cdcf_review_token_issued_at';

/**
 * A fresh token, not yet valid.
 *
 * @return array{token: string, hash: string, issued_at: string}
 */
function cdcf_review_new_token(): array {
    $token = bin2hex(random_bytes(32));
    return [
        'token'     => $token,
        'hash'      => hash('sha256', $token),
        'issued_at' => sprintf('%.6F', microtime(true)),
    ];
}

/** Make `$hash` the valid token, unless a newer one is already in place. */
function cdcf_review_commit_token(int $project_id, string $hash, string $issued_at): bool {
    $current = (string) get_post_meta($project_id, CDCF_REVIEW_TOKEN_ISSUED_META, true);
    if ($current !== '' && (float) $current > (float) $issued_at) {
        return false;
    }
    update_post_meta($project_id, CDCF_REVIEW_TOKEN_HASH_META, $hash);
    update_post_meta($project_id, CDCF_REVIEW_TOKEN_ISSUED_META, $issued_at);
    return true;
}

/** Issue a token and make it valid at once; returns the raw value. */
function cdcf_review_issue_token(int $project_id): string {
    $new = cdcf_review_new_token();
    cdcf_review_commit_token($project_id, $new['hash'], $new['issued_at']);
    return $new['token'];
}

function cdcf_review_token_matches(int $project_id, string $token): bool {
    if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        return false;
    }
    $stored = (string) get_post_meta($project_id, CDCF_REVIEW_TOKEN_HASH_META, true);
    if ($stored === '' || !hash_equals($stored, hash('sha256', $token))) {
        return false;
    }
    $expires = (int) get_post_meta($project_id, CDCF_REVIEW_TOKEN_EXPIRES_META, true);
    return $expires === 0 || time() <= $expires;
}
