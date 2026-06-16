<?php
/**
 * REST route handler for /cdcf/v1/submit-project.
 *
 * Public-facing endpoint for open-source project submissions. Same
 * verification-code skeleton as /cdcf/v1/refer-* but creates a
 * `project` post (not community_project) and accepts an array of
 * repository URLs (the first wins for the ACF project_repo_url
 * field; all URLs are stored as JSON in private meta for the
 * admin-side meta box).
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_submit_project(WP_REST_Request $request) {
    // Resolve + validate the submission content language up front so we
    // refuse tampered values before any side effects.
    $language = cdcf_validate_submission_language($request['language']);
    if (is_wp_error($language)) {
        return $language;
    }

    // Rate limiting via transients: 3 submissions per hour per IP (defense-in-depth).
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $transient_key = 'cdcf_projsub_' . md5($ip);
    $count = (int) get_transient($transient_key);

    if ($count >= 3) {
        return new WP_Error(
            'rate_limited',
            'Too many submissions. Please try again later.',
            ['status' => 429]
        );
    }

    set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

    // IP DNSBL check.
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
    if (cdcf_is_spam_content($request['description'] . ' ' . $request['project_name'])) {
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

    // Create a pending project post.
    $post_id = wp_insert_post([
        'post_type'    => 'project',
        'post_status'  => 'pending',
        'post_title'   => $request['project_name'],
        'post_content' => $request['description'],
    ]);

    if (is_wp_error($post_id) || !$post_id) {
        return new WP_Error('insert_failed', 'Failed to create project submission.', ['status' => 500]);
    }

    // Tag the post with its submission content language so Polylang
    // can link translations later. Defaults to 'en' for legacy callers
    // that don't send the field; see cdcf_validate_submission_language.
    if (function_exists('pll_set_post_language')) {
        pll_set_post_language($post_id, $language);
    }

    // Sanitise repo URLs.
    $repo_urls = array_values(array_filter(array_map('esc_url_raw', (array) $request['repo_urls'])));

    // Set ACF fields if ACF is active.
    if (function_exists('update_field')) {
        update_field('project_url', $request['url'], $post_id);
        update_field('project_status', 'incubating', $post_id);
        if (!empty($request['category'])) {
            update_field('project_category', $request['category'], $post_id);
        }
        if (!empty($repo_urls)) {
            update_field('project_repo_url', $repo_urls[0], $post_id);
        }
    }

    // Store all repo URLs as private meta (JSON-encoded array) for the meta box.
    if (!empty($repo_urls)) {
        update_post_meta($post_id, '_submission_repo_urls', wp_json_encode($repo_urls));
    }

    // Assign project tags.
    $tags = array_filter(array_map('sanitize_text_field', (array) $request['tags']));
    if (!empty($tags)) {
        wp_set_object_terms($post_id, $tags, 'project_tag');
    }

    // Store submitter info as private post meta.
    update_post_meta($post_id, '_submission_submitter_name', $request['submitter_name']);
    update_post_meta($post_id, '_submission_submitter_email', $request['submitter_email']);

    // Send admin notification email.
    $admin_email = get_option('admin_email');
    $edit_link   = admin_url("post.php?post={$post_id}&action=edit");
    $subject     = sprintf('[CDCF] New Project Submission: %s', $request['project_name']);

    $repo_list = !empty($repo_urls) ? implode("\n  ", $repo_urls) : '(none provided)';
    $body = sprintf(
        "A new project has been submitted for review.\n\n" .
        "Project Name: %s\n" .
        "Website: %s\n" .
        "Repositories:\n  %s\n" .
        "Description:\n%s\n\n" .
        "Submitted by: %s (%s)\n\n" .
        "Review and approve it here:\n%s",
        $request['project_name'],
        $request['url'],
        $repo_list,
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
