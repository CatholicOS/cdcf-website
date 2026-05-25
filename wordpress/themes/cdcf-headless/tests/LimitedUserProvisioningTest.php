<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the cdcf_create_limited_users capability glue
 * (includes/admin/limited-user-provisioning.php):
 *   - the user_has_cap filter grants the cap only from the meta flag
 *   - the admin-only profile checkbox renders only for promote_users
 *   - the save handler is gated on promote_users AND a valid nonce
 */
final class LimitedUserProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        unset(
            $_POST[CDCF_LIMITED_USER_META_KEY],
            $_POST[CDCF_LIMITED_USER_NONCE . '_nonce']
        );
        parent::tearDown();
    }

    // ─── user_has_cap filter ──────────────────────────────────────

    public function test_grants_cap_when_meta_flag_set(): void
    {
        Functions\when('get_user_meta')->justReturn('1');

        $allcaps = cdcf_grant_limited_user_provisioning(['edit_posts' => true], [], [], new WP_User(7));

        $this->assertTrue($allcaps[CDCF_LIMITED_USER_CAP]);
        $this->assertTrue($allcaps['edit_posts']); // existing caps preserved
    }

    public function test_does_not_grant_cap_when_meta_flag_absent(): void
    {
        Functions\when('get_user_meta')->justReturn('');

        $allcaps = cdcf_grant_limited_user_provisioning(['edit_posts' => true], [], [], new WP_User(7));

        $this->assertArrayNotHasKey(CDCF_LIMITED_USER_CAP, $allcaps);
    }

    public function test_ignores_non_wp_user(): void
    {
        // No get_user_meta stub: a non-WP_User must short-circuit before
        // any meta lookup, so reaching it would fatal on the undefined fn.
        $allcaps = cdcf_grant_limited_user_provisioning(['edit_posts' => true], [], [], null);

        $this->assertArrayNotHasKey(CDCF_LIMITED_USER_CAP, $allcaps);
    }

    // ─── Profile checkbox render ──────────────────────────────────

    public function test_render_outputs_nothing_for_non_admin(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        cdcf_render_limited_user_provisioning_field(new WP_User(7));
        $html = ob_get_clean();

        $this->assertSame('', $html);
    }

    public function test_render_outputs_checkbox_for_admin(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_user_meta')->justReturn('1');
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('checked')->justReturn(" checked='checked'");

        ob_start();
        cdcf_render_limited_user_provisioning_field(new WP_User(7));
        $html = ob_get_clean();

        $this->assertStringContainsString('name="' . CDCF_LIMITED_USER_META_KEY . '"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    // ─── Save handler ─────────────────────────────────────────────

    public function test_save_noop_when_not_admin(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('update_user_meta')->never();
        Functions\expect('delete_user_meta')->never();

        cdcf_save_limited_user_provisioning_field(7);
        $this->assertTrue(true); // no meta write attempted
    }

    public function test_save_noop_when_nonce_invalid(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\expect('update_user_meta')->never();
        Functions\expect('delete_user_meta')->never();

        $_POST[CDCF_LIMITED_USER_NONCE . '_nonce'] = 'bad';
        $_POST[CDCF_LIMITED_USER_META_KEY]         = '1';

        cdcf_save_limited_user_provisioning_field(7);
        $this->assertTrue(true);
    }

    public function test_save_sets_meta_when_checkbox_ticked(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_verify_nonce')->justReturn(true);
        $written = null;
        Functions\when('update_user_meta')->alias(function ($id, $key, $val) use (&$written): bool {
            $written = [$id, $key, $val];
            return true;
        });

        $_POST[CDCF_LIMITED_USER_NONCE . '_nonce'] = 'ok';
        $_POST[CDCF_LIMITED_USER_META_KEY]         = '1';

        cdcf_save_limited_user_provisioning_field(7);

        $this->assertSame([7, CDCF_LIMITED_USER_META_KEY, 1], $written);
    }

    public function test_save_deletes_meta_when_checkbox_unticked(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_verify_nonce')->justReturn(true);
        $deleted = null;
        Functions\when('delete_user_meta')->alias(function ($id, $key) use (&$deleted): bool {
            $deleted = [$id, $key];
            return true;
        });

        $_POST[CDCF_LIMITED_USER_NONCE . '_nonce'] = 'ok';
        // checkbox absent from $_POST → flag cleared

        cdcf_save_limited_user_provisioning_field(7);

        $this->assertSame([7, CDCF_LIMITED_USER_META_KEY], $deleted);
    }
}
