<?php
/**
 * REST route handler for /cdcf/v1/refer-local-group/send-code AND
 * /cdcf/v1/refer-community-project/send-code.
 *
 * Both /send-code routes share this handler. Sends a six-digit
 * verification code to the submitter's email after a layered abuse
 * check: IP rate-limit, honeypot, timing, DNSBL, email format,
 * disposable-domain check, and content spam scoring.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_send_verification_code(WP_REST_Request $request) {
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    // IP rate limit: max 5 code requests per hour.
    $ip_key   = 'cdcf_verify_' . md5($ip);
    $ip_count = (int) get_transient($ip_key);
    if ($ip_count >= 5) {
        return new WP_Error('rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
    }
    set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);

    // Honeypot — silent success so bots don't adapt.
    if (!empty($request['honeypot'])) {
        return rest_ensure_response(['success' => true]);
    }

    // Timing check — too fast means bot.
    $elapsed = (int) $request['elapsed_ms'];
    if ($elapsed > 0 && $elapsed < 3000) {
        return rest_ensure_response(['success' => true]);
    }

    // DNSBL check.
    if (cdcf_check_ip_rbl($ip)) {
        return new WP_Error('forbidden', 'Request blocked.', ['status' => 403]);
    }

    // Validate email format.
    if (!is_email($request['submitter_email'])) {
        return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status' => 400]);
    }

    // Disposable email check.
    if (cdcf_is_disposable_email($request['submitter_email'])) {
        return new WP_Error('disposable_email', 'Please use a permanent email address.', ['status' => 400]);
    }

    // Content spam scoring — silent success so bots don't adapt.
    // This handler is shared between /refer-local-group/send-code
    // (which posts `group_name`) and /refer-community-project/send-code
    // (which posts `project_name`). Prefer project_name when present so
    // both routes feed the spam scorer the full submission text.
    $name = $request['project_name'] ?? $request['group_name'] ?? '';
    if (cdcf_is_spam_content($request['description'] . ' ' . $name)) {
        return rest_ensure_response(['success' => true]);
    }

    // Email send rate limit: max 3 codes per hour per email.
    $email       = $request['submitter_email'];
    $sends_key   = 'cdcf_code_sends_' . md5($email);
    $sends_count = (int) get_transient($sends_key);
    if ($sends_count >= 3) {
        return new WP_Error('rate_limited', 'Too many code requests for this email. Please try again later.', ['status' => 429]);
    }
    set_transient($sends_key, $sends_count + 1, HOUR_IN_SECONDS);

    // Generate 6-digit code and store in transient (10 min TTL).
    $code         = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $code_key     = 'cdcf_email_code_' . md5($email);
    set_transient($code_key, ['code' => $code, 'attempts' => 0], 600);

    // Send the code via email.
    $subject = '[CDCF] Your verification code';
    $body    = sprintf(
        "Your verification code is: %s\n\n" .
        "Enter this code in the referral form to complete your submission.\n" .
        "This code expires in 10 minutes.\n\n" .
        "If you did not request this code, you can safely ignore this email.",
        $code
    );

    $sent = wp_mail($email, $subject, $body);
    if (!$sent) {
        return new WP_Error('mail_failed', 'Failed to send verification email. Please try again.', ['status' => 500]);
    }

    return rest_ensure_response(['success' => true]);
}
