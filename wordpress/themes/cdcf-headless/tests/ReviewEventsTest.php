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
