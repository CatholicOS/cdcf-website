<?php

/**
 * Grants and an admin-only toggle for the custom `cdcf_create_limited_users`
 * capability that gates POST /cdcf/v1/create-user.
 *
 * Why a custom capability instead of native `create_users`: granting
 * `create_users` to the bot would also unlock core's POST /wp/v2/users,
 * which accepts ANY role (including administrator) and is a route we don't
 * control. The custom cap is honoured *only* by our own endpoint, whose
 * handler enforces a role allowlist — so the bot can never mint a
 * privileged account. See includes/handlers/create-user.php.
 *
 * The cap is granted per-user via the `cdcf_can_create_users` user-meta
 * flag, set by an administrator on the dedicated bot account through the
 * checkbox added to the user-edit screen below. It is intentionally NOT
 * tied to a role, so it never leaks to every editor; and the toggle is
 * shown/saved only on the *edit-other-user* screen for administrators
 * (`promote_users`), never on a user's own profile — so an account can
 * never grant the capability to itself.
 *
 * Functions are pure; functions.php registers the hooks (mirrors the
 * includes/admin/ai-translate.php convention).
 */

defined('ABSPATH') || exit;

const CDCF_LIMITED_USER_CAP       = 'cdcf_create_limited_users';
const CDCF_LIMITED_USER_META_KEY  = 'cdcf_can_create_users';
const CDCF_LIMITED_USER_NONCE     = 'cdcf_limited_user_provisioning';

/**
 * Dynamically grant the custom capability to any user carrying the
 * `cdcf_can_create_users` meta flag. Filter for `user_has_cap`.
 *
 * @param array<string,bool> $allcaps All capabilities the user currently has.
 * @param string[]           $caps    Required primitive caps (unused).
 * @param array<int,mixed>   $args    [requested_cap, user_id, ...] (unused).
 * @param WP_User            $user    The user being checked.
 * @return array<string,bool>
 */
function cdcf_grant_limited_user_provisioning(array $allcaps, array $caps, array $args, $user): array {
    if ($user instanceof WP_User
        && get_user_meta($user->ID, CDCF_LIMITED_USER_META_KEY, true)
    ) {
        $allcaps[CDCF_LIMITED_USER_CAP] = true;
    }
    return $allcaps;
}

/**
 * Render the admin-only checkbox on the edit-other-user screen. Hooked to
 * `edit_user_profile` (never `show_user_profile`, so it is absent from a
 * user's own profile) and shown only to administrators.
 */
function cdcf_render_limited_user_provisioning_field($user): void {
    if (!current_user_can('promote_users')) {
        return;
    }
    $enabled = (bool) get_user_meta($user->ID, CDCF_LIMITED_USER_META_KEY, true);
    wp_nonce_field(CDCF_LIMITED_USER_NONCE, CDCF_LIMITED_USER_NONCE . '_nonce');
    ?>
    <h2>CDCF Automation</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">Limited user provisioning</th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(CDCF_LIMITED_USER_META_KEY); ?>" value="1" <?php checked($enabled); ?> />
                    Allow this account to create author / contributor / subscriber users via the MCP <code>cdcf/create-user</code> ability.
                </label>
                <p class="description">
                    Grant only to a dedicated automation (bot) account. This does not grant
                    <code>create_users</code>; the account still cannot create editors or administrators.
                </p>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Persist the checkbox. Hooked to `edit_user_profile_update`; only an
 * administrator (`promote_users`) may change the flag, and only with a
 * valid nonce.
 */
function cdcf_save_limited_user_provisioning_field(int $user_id): void {
    if (!current_user_can('promote_users')) {
        return;
    }
    $nonce = $_POST[CDCF_LIMITED_USER_NONCE . '_nonce'] ?? '';
    if (!wp_verify_nonce($nonce, CDCF_LIMITED_USER_NONCE)) {
        return;
    }
    if (!empty($_POST[CDCF_LIMITED_USER_META_KEY])) {
        update_user_meta($user_id, CDCF_LIMITED_USER_META_KEY, 1);
    } else {
        delete_user_meta($user_id, CDCF_LIMITED_USER_META_KEY);
    }
}
