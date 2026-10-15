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
        Functions\when('get_posts')->justReturn([11, 12]);
        $meta = [];
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use (&$meta): bool {
            $meta[] = [$id, $key, $value];
            return true;
        });
        Functions\expect('update_option')->once()->with('cdcf_review_db_version', CDCF_REVIEW_DB_VERSION);

        cdcf_review_maybe_upgrade();

        $this->assertCount(4, $delta);
        $this->assertSame(
            [[11, '_cdcf_review_stage', 'in_review'], [12, '_cdcf_review_stage', 'in_review']],
            $meta
        );
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

    public function test_now_is_utc_with_microseconds(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/',
            cdcf_review_now()
        );
    }
}
