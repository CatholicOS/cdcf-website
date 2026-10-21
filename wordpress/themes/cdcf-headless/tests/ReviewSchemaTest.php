<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/schema.php — table SQL, the versioned
 * upgrade routine and the one-time stage backfill.
 */
final class ReviewSchemaTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_table_names_use_wpdb_prefix(): void
    {
        $this->assertSame('wp_cdcf_review_entries', cdcf_review_table('entries'));
    }

    public function test_schema_sql_defines_four_tables_in_order(): void
    {
        $sql = cdcf_review_schema_sql('DEFAULT CHARACTER SET utf8mb4');

        $this->assertCount(4, $sql);
        foreach (['entries', 'attachments', 'events', 'notifications'] as $i => $table) {
            $this->assertStringContainsString("CREATE TABLE wp_cdcf_review_{$table} (", $sql[$i]);
            $this->assertStringEndsWith('DEFAULT CHARACTER SET utf8mb4;', $sql[$i]);
        }
    }

    public function test_entries_table_enforces_one_review_per_reviewer(): void
    {
        $sql = cdcf_review_schema_sql('')[0];

        $this->assertStringContainsString('review_slot bigint(20) unsigned DEFAULT NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY one_review (project_id,review_slot)', $sql);
        $this->assertStringContainsString('created_at datetime(6) NOT NULL', $sql);
    }

    public function test_notifications_primary_key_is_event_and_user(): void
    {
        $sql = cdcf_review_schema_sql('')[3];

        $this->assertStringContainsString('PRIMARY KEY  (event_id,user_id)', $sql);
        $this->assertStringContainsString('KEY user_unread (user_id,read_at)', $sql);
        $this->assertStringContainsString('KEY user_event (user_id,event_id)', $sql);
    }

    public function test_maybe_upgrade_is_a_noop_when_version_is_current(): void
    {
        Functions\when('get_option')->justReturn(CDCF_REVIEW_DB_VERSION);
        Functions\expect('dbDelta')->never();
        Functions\expect('update_option')->never();

        cdcf_review_maybe_upgrade();

        $this->addToAssertionCount(1);
    }

    public function test_maybe_upgrade_runs_dbdelta_backfills_and_stores_version(): void
    {
        Functions\when('get_option')->justReturn(false);
        $delta = [];
        Functions\when('dbDelta')->alias(function (string $sql) use (&$delta): array {
            $delta[] = $sql;
            return [];
        });
        Functions\when('get_posts')->alias(fn(array $a): array => $a['post_status'] === 'pending' ? [11, 12] : []);
        $meta = [];
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use (&$meta): bool {
            $meta[] = [$id, $key, $value];
            return true;
        });
        Functions\expect('update_option')->once()->with('cdcf_review_db_version', CDCF_REVIEW_DB_VERSION);
        Functions\expect('cdcf_review_backfill_submission_received')->once()->andReturn(0);
        Functions\expect('cdcf_review_backfill_referrals')->once()->andReturn(0);
        foreach (['entries', 'attachments', 'events', 'notifications'] as $t) {
            $this->wpdb->results[] = "wp_cdcf_review_{$t}";
        }

        cdcf_review_maybe_upgrade();

        $this->assertCount(4, $delta);
        $this->assertSame(
            [[11, '_cdcf_review_stage', 'in_review'], [12, '_cdcf_review_stage', 'in_review']],
            $meta
        );
    }

    public function test_maybe_upgrade_keeps_old_version_when_a_table_is_missing(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\when('dbDelta')->justReturn([]);
        Functions\expect('get_posts')->never();
        Functions\expect('update_option')->never();
        // dbDelta failed silently on the events table.
        $this->wpdb->results = ['wp_cdcf_review_entries', 'wp_cdcf_review_attachments', null, 'wp_cdcf_review_notifications'];

        cdcf_review_maybe_upgrade();

        $this->assertStringContainsString('SHOW TABLES LIKE', end($this->wpdb->queries));
    }

    public function test_backfill_targets_pending_public_submissions_without_a_stage(): void
    {
        $args = null;
        Functions\when('get_posts')->alias(function (array $a) use (&$args): array {
            $args = $a;
            return [];
        });

        $this->assertSame(0, cdcf_review_backfill_stage());
        $this->assertSame('project', $args['post_type']);
        $this->assertSame('pending', $args['post_status']);
        $this->assertSame('ids', $args['fields']);
        $this->assertSame('', $args['lang']);
        $this->assertSame(
            [
                ['key' => '_submission_submitter_email', 'compare' => 'EXISTS'],
                ['key' => '_cdcf_review_stage', 'compare' => 'NOT EXISTS'],
            ],
            $args['meta_query']
        );
    }

    public function test_db_version_is_bumped_for_referrals(): void
    {
        $this->assertSame('4', CDCF_REVIEW_DB_VERSION);
    }

    public function test_maybe_upgrade_from_version_1_backfills_published_decisions(): void
    {
        Functions\when('get_option')->justReturn('1');
        Functions\when('dbDelta')->justReturn([]);
        Functions\when('update_post_meta')->justReturn(true);
        $queried = [];
        Functions\when('get_posts')->alias(function (array $a) use (&$queried): array {
            $queried[] = $a['post_status'];
            return [];
        });
        Functions\expect('update_option')->once()->with('cdcf_review_db_version', CDCF_REVIEW_DB_VERSION);
        Functions\expect('cdcf_review_backfill_submission_received')->once()->andReturn(0);
        Functions\expect('cdcf_review_backfill_referrals')->once()->andReturn(0);
        foreach (['entries', 'attachments', 'events', 'notifications'] as $t) {
            $this->wpdb->results[] = "wp_cdcf_review_{$t}";
        }

        cdcf_review_maybe_upgrade();

        $this->assertSame(['pending', 'publish'], $queried);
    }

    /**
     * In-memory posts: 1 published project under review, 2 published
     * community project awaiting the submitter, 3 pending under review,
     * 4 published and already decided, 5 published with no review stage.
     */
    public function test_published_backfill_decides_only_live_undecided_submissions(): void
    {
        $posts = [
            1 => ['project', 'publish', 'in_review'],
            2 => ['community_project', 'publish', 'awaiting_submitter'],
            3 => ['project', 'pending', 'in_review'],
            4 => ['project', 'publish', 'decided'],
            5 => ['project', 'publish', ''],
        ];
        $args = null;
        Functions\when('get_posts')->alias(function (array $a) use (&$args, $posts): array {
            $args = $a;
            $stages = $a['meta_query'][0]['value'];
            return array_keys(array_filter(
                $posts,
                static fn(array $p) => in_array($p[0], $a['post_type'], true) && $p[1] === $a['post_status'] && in_array($p[2], $stages, true)
            ));
        });
        Functions\when('get_post_type')->alias(static fn(int $id) => $posts[$id][0]);
        $decided = [];
        Functions\when('cdcf_review_record_decision')->alias(function (...$a) use (&$decided): void {
            $decided[] = $a;
        });
        $events = [];
        Functions\when('cdcf_review_record_event')->alias(function (...$a) use (&$events): int {
            $events[] = $a;
            return 1;
        });
        Functions\expect('cdcf_review_send_submitter_email')->never();

        $this->assertSame(2, cdcf_review_backfill_published_decisions());

        $this->assertSame(['project', 'community_project'], $args['post_type']);
        $this->assertSame('publish', $args['post_status']);
        $this->assertSame('ids', $args['fields']);
        $this->assertSame('', $args['lang']);
        $this->assertSame(-1, $args['posts_per_page']);
        $this->assertSame(
            [['key' => '_cdcf_review_stage', 'value' => ['in_review', 'awaiting_submitter'], 'compare' => 'IN']],
            $args['meta_query']
        );
        $this->assertSame(
            [[1, 'cdcf', null, '', '', true], [2, 'community', null, '', '', true]],
            $decided
        );
        $this->assertSame(
            [
                [1, 'admin_override', null, null, ['decision' => 'cdcf', 'backfill' => true]],
                [2, 'admin_override', null, null, ['decision' => 'community', 'backfill' => true]],
            ],
            $events
        );
    }

    public function test_now_is_utc_with_microseconds(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/',
            cdcf_review_now()
        );
    }

    // ─── Version 3: submissions that entered review before Phase 1 ─────

    public function test_received_backfill_notifies_members_and_sends_first_links(): void
    {
        $events = $emails = $args = null;
        Functions\when('get_posts')->alias(function (array $a) use (&$args): array {
            $args = $a;
            return [11, 12, 13];
        });
        // 13 already had a status email (a committee question), so it has a link.
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => ($key === CDCF_REVIEW_TOKEN_HASH_META && $id === 13) ? str_repeat('a', 64) : ''
        );
        $events = [];
        Functions\when('cdcf_review_record_event')->alias(function (...$a) use (&$events): int {
            $events[] = $a;
            return 1;
        });
        $emails = [];
        Functions\when('cdcf_review_send_submitter_email')->alias(function (...$a) use (&$emails): string {
            $emails[] = $a;
            return 'queued';
        });
        // GET_LOCK, then the projects already announced (12), then RELEASE_LOCK.
        $this->wpdb->results = ['1', [['project_id' => '12']], '1'];

        $this->assertSame(2, cdcf_review_backfill_submission_received());

        $this->assertSame([
            [11, 'submission_received', null, null, ['migrated' => true]],
            [13, 'submission_received', null, null, ['migrated' => true]],
        ], $events);
        $this->assertSame([[11, 'confirmation']], $emails);
        $this->assertSame('project', $args['post_type']);
        $this->assertSame('pending', $args['post_status']);
        $this->assertSame('', $args['lang']);
        $this->assertSame(
            [['key' => CDCF_REVIEW_STAGE_META, 'value' => ['in_review', 'awaiting_submitter'], 'compare' => 'IN']],
            $args['meta_query']
        );
        $this->assertStringContainsString("SELECT GET_LOCK('cdcf_review_received_backfill', 0)", $this->wpdb->queries[0]);
        $this->assertStringContainsString("type = 'submission_received' AND project_id IN (11,12,13)", $this->wpdb->queries[1]);
        $this->assertStringContainsString("SELECT RELEASE_LOCK('cdcf_review_received_backfill')", $this->wpdb->queries[2]);
    }

    public function test_received_backfill_leaves_the_work_to_the_request_holding_the_lock(): void
    {
        Functions\when('get_posts')->justReturn([11]);
        Functions\expect('cdcf_review_record_event')->never();
        Functions\expect('cdcf_review_send_submitter_email')->never();
        $this->wpdb->results = ['0'];

        $this->assertSame(0, cdcf_review_backfill_submission_received());
    }

    public function test_received_backfill_with_nothing_pending_takes_no_lock(): void
    {
        Functions\when('get_posts')->justReturn([]);

        $this->assertSame(0, cdcf_review_backfill_submission_received());
        $this->assertSame([], $this->wpdb->queries);
    }

    // ─── Version 4: pending community referrals ────────────────────────

    /** Scripts the stage meta (id => stage) and records writes, events and emails. */
    private function referralHarness(array $stages, &$log): void
    {
        $log = ['args' => null, 'stage' => [], 'events' => [], 'emails' => []];
        Functions\when('get_posts')->alias(function (array $a) use (&$log, $stages): array {
            $log['args'] = $a;
            return array_keys($stages);
        });
        Functions\when('get_post_meta')->alias(
            static fn(int $id, string $key) => $key === CDCF_REVIEW_STAGE_META ? ($stages[$id] ?? '') : ''
        );
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use (&$log): bool {
            $log['stage'][] = [$id, $key, $value];
            return true;
        });
        Functions\when('cdcf_review_record_event')->alias(function (...$a) use (&$log): int {
            $log['events'][] = $a;
            return 1;
        });
        Functions\when('cdcf_review_send_submitter_email')->alias(function (...$a) use (&$log): string {
            $log['emails'][] = $a;
            return 'queued';
        });
    }

    public function test_referral_backfill_puts_pending_referrals_in_review_and_announces_them(): void
    {
        $this->referralHarness([21 => '', 22 => 'in_review', 23 => 'decided'], $log);
        // GET_LOCK, nothing announced yet, RELEASE_LOCK.
        $this->wpdb->results = ['1', [], '1'];

        $this->assertSame(2, cdcf_review_backfill_referrals());

        $this->assertSame('community_project', $log['args']['post_type']);
        $this->assertSame('pending', $log['args']['post_status']);
        $this->assertSame('', $log['args']['lang']);
        $this->assertSame([['key' => '_referral_submitter_email', 'compare' => 'EXISTS']], $log['args']['meta_query']);
        $this->assertSame([[21, '_cdcf_review_stage', 'in_review']], $log['stage']);
        $this->assertSame([
            [21, 'submission_received', null, null, ['migrated' => true]],
            [22, 'submission_received', null, null, ['migrated' => true]],
        ], $log['events']);
        $this->assertSame([[21, 'confirmation'], [22, 'confirmation']], $log['emails']);
        $this->assertStringContainsString("SELECT GET_LOCK('cdcf_review_referral_backfill', 0)", $this->wpdb->queries[0]);
        $this->assertStringContainsString("type = 'submission_received' AND project_id IN (21,22,23)", $this->wpdb->queries[1]);
        $this->assertStringContainsString("SELECT RELEASE_LOCK('cdcf_review_referral_backfill')", $this->wpdb->queries[2]);
    }

    public function test_referral_backfill_skips_referrals_already_announced(): void
    {
        $this->referralHarness([31 => 'in_review', 32 => 'in_review'], $log);
        $this->wpdb->results = ['1', [['project_id' => '31']], '1'];

        $this->assertSame(1, cdcf_review_backfill_referrals());

        $this->assertSame([[32, 'submission_received', null, null, ['migrated' => true]]], $log['events']);
        $this->assertSame([[32, 'confirmation']], $log['emails']);
        $this->assertSame([], $log['stage']);
    }

    public function test_referral_backfill_without_a_valid_email_still_announces(): void
    {
        $this->referralHarness([41 => ''], $log);
        Functions\when('cdcf_review_send_submitter_email')->alias(function (...$a) use (&$log): string {
            $log['emails'][] = $a;
            return 'no_email';
        });
        $this->wpdb->results = ['1', [], '1'];

        $this->assertSame(1, cdcf_review_backfill_referrals());

        $this->assertCount(1, $log['events']);
        $this->assertCount(1, $log['emails']);
    }

    public function test_referral_backfill_leaves_the_work_to_the_request_holding_the_lock(): void
    {
        $this->referralHarness([51 => ''], $log);
        $this->wpdb->results = ['0'];

        $this->assertSame(0, cdcf_review_backfill_referrals());

        $this->assertSame([], $log['stage']);
        $this->assertSame([], $log['events']);
        $this->assertSame([], $log['emails']);
    }

    public function test_referral_backfill_with_nothing_pending_takes_no_lock(): void
    {
        Functions\when('get_posts')->justReturn([]);

        $this->assertSame(0, cdcf_review_backfill_referrals());
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_maybe_upgrade_runs_the_referral_backfill_before_storing_the_version(): void
    {
        Functions\when('get_option')->justReturn('3');
        Functions\when('dbDelta')->justReturn([]);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_posts')->justReturn([]);
        $order = [];
        Functions\when('cdcf_review_backfill_submission_received')->alias(function () use (&$order): int {
            $order[] = 'received';
            return 0;
        });
        Functions\when('cdcf_review_backfill_referrals')->alias(function () use (&$order): int {
            $order[] = 'referrals';
            return 0;
        });
        Functions\when('update_option')->alias(function () use (&$order): bool {
            $order[] = 'version';
            return true;
        });
        foreach (['entries', 'attachments', 'events', 'notifications'] as $t) {
            $this->wpdb->results[] = "wp_cdcf_review_{$t}";
        }

        cdcf_review_maybe_upgrade();

        $this->assertSame(['received', 'referrals', 'version'], $order);
    }
}
