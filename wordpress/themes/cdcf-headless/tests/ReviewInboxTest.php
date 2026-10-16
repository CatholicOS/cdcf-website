<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/inbox.php — reading and marking notifications.
 */
final class ReviewInboxTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        Functions\when('get_post_field')->alias(static fn(string $f, int $id) => $id === 40 ? 'Liturgy Helper' : '');
        Functions\when('get_userdata')->alias(static function (int $uid) {
            if ($uid !== 8) {
                return false;
            }
            $u = new WP_User($uid);
            $u->display_name = 'Fr. A';
            return $u;
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function row(array $over = []): array
    {
        return $over + [
            'id' => '12', 'project_id' => '40', 'type' => 'discussion_posted', 'actor_user_id' => '8',
            'entry_id' => '5', 'data' => null, 'created_at' => '2026-10-16 09:00:00.000000', 'read_at' => null,
        ];
    }

    public function test_page_is_newest_first_for_the_user_and_reports_more(): void
    {
        $rows = array_map(fn(int $i) => $this->row(['id' => (string) (100 - $i)]), range(0, 20)); // 21 rows
        $this->wpdb->results[] = $rows;

        $out = cdcf_review_inbox(7, false, 2);

        $this->assertCount(20, $out['items']);
        $this->assertTrue($out['has_more']);
        $sql = $this->wpdb->queries[0];
        $this->assertStringContainsString('n.user_id = 7', $sql);
        $this->assertStringContainsString('ORDER BY e.id DESC LIMIT 21 OFFSET 20', $sql);
        $this->assertStringNotContainsString('read_at IS NULL', $sql);
    }

    public function test_unread_filter_and_last_page(): void
    {
        $this->wpdb->results[] = [$this->row()];

        $out = cdcf_review_inbox(7, true, 1);

        $this->assertFalse($out['has_more']);
        $this->assertStringContainsString('n.read_at IS NULL', $this->wpdb->queries[0]);
    }

    public function test_presents_a_notification(): void
    {
        $item = cdcf_review_present_notification($this->row(['data' => '{"from":"reject","to":"cdcf"}', 'type' => 'vote_changed']), 7);

        $this->assertSame([
            'event_id'   => 12,
            'type'       => 'vote_changed',
            'project'    => ['id' => 40, 'title' => 'Liturgy Helper'],
            'actor'      => ['id' => 8, 'name' => 'Fr. A'],
            'entry_id'   => 5,
            'data'       => ['from' => 'reject', 'to' => 'cdcf'],
            'created_at' => '2026-10-16 09:00:00.000000',
            'read'       => false,
            'to_you'     => false,
        ], $item);
    }

    public function test_submitter_events_have_no_actor_and_former_members_are_named_as_such(): void
    {
        $this->assertNull(cdcf_review_present_notification($this->row(['actor_user_id' => null, 'type' => 'submitter_answered']), 7)['actor']);
        $this->assertSame(['id' => 99, 'name' => 'Former member'], cdcf_review_present_notification($this->row(['actor_user_id' => '99']), 7)['actor']);
        $this->assertTrue(cdcf_review_present_notification($this->row(['read_at' => '2026-10-16 10:00:00']), 7)['read']);
    }

    public function test_a_reply_to_the_callers_review_is_marked_to_you(): void
    {
        // get_row: the reply (parent 3), then its parent review (author 7).
        $this->wpdb->results[] = ['id' => '5', 'kind' => 'reply', 'parent_id' => '3', 'author_user_id' => '8'];
        $this->wpdb->results[] = ['id' => '3', 'kind' => 'review', 'parent_id' => null, 'author_user_id' => '7'];

        $this->assertTrue(cdcf_review_present_notification($this->row(['type' => 'reply_posted']), 7)['to_you']);
    }

    public function test_unread_total(): void
    {
        $this->wpdb->results[] = '4';

        $this->assertSame(4, cdcf_review_unread_total(7));
        $this->assertStringContainsString('user_id = 7 AND read_at IS NULL', $this->wpdb->queries[0]);
    }

    public function test_mark_read_is_always_scoped_to_the_caller(): void
    {
        cdcf_review_mark_read(7, [12, 0, -3, 13]);
        cdcf_review_mark_read(7);
        cdcf_review_mark_read(7, null, 40);

        [$some, $all, $project] = $this->wpdb->queries;
        foreach ([$some, $all, $project] as $sql) {
            $this->assertStringContainsString('WHERE user_id = 7 AND read_at IS NULL', $sql);
        }
        $this->assertStringContainsString('AND event_id IN (12,13)', $some);
        $this->assertStringNotContainsString('event_id IN', $all);
        $this->assertStringContainsString('WHERE project_id = 40', $project);
    }

    public function test_mark_read_with_no_valid_ids_does_nothing(): void
    {
        cdcf_review_mark_read(7, [0, -1]);

        $this->assertSame([], $this->wpdb->queries);
    }
}
