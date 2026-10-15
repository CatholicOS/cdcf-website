<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/admin/review-access.php.
 */
final class ReviewAccessScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('cdcf_review_storage_dir')->justReturn(['path' => '/srv/x', 'fallback' => false]);
        Functions\when('get_edit_user_link')->alias(static fn(int $id): string => "user-edit.php?user_id={$id}");
        Functions\when('get_userdata')->alias(static function (int $id) {
            $u = new WP_User($id);
            $u->display_name = 'Fr. A';
            $u->user_email = 'a@example.org';
            return $u;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_menu_is_registered_under_projects_for_admins(): void
    {
        Functions\expect('add_submenu_page')->once()->with(
            'cdcf-projects', 'Review access', 'Review access', 'manage_options',
            'cdcf-review-access', 'cdcf_review_access_render'
        );

        cdcf_review_access_admin_menu();

        $this->addToAssertionCount(1);
    }

    public function test_render_lists_linked_and_unlinked_members(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('cdcf_review_roster')->justReturn([
            ['council' => 'technical', 'team_member_id' => 700, 'name' => 'Dr. B', 'user_id' => 0],
            ['council' => 'board', 'team_member_id' => 702, 'name' => 'Fr. A', 'user_id' => 8],
        ]);

        ob_start();
        cdcf_review_access_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Technical Council', $html);
        $this->assertStringContainsString('Dr. B', $html);
        $this->assertStringContainsString('Not linked', $html);
        $this->assertStringContainsString('Board of Directors', $html);
        $this->assertStringContainsString('user-edit.php?user_id=8', $html);
        $this->assertStringContainsString('a@example.org', $html);
    }

    public function test_render_explains_an_empty_roster(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('cdcf_review_roster')->justReturn([]);

        ob_start();
        cdcf_review_access_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('No council members are listed on the English About page.', $html);
    }

    public function test_render_outputs_nothing_for_non_admins(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('cdcf_review_roster')->never();

        ob_start();
        cdcf_review_access_render();

        $this->assertSame('', ob_get_clean());
    }

    public function test_render_warns_while_storage_fallback_is_used(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('cdcf_review_roster')->justReturn([]);
        Functions\when('cdcf_review_storage_dir')->justReturn(['path' => '/var/www/wp-content/uploads/cdcf-review', 'fallback' => true]);

        ob_start();
        cdcf_review_access_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('CDCF_REVIEW_STORAGE_DIR', $html);
        $this->assertStringContainsString('notice-warning', $html);
    }
}
