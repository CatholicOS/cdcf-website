<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/membership.php.
 *
 * Fixture: English About page 5 lists team_member 700 on the technical
 * council, 701 on the ecclesial council and 702 on the Board. User 7 is
 * linked to 710, the Italian sibling of 700. User 8 is linked to 702.
 * User 9 is linked to 799, which is on no council.
 */
final class ReviewMembershipTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        Functions\when('get_option')->alias(fn(string $k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('cdcf_my_team_member_get_english_about_page_id')->justReturn(5);
        Functions\when('get_field')->alias(static function (string $field, $post_id) {
            return match ($field) {
                'technical_council' => ['700'],
                'ecclesial_council' => ['701'],
                'team_members'      => ['702'],
                default             => null,
            };
        });
        Functions\when('cdcf_my_team_member_resolve_link')->alias(
            static fn(int $uid): int => [7 => 710, 8 => 702, 9 => 799][$uid] ?? 0
        );
        Functions\when('cdcf_my_team_member_collect_group')->alias(
            static fn(int $tm): array => match ($tm) {
                710, 700 => ['en' => 700, 'it' => 710],
                702      => ['en' => 702],
                default  => ['en' => $tm],
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_council_ids_read_from_english_about_page(): void
    {
        $this->assertSame(
            ['technical' => [700], 'ecclesial' => [701], 'board' => [702]],
            cdcf_review_council_ids()
        );
    }

    public function test_council_ids_empty_without_about_page(): void
    {
        Functions\when('cdcf_my_team_member_get_english_about_page_id')->justReturn(0);

        $this->assertSame(
            ['technical' => [], 'ecclesial' => [], 'board' => []],
            cdcf_review_council_ids()
        );
    }

    public function test_link_to_translated_bio_counts_for_its_council(): void
    {
        $this->assertSame(
            ['technical' => true, 'ecclesial' => false, 'board' => false],
            cdcf_review_membership(7)
        );
    }

    public function test_unlinked_user_and_non_member_get_no_flags(): void
    {
        $none = ['technical' => false, 'ecclesial' => false, 'board' => false];
        $this->assertSame($none, cdcf_review_membership(42));
        $this->assertSame($none, cdcf_review_membership(9));
        $this->assertSame($none, cdcf_review_membership(0));
    }

    public function test_cached_membership_skips_lookup(): void
    {
        Functions\when('get_transient')->justReturn(['technical' => false, 'ecclesial' => true, 'board' => false]);
        Functions\expect('cdcf_my_team_member_resolve_link')->never();

        $this->assertTrue(cdcf_review_membership(7)['ecclesial']);
    }

    public function test_cache_key_includes_generation(): void
    {
        $keys = [];
        Functions\when('set_transient')->alias(function (string $k) use (&$keys) {
            $keys[] = $k;
            return true;
        });

        cdcf_review_membership(7);
        cdcf_review_bump_membership_generation();
        cdcf_review_membership(7);

        $this->assertSame(['cdcf_review_membership_0_7', 'cdcf_review_membership_1_7'], $keys);
    }

    public function test_permission_matrix(): void
    {
        // 7 technical, 8 board, 9 nobody.
        $this->assertTrue(cdcf_review_can(7, 'view'));
        $this->assertTrue(cdcf_review_can(7, 'discuss'));
        $this->assertTrue(cdcf_review_can(7, 'vote'));
        $this->assertFalse(cdcf_review_can(7, 'decide'));

        $this->assertTrue(cdcf_review_can(8, 'view'));
        $this->assertFalse(cdcf_review_can(8, 'vote'));
        $this->assertTrue(cdcf_review_can(8, 'decide'));

        $this->assertFalse(cdcf_review_can(9, 'view'));
        $this->assertFalse(cdcf_review_can(7, 'unknown'));
    }

    public function test_member_user_ids_filters_by_permission(): void
    {
        Functions\when('get_users')->justReturn(['7', '8', '9']);

        $this->assertSame([7, 8], cdcf_review_member_user_ids());
        $this->assertSame([7], cdcf_review_member_user_ids('vote'));
        $this->assertSame([8], cdcf_review_member_user_ids('decide'));
    }

    public function test_saving_the_about_page_bumps_generation(): void
    {
        Functions\when('get_page_template_slug')->justReturn('templates/about.php');

        cdcf_review_flush_on_about_save(5);

        $this->assertSame(1, $this->options['cdcf_review_membership_gen']);
    }

    public function test_saving_other_pages_or_acf_non_posts_does_not_bump(): void
    {
        Functions\when('get_page_template_slug')->justReturn('templates/home.php');

        cdcf_review_flush_on_about_save(6);
        cdcf_review_flush_on_about_save('user_7');
        cdcf_review_flush_on_about_save('options');

        $this->assertArrayNotHasKey('cdcf_review_membership_gen', $this->options);
    }

    public function test_author_team_member_meta_write_bumps_generation(): void
    {
        cdcf_review_flush_on_user_meta(1, 7, 'author_team_member');
        cdcf_review_flush_on_user_meta(2, 7, 'nickname');

        $this->assertSame(1, $this->options['cdcf_review_membership_gen']);
    }

    public function test_roster_marks_linked_and_unlinked_members(): void
    {
        Functions\when('get_users')->justReturn(['7', '8']);
        Functions\when('get_the_title')->alias(static fn(int $id): string => "Member {$id}");

        $this->assertSame(
            [
                ['council' => 'technical', 'team_member_id' => 700, 'name' => 'Member 700', 'user_id' => 7],
                ['council' => 'ecclesial', 'team_member_id' => 701, 'name' => 'Member 701', 'user_id' => 0],
                ['council' => 'board', 'team_member_id' => 702, 'name' => 'Member 702', 'user_id' => 8],
            ],
            cdcf_review_roster()
        );
    }

    public function test_unpublished_council_bios_are_ignored(): void
    {
        Functions\when('get_post_status')->alias(static fn(int $id): string => $id === 700 ? 'trash' : 'publish');

        $this->assertSame([], cdcf_review_council_ids()['technical']);
        $this->assertFalse(cdcf_review_membership(7)['technical']);
    }

    public function test_council_field_meta_write_on_about_page_bumps_generation(): void
    {
        Functions\when('get_page_template_slug')->alias(static fn(int $id): string => $id === 5 ? 'templates/about.php' : '');

        cdcf_review_flush_on_post_meta(1, 5, 'technical_council');
        cdcf_review_flush_on_post_meta(2, 5, 'hero_title');
        cdcf_review_flush_on_post_meta(3, 6, 'team_members');

        $this->assertSame(1, $this->options['cdcf_review_membership_gen']);
    }

    public function test_team_member_status_change_bumps_generation(): void
    {
        $bio = new stdClass();
        $bio->post_type = 'team_member';
        $page = new stdClass();
        $page->ID = 6;
        $page->post_type = 'page';
        Functions\when('get_page_template_slug')->justReturn('templates/home.php');

        cdcf_review_flush_on_status_change('trash', 'publish', $bio);
        cdcf_review_flush_on_status_change('publish', 'publish', $bio);
        cdcf_review_flush_on_status_change('trash', 'publish', $page);

        $this->assertSame(1, $this->options['cdcf_review_membership_gen']);
    }

    public function test_about_page_status_change_bumps_generation(): void
    {
        Functions\when('get_page_template_slug')->alias(static fn(int $id): string => $id === 5 ? 'templates/about.php' : 'templates/home.php');
        $about = new stdClass();
        $about->ID = 5;
        $about->post_type = 'page';
        $home = new stdClass();
        $home->ID = 6;
        $home->post_type = 'page';

        cdcf_review_flush_on_status_change('trash', 'publish', $about);
        cdcf_review_flush_on_status_change('draft', 'publish', $home);

        $this->assertSame(1, $this->options['cdcf_review_membership_gen']);
    }
}
