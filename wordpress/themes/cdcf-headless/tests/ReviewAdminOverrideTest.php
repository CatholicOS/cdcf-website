<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewAdminOverrideTest extends TestCase
{
    private array $meta = [];
    private array $events = [];
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];
        $this->events = $this->emails = [];

        Functions\when('get_post_meta')->alias(fn(int $id, string $k, bool $s = false) => $this->meta[$id][$k] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v): bool {
            $this->meta[$id][$k] = $v;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $k): bool {
            unset($this->meta[$id][$k]);
            return true;
        });
        // Only the first three arguments (project, type, actor) are recorded.
        Functions\when('cdcf_review_record_event')->alias(function ($project, $type, $actor, ...$rest): int {
            $this->events[] = [$project, $type, $actor];
            return 1;
        });
        Functions\when('cdcf_review_send_submitter_email')->alias(function (int $id, string $t): string {
            $this->emails[] = [$id, $t];
            return 'queued';
        });
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html')->returnArg();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['post']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_publishing_an_undecided_review_records_an_override(): void
    {
        $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'in_review'];
        Functions\when('get_current_user_id')->justReturn(1);
        cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 40, 'post_type' => 'project']);
        $this->assertSame('decided', $this->meta[40][CDCF_REVIEW_STAGE_META]);
        $this->assertSame('cdcf', $this->meta[40]['_cdcf_review_decision']);
        $this->assertSame('1', $this->meta[40]['_cdcf_review_admin_override']);
        $this->assertSame([[40, 'admin_override', 1]], $this->events);
        $this->assertSame([], $this->emails);
    }

    public function test_the_decision_endpoints_own_publish_is_not_an_override(): void
    {
        $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'in_review'];
        cdcf_review_deciding(true);
        cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 40, 'post_type' => 'project']);
        cdcf_review_deciding(false);
        $this->assertSame('in_review', $this->meta[40][CDCF_REVIEW_STAGE_META]);
        $this->assertSame([], $this->events);
    }

    public function test_other_transitions_and_posts_are_ignored(): void
    {
        $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'decided'];
        $this->meta[41] = [];
        $this->meta[42] = [CDCF_REVIEW_STAGE_META => 'in_review'];
        cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 40, 'post_type' => 'project']);
        cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 41, 'post_type' => 'project']);
        cdcf_review_admin_override_on_publish('publish', 'publish', (object) ['ID' => 42, 'post_type' => 'project']);
        cdcf_review_admin_override_on_publish('draft', 'pending', (object) ['ID' => 42, 'post_type' => 'project']);
        $this->assertSame([], $this->events);
    }

    public function test_the_edit_screen_shows_a_notice_while_under_review(): void
    {
        $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'awaiting_submitter'];
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'post']);
        $GLOBALS['post'] = (object) ['ID' => 40, 'post_type' => 'project'];
        ob_start();
        cdcf_review_edit_screen_notice();
        $html = ob_get_clean();
        $this->assertStringContainsString('Under council review', $html);
        $this->assertStringContainsString('/review/40', $html);
    }
}
