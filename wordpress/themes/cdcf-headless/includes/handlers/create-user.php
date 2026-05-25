<?php
/**
 * REST route handler for /cdcf/v1/create-user.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery. The theme's functions.php require_once's this
 * file and references cdcf_rest_create_user() in its register_rest_route()
 * call.
 *
 * SECURITY: this is the only cdcf/v1 endpoint that provisions WordPress
 * users, so it sits deliberately *above* the editor baseline used by every
 * other ability. Two independent guards keep it from becoming a
 * privilege-escalation path:
 *
 *   1. The route's permission_callback gates on the custom capability
 *      `cdcf_create_limited_users` (granted only to the dedicated bot
 *      account — see cdcf_grant_limited_user_provisioning() in
 *      functions.php). Native `create_users` is intentionally NOT granted,
 *      so core's POST /wp/v2/users (which accepts ANY role) stays 403 for
 *      the bot.
 *   2. This handler hard-codes a role allowlist (author / contributor /
 *      subscriber) and rejects anything else — including editor and
 *      administrator — regardless of what capability the caller holds.
 *
 * No agent-supplied password is accepted: a strong password is generated
 * server-side and the standard "new user" set-password email is sent, so
 * the human controls the credential and it never transits the agent.
 */

if (defined('ABSPATH') === false) {
    return;
}

/**
 * Roles this endpoint is permitted to create. Deliberately excludes
 * editor, administrator and the bot role itself — none of these may ever
 * be provisioned via the agent. Defense in depth: enforced here in the
 * handler independently of the capability gate on the route.
 *
 * @return string[]
 */
function cdcf_create_user_allowed_roles(): array {
    return ['author', 'contributor', 'subscriber'];
}

/**
 * POST /cdcf/v1/create-user — create a low-privilege WordPress user with a
 * server-generated password and dispatch the standard set-password email.
 */
function cdcf_rest_create_user(WP_REST_Request $request) {
    $username     = $request['username'];
    $email        = $request['email'];
    $role         = $request['role'];
    $display_name = $request['display_name'];
    $first_name   = $request['first_name'];
    $last_name    = $request['last_name'];

    // ── Role allowlist (privilege-escalation guard) ──
    if (!in_array($role, cdcf_create_user_allowed_roles(), true)) {
        return new WP_Error(
            'invalid_role',
            'role must be one of: ' . implode(', ', cdcf_create_user_allowed_roles()),
            ['status' => 400]
        );
    }

    // ── Validation (sanitization already ran at REST dispatch, per #111) ──
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'A valid email address is required.', ['status' => 400]);
    }
    if ($username === '') {
        return new WP_Error('invalid_username', 'A non-empty username is required.', ['status' => 400]);
    }
    if (username_exists($username)) {
        return new WP_Error('username_exists', 'That username is already taken.', ['status' => 409]);
    }
    if (email_exists($email)) {
        return new WP_Error('email_exists', 'That email address is already registered.', ['status' => 409]);
    }

    // ── Create the user with a server-generated password ──
    // The agent never supplies or sees the password; the set-password email
    // (below) lets the human establish their own credential.
    $userdata = [
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password(24, true, true),
        'role'         => $role,
        'display_name' => $display_name !== '' ? $display_name : $username,
    ];
    if ($first_name !== '') {
        $userdata['first_name'] = $first_name;
    }
    if ($last_name !== '') {
        $userdata['last_name'] = $last_name;
    }

    $user_id = wp_insert_user($userdata);
    if (is_wp_error($user_id)) {
        return $user_id;
    }

    // Send the standard new-user notification to the user only ('user'),
    // never to the admin, so they receive a set-password link.
    if (function_exists('wp_new_user_notification')) {
        wp_new_user_notification($user_id, null, 'user');
    }

    return new WP_REST_Response([
        'success'  => true,
        'user_id'  => (int) $user_id,
        'username' => $username,
        'email'    => $email,
        'role'     => $role,
    ], 201);
}
