<?php
/**
 * REST route handler for /cdcf/v1/refer-local-group.
 *
 * Public-facing endpoint that takes a verification code (issued by
 * /refer-local-group/send-code) along with the local-group metadata,
 * and on success creates a `pending` local_group post for admin review
 * + emails the admin.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_refer_local_group(WP_REST_Request $request) {
    // Resolve + validate the submission content language up front so we
    // refuse tampered values before any side effects.
    $language = cdcf_validate_submission_language($request['language']);
    if (is_wp_error($language)) {
        return $language;
    }

    // Rate limiting via transients: 3 submissions per hour per IP (defense-in-depth).
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $transient_key = 'cdcf_refer_' . md5($ip);
    $count = (int) get_transient($transient_key);

    if ($count >= 3) {
        return new WP_Error(
            'rate_limited',
            'Too many submissions. Please try again later.',
            ['status' => 429]
        );
    }

    set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

    // Layer 5: IP DNSBL check.
    if (cdcf_check_ip_rbl($ip)) {
        return new WP_Error('forbidden', 'Request blocked.', ['status' => 403]);
    }

    // Validate email format.
    if (!is_email($request['submitter_email'])) {
        return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status' => 400]);
    }

    // Layer 6: Disposable email check.
    if (cdcf_is_disposable_email($request['submitter_email'])) {
        return new WP_Error('disposable_email', 'Please use a permanent email address.', ['status' => 400]);
    }

    // Layer 7: Content spam scoring — silent success so bots don't adapt.
    if (cdcf_is_spam_content($request['description'] . ' ' . $request['group_name'])) {
        return rest_ensure_response(['success' => true, 'post_id' => 0]);
    }

    // Verify email verification code.
    $email    = $request['submitter_email'];
    $code_key = 'cdcf_email_code_' . md5($email);
    $stored   = get_transient($code_key);

    if (!$stored) {
        return new WP_Error('code_expired', 'Verification code has expired. Please request a new one.', ['status' => 400]);
    }

    if ($stored['attempts'] >= 5) {
        delete_transient($code_key);
        return new WP_Error('too_many_attempts', 'Too many incorrect attempts. Please request a new code.', ['status' => 429]);
    }

    if ($request['verification_code'] !== $stored['code']) {
        $stored['attempts']++;
        set_transient($code_key, $stored, 600);
        return new WP_Error('invalid_code', 'Invalid verification code. Please check and try again.', ['status' => 400]);
    }

    // Code is valid — delete it (single use).
    delete_transient($code_key);

    // Create a pending local_group post.
    $post_id = wp_insert_post([
        'post_type'   => 'local_group',
        'post_status' => 'pending',
        'post_title'  => $request['group_name'],
    ]);

    if (is_wp_error($post_id) || !$post_id) {
        return new WP_Error('insert_failed', 'Failed to create referral.', ['status' => 500]);
    }

    // Tag the post with its submission content language so Polylang
    // can link translations later. Defaults to 'en' for legacy callers
    // that don't send the field; see cdcf_validate_submission_language.
    if (function_exists('pll_set_post_language')) {
        pll_set_post_language($post_id, $language);
    }

    // Set ACF fields if ACF is active.
    if (function_exists('update_field')) {
        update_field('group_description', $request['description'], $post_id);
        update_field('group_url', $request['url'], $post_id);
        if ($request['location']) {
            update_field('group_location', $request['location'], $post_id);
        }
    }

    // Store submitter info as private post meta.
    update_post_meta($post_id, '_referral_submitter_name', $request['submitter_name']);
    update_post_meta($post_id, '_referral_submitter_email', $request['submitter_email']);

    // Send admin notification email.
    $admin_email = get_option('admin_email');
    $edit_link   = admin_url("post.php?post={$post_id}&action=edit");
    $subject     = sprintf('[CDCF] New Local Group Referral: %s', $request['group_name']);
    $body        = sprintf(
        "A new local group referral has been submitted for review.\n\n" .
        "Group Name: %s\n" .
        "Location: %s\n" .
        "URL: %s\n" .
        "Description:\n%s\n\n" .
        "Submitted by: %s (%s)\n\n" .
        "Review and approve it here:\n%s",
        $request['group_name'],
        $request['location'] ?: '(not provided)',
        $request['url'],
        $request['description'],
        $request['submitter_name'],
        $request['submitter_email'],
        $edit_link
    );

    wp_mail($admin_email, $subject, $body);

    return rest_ensure_response([
        'success' => true,
        'post_id' => $post_id,
    ]);
}
