<?php

/**
 * Spam-protection helpers shared by every public-submission endpoint
 * (refer-local-group, refer-community-project, submit-project, and
 * their /send-code siblings).
 *
 * Pure-ish helpers — no REST plumbing here; each is called from the
 * abuse-check pipeline in the handlers under includes/handlers/.
 */

defined('ABSPATH') || exit;

/**
 * Check whether an IP is listed in DNS-based Real-time Blackhole Lists.
 * Returns true if the IP is listed on any checked RBL.
 * Results are cached in a transient for 1 hour.
 */
function cdcf_check_ip_rbl(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false; // Only IPv4 is supported by DNSBL lookups.
    }

    $cache_key = 'cdcf_rbl_' . md5($ip);
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        return $cached === 'listed';
    }

    $reversed = implode('.', array_reverse(explode('.', $ip)));
    $rbls     = ['zen.spamhaus.org', 'bl.spamcop.net'];
    $listed   = false;

    foreach ($rbls as $rbl) {
        if (checkdnsrr("{$reversed}.{$rbl}", 'A')) {
            $listed = true;
            break;
        }
    }

    set_transient($cache_key, $listed ? 'listed' : 'clean', HOUR_IN_SECONDS);
    return $listed;
}

/**
 * Check whether an email address uses a known disposable/throwaway domain.
 */
function cdcf_is_disposable_email(string $email): bool {
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    if (!$domain) {
        return false;
    }

    static $domains = null;
    if ($domains === null) {
        $file = CDCF_DISPOSABLE_DOMAINS_FILE;
        if (!file_exists($file)) {
            return false;
        }
        $domains = array_flip(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
    }

    return isset($domains[$domain]);
}

/**
 * Score text content for spam indicators. Returns true if likely spam (score >= 5).
 */
function cdcf_is_spam_content(string $text): bool {
    $score = 0;

    // Excessive URLs (> 2)
    $url_count = preg_match_all('#https?://#i', $text);
    if ($url_count > 2) {
        $score += 2;
    }

    // Common spam keywords
    $spam_keywords = [
        'viagra', 'cialis', 'casino', 'lottery', 'poker', 'blackjack',
        'buy now', 'free money', 'click here', 'act now', 'limited time',
        'nigerian prince', 'wire transfer', 'cryptocurrency offer',
    ];
    $lower = strtolower($text);
    foreach ($spam_keywords as $kw) {
        if (str_contains($lower, $kw)) {
            $score += 3;
        }
    }

    // HTML/script injection attempts
    if (preg_match('/<\s*(script|iframe|object|embed|form|style)\b/i', $text)) {
        $score += 10;
    }

    // Excessive email addresses in content (> 1)
    $email_count = preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text);
    if ($email_count > 1) {
        $score += 2;
    }

    // Non-Latin script ratio (> 50% suggests gibberish or Cyrillic spam)
    $total_chars = mb_strlen(preg_replace('/\s+/', '', $text));
    if ($total_chars > 0) {
        $latin_chars = preg_match_all('/[\x20-\x7E\xC0-\xFF]/u', $text);
        if ($latin_chars / $total_chars < 0.5) {
            $score += 2;
        }
    }

    return $score >= 5;
}

/**
 * Resolve + validate the submission content language for a public
 * submission form. Public submission forms (project, community_project,
 * local_group) now expose a language selector defaulting to the page's
 * current locale; this helper normalizes the request param to a
 * configured Polylang locale.
 *
 * Empty / unset / null → default 'en' (legacy contract, plus the
 * back-compat path for any caller that doesn't yet send the field).
 * Any other value MUST be a key of CDCF_LOCALE_NAMES — otherwise we
 * return WP_Error so the handler can refuse a tampered request rather
 * than land a post in an unconfigured language. CDCF_LOCALE_NAMES is
 * defined in functions.php (en/it/es/fr/pt/de).
 *
 * @return string|WP_Error Validated locale slug on success.
 */
function cdcf_validate_submission_language($value) {
    $lang = is_string($value) ? trim($value) : '';
    if ($lang === '') {
        return 'en';
    }
    $allowed = defined('CDCF_LOCALE_NAMES') ? array_keys(CDCF_LOCALE_NAMES) : ['en'];
    if (!in_array($lang, $allowed, true)) {
        return new WP_Error(
            'invalid_language',
            'Submission language must be one of the configured site locales.',
            ['status' => 400]
        );
    }
    return $lang;
}
