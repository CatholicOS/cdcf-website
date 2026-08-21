<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the capability guard on the `author_team_member` ACF user
 * field (includes/admin/author-profile-guard.php).
 *
 * Why this exists: the `group_author_profile` field group is registered
 * with `user_form == all`, which ACF renders on profile.php — every
 * logged-in user's OWN profile, subscribers included. ACF saves user
 * field groups straight from $_POST['acf'] with no capability check of
 * its own, and `author_team_member` is the sole ownership signal for
 * /cdcf/v1/my-team-member. Without these guards any subscriber could
 * link themselves to any published team_member and then PATCH that
 * person's bio in all six languages.
 *
 * Two guards, because either alone is a half-fix: hiding the field does
 * not stop a hand-crafted POST carrying acf[field_author_team_member][],
 * and blocking the write alone would leave a visible control that
 * silently does nothing.
 */
final class AuthorProfileGuardTest extends TestCase
{
    /** Shape ACF passes to acf/prepare_field for this field. */
    private const FIELD = [
        'key'   => 'field_author_team_member',
        'name'  => 'author_team_member',
        'type'  => 'relationship',
        'label' => 'Team Member Profile',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Stub current_user_can() so only the named capabilities answer true.
     *
     * @param string[] $caps
     */
    private function grantCaps(array $caps): void
    {
        Functions\when('current_user_can')->alias(
            static fn(string $cap): bool => in_array($cap, $caps, true)
        );
    }

    // ─── capability check ─────────────────────────────────────────

    public function test_editor_may_manage_the_link(): void
    {
        // edit_others_posts is the canonical "editor and above" primitive:
        // editors and administrators hold it; authors, contributors and
        // subscribers do not.
        $this->grantCaps(['edit_posts', 'edit_others_posts']);

        $this->assertTrue(cdcf_can_manage_author_team_member_link());
    }

    public function test_limited_user_provisioning_bot_may_manage_the_link(): void
    {
        // POST /cdcf/v1/author-team-member is gated on this capability, so
        // an opted-in non-admin bot must keep working — otherwise the guard
        // regresses the endpoint it is meant to leave alone.
        $this->grantCaps([CDCF_LIMITED_USER_CAP]);

        $this->assertTrue(cdcf_can_manage_author_team_member_link());
    }

    public function test_subscriber_may_not_manage_the_link(): void
    {
        $this->grantCaps(['read']);

        $this->assertFalse(cdcf_can_manage_author_team_member_link());
    }

    public function test_author_may_not_manage_the_link(): void
    {
        // Authors hold edit_posts and edit_published_posts but NOT
        // edit_others_posts — they must not reach other people's bios.
        $this->grantCaps(['read', 'edit_posts', 'edit_published_posts']);

        $this->assertFalse(cdcf_can_manage_author_team_member_link());
    }

    // ─── guard 1: the field is hidden from the form ───────────────

    public function test_field_is_hidden_from_an_unauthorized_viewer(): void
    {
        $this->grantCaps(['read']);

        $this->assertFalse(cdcf_guard_author_team_member_field(self::FIELD));
    }

    public function test_field_is_returned_unchanged_to_an_editor(): void
    {
        $this->grantCaps(['edit_others_posts']);

        $this->assertSame(self::FIELD, cdcf_guard_author_team_member_field(self::FIELD));
    }

    // ─── guard 2: the write is refused ────────────────────────────

    public function test_write_is_blocked_for_an_unauthorized_viewer(): void
    {
        // A non-null return short-circuits acf_update_value() before it
        // touches the database. This is the guard that actually closes the
        // hole: it defeats a hand-crafted POST to profile.php that carries
        // the field key even though the input was never rendered.
        $this->grantCaps(['read']);

        $result = cdcf_guard_author_team_member_update(null, [123], 'user_9', self::FIELD);

        $this->assertNotNull($result);
        $this->assertFalse($result);
    }

    public function test_write_proceeds_for_an_editor(): void
    {
        // Returning the incoming $check unchanged (null) lets ACF carry on
        // with its normal update path.
        $this->grantCaps(['edit_others_posts']);

        $this->assertNull(
            cdcf_guard_author_team_member_update(null, [123], 'user_9', self::FIELD)
        );
    }

    public function test_write_proceeds_for_the_limited_user_provisioning_bot(): void
    {
        $this->grantCaps([CDCF_LIMITED_USER_CAP]);

        $this->assertNull(
            cdcf_guard_author_team_member_update(null, [123], 'user_9', self::FIELD)
        );
    }

    public function test_writes_to_other_fields_are_untouched(): void
    {
        // ACF registers no `key` hook variation for acf/pre_update_value
        // (only acf/update_value gets one), so this guard must hook the
        // BARE filter and discriminate on $field['key'] itself. That means
        // it runs on every field write site-wide and must keep its hands
        // off everything but its own field.
        $this->grantCaps(['read']);

        $other = ['key' => 'field_hero_heading', 'name' => 'hero_heading'];

        $this->assertNull(
            cdcf_guard_author_team_member_update(null, 'anything', 42, $other)
        );
    }

    public function test_a_field_array_without_a_key_is_untouched(): void
    {
        $this->grantCaps(['read']);

        $this->assertNull(
            cdcf_guard_author_team_member_update(null, 'anything', 42, [])
        );
    }

    public function test_an_earlier_filter_short_circuit_is_preserved(): void
    {
        // If something upstream already short-circuited the update, the
        // guard must pass that decision through rather than resurrect the
        // write by returning null.
        $this->grantCaps(['edit_others_posts']);

        $this->assertSame(
            'upstream',
            cdcf_guard_author_team_member_update('upstream', [123], 'user_9', self::FIELD)
        );
    }
}
