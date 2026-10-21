<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/events.php — event log + notification fan-out.
 */
final class ReviewEventsTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('cdcf_review_member_user_ids')->justReturn([7, 8, 9]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_records_event_and_notifies_everyone_but_the_actor(): void
    {
        Actions\expectDone('cdcf_review_event_recorded')->once()->with(1, 40, 'reply_posted', 8, 5, []);

        $id = cdcf_review_record_event(40, 'reply_posted', 8, 5);

        $this->assertSame(1, $id);
        $event = $this->wpdb->inserts[0];
        $this->assertSame('wp_cdcf_review_events', $event['table']);
        $this->assertSame(40, $event['data']['project_id']);
        $this->assertSame('reply_posted', $event['data']['type']);
        $this->assertSame(8, $event['data']['actor_user_id']);
        $this->assertSame(5, $event['data']['entry_id']);
        $this->assertNull($event['data']['data']);

        $recipients = array_map(
            static fn(array $i) => $i['data']['user_id'],
            array_slice($this->wpdb->inserts, 1)
        );
        $this->assertSame([7, 9], $recipients);
    }

    public function test_submitter_events_notify_all_members_and_encode_data(): void
    {
        cdcf_review_record_event(40, 'vote_changed', null, null, ['from' => 'reject', 'to' => 'cdcf']);

        $this->assertSame('{"from":"reject","to":"cdcf"}', $this->wpdb->inserts[0]['data']['data']);
        $this->assertCount(4, $this->wpdb->inserts);
    }

    public function test_failed_event_insert_returns_zero_and_notifies_nobody(): void
    {
        $this->wpdb->failInsert = true;
        Actions\expectDone('cdcf_review_event_recorded')->never();

        $this->assertSame(0, cdcf_review_record_event(40, 'review_posted', 7, 3));
    }

    /** @param array<int, string> $meta user id => stored cdcf_review_notified_gen */
    private function stubCatchUp(array $meta, bool $member = true, array &$updates = []): void
    {
        Functions\when('get_option')->alias(static fn(string $k, $d = false) => $k === 'cdcf_review_membership_gen' ? 5 : $d);
        Functions\when('get_user_meta')->alias(static fn(int $uid, string $k) => $meta[$uid] ?? '');
        Functions\when('cdcf_review_can')->justReturn($member);
        Functions\when('update_user_meta')->alias(static function (int $uid, string $k, string $v) use (&$updates): bool {
            $updates[] = [$uid, $k, $v];
            return true;
        });
    }

    public function test_catch_up_backfills_open_project_events_for_a_new_member(): void
    {
        $updates = [];
        $this->stubCatchUp([], true, $updates);

        cdcf_review_catch_up_notifications(12);

        $this->assertCount(1, $this->wpdb->queries);
        $sql = $this->wpdb->queries[0];
        // INSERT IGNORE: rows the member already has, and their read state, are kept.
        $this->assertStringStartsWith('INSERT IGNORE INTO wp_cdcf_review_notifications (event_id, user_id) SELECT e.id, 12 FROM wp_cdcf_review_events e', $sql);
        // Never notified of their own actions.
        $this->assertStringContainsString('(e.actor_user_id IS NULL OR e.actor_user_id <> 12)', $sql);
        // Only projects still under review; decided ones are left out.
        $this->assertStringContainsString(
            "SELECT post_id FROM wp_postmeta WHERE meta_key = '_cdcf_review_stage' AND meta_value IN ('in_review', 'awaiting_submitter')",
            $sql
        );
        $this->assertSame([[12, 'cdcf_review_notified_gen', '5']], $updates);
        $this->assertSame([], $this->wpdb->inserts, 'no event is recorded, so no email goes out');
    }

    public function test_catch_up_runs_once_per_membership_generation(): void
    {
        $updates = [];
        $this->stubCatchUp([12 => '5'], true, $updates);

        cdcf_review_catch_up_notifications(12);

        $this->assertSame([], $this->wpdb->queries);
        $this->assertSame([], $updates);
    }

    public function test_catch_up_reruns_after_a_roster_change(): void
    {
        $updates = [];
        $this->stubCatchUp([12 => '4'], true, $updates);

        cdcf_review_catch_up_notifications(12);

        $this->assertCount(1, $this->wpdb->queries);
        $this->assertSame([[12, 'cdcf_review_notified_gen', '5']], $updates);
    }

    public function test_catch_up_skips_non_members_and_anonymous_users(): void
    {
        $updates = [];
        $this->stubCatchUp([], false, $updates);

        cdcf_review_catch_up_notifications(12);
        cdcf_review_catch_up_notifications(0);

        $this->assertSame([], $this->wpdb->queries);
        $this->assertSame([], $updates);
    }

    public function test_failed_catch_up_is_retried_on_the_next_request(): void
    {
        $updates = [];
        $this->stubCatchUp([], true, $updates);
        $this->wpdb->results[] = false;

        cdcf_review_catch_up_notifications(12);

        $this->assertCount(1, $this->wpdb->queries);
        $this->assertSame([], $updates);
    }

    public function test_unread_counts_per_project(): void
    {
        $this->wpdb->results[] = [['project_id' => '40', 'n' => '3']];

        $this->assertSame([40 => 3], cdcf_review_unread_counts(7, [40, 41]));
        $sql = $this->wpdb->queries[0];
        $this->assertStringContainsString('n.user_id = 7', $sql);
        $this->assertStringContainsString('n.read_at IS NULL', $sql);
        $this->assertStringContainsString('e.project_id IN (40,41)', $sql);
    }

    public function test_unread_counts_of_no_projects_skip_the_query(): void
    {
        $this->assertSame([], cdcf_review_unread_counts(7, []));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_get_event_decodes_its_data(): void
    {
        $this->wpdb->results[] = ['id' => '3', 'project_id' => '40', 'type' => 'admin_override', 'actor_user_id' => null, 'entry_id' => null, 'data' => '{"backfill":true}', 'created_at' => 'x'];

        $this->assertSame(
            ['id' => 3, 'project_id' => 40, 'type' => 'admin_override', 'actor_user_id' => null, 'entry_id' => null, 'data' => ['backfill' => true], 'created_at' => 'x'],
            cdcf_review_get_event(3)
        );
        $this->assertNull(cdcf_review_get_event(4));
    }
}
