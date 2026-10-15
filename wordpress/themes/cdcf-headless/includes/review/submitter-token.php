<?php
/**
 * Council review — the submitter's status-page token.
 *
 * Only the SHA-256 hash is stored. Issuing a token replaces the previous
 * hash, so every submitter email carries a fresh link and only the most
 * recent one works. The expiry is set at decision time (Phase 4).
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_TOKEN_HASH_META    = '_cdcf_review_token_hash';
const CDCF_REVIEW_TOKEN_EXPIRES_META = '_cdcf_review_token_expires';

/** Issue (or rotate) the token and return the raw value to email. */
function cdcf_review_issue_token(int $project_id): string {
    $token = bin2hex(random_bytes(32));
    update_post_meta($project_id, CDCF_REVIEW_TOKEN_HASH_META, hash('sha256', $token));
    return $token;
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
