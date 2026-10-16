<?php

declare(strict_types=1);

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/repository.php against the recording
 * FakeWpdb double.
 */
final class ReviewRepositoryTest extends TestCase
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

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '3', 'project_id' => '40', 'kind' => 'review', 'parent_id' => null,
            'author_user_id' => '7', 'review_slot' => '7', 'body_md' => 'Good fit.',
            'vote' => 'cdcf', 'created_at' => '2026-10-15 10:00:00.000000', 'edited_at' => null,
        ], $overrides);
    }

    public function test_insert_review_sets_review_slot_and_timestamp(): void
    {
        $id = cdcf_review_insert_entry([
            'project_id' => 40, 'kind' => 'review', 'author_user_id' => 7,
            'body_md' => 'Good fit.', 'vote' => 'cdcf',
        ]);

        $this->assertSame(1, $id);
        $insert = $this->wpdb->inserts[0];
        $this->assertSame('wp_cdcf_review_entries', $insert['table']);
        $this->assertSame(7, $insert['data']['review_slot']);
        $this->assertNull($insert['data']['parent_id']);
        $this->assertSame('cdcf', $insert['data']['vote']);
        $this->assertMatchesRegularExpression('/\.\d{6}$/', $insert['data']['created_at']);
    }

    public function test_insert_non_review_has_null_slot_and_vote(): void
    {
        cdcf_review_insert_entry([
            'project_id' => 40, 'kind' => 'reply', 'parent_id' => 3,
            'author_user_id' => 8, 'body_md' => 'Agreed.', 'vote' => 'reject',
        ]);

        $data = $this->wpdb->inserts[0]['data'];
        $this->assertNull($data['review_slot']);
        $this->assertNull($data['vote']);
        $this->assertSame(3, $data['parent_id']);
    }

    public function test_insert_returns_zero_on_failure(): void
    {
        $this->wpdb->failInsert = true;

        $this->assertSame(0, cdcf_review_insert_entry([
            'project_id' => 40, 'kind' => 'review', 'author_user_id' => 7, 'body_md' => 'x', 'vote' => 'cdcf',
        ]));
    }

    public function test_insert_suppresses_db_error_output(): void
    {
        $this->wpdb->failInsert = true;

        cdcf_review_insert_entry(['project_id' => 40, 'kind' => 'review', 'author_user_id' => 7, 'body_md' => 'x', 'vote' => 'cdcf']);

        // A duplicate-key insert is an expected 409, not a printed DB error.
        $this->assertTrue($this->wpdb->insertSuppressed);
        $this->assertFalse($this->wpdb->suppress_errors(false), 'previous suppression state is restored');
    }

    public function test_voter_ids_group_review_authors_by_project(): void
    {
        $this->wpdb->results[] = [
            ['project_id' => '40', 'author_user_id' => '7'],
            ['project_id' => '40', 'author_user_id' => '99'],
            ['project_id' => '42', 'author_user_id' => '10'],
        ];

        $this->assertSame([40 => [7, 99], 42 => [10]], cdcf_review_voter_ids([40, 42]));
        $this->assertStringContainsString("kind = 'review'", $this->wpdb->queries[0]);
        $this->assertStringContainsString('project_id IN (40,42)', $this->wpdb->queries[0]);
        $this->assertSame([], cdcf_review_voter_ids([]));
    }

    public function test_get_entry_normalizes_ints(): void
    {
        $this->wpdb->results[] = $this->row();

        $entry = cdcf_review_get_entry(3);

        $this->assertSame(3, $entry['id']);
        $this->assertSame(40, $entry['project_id']);
        $this->assertSame(7, $entry['author_user_id']);
        $this->assertNull($entry['parent_id']);
        $this->assertStringContainsString('WHERE id = 3', $this->wpdb->queries[0]);
    }

    public function test_get_entry_returns_null_when_missing(): void
    {
        $this->assertNull(cdcf_review_get_entry(99));
    }

    public function test_find_review_queries_by_project_and_author(): void
    {
        $this->wpdb->results[] = $this->row();

        $this->assertSame(3, cdcf_review_find_review(40, 7)['id']);
        $this->assertStringContainsString("project_id = 40 AND kind = 'review' AND author_user_id = 7", $this->wpdb->queries[0]);
    }

    public function test_project_entries_are_ordered_oldest_first(): void
    {
        $this->wpdb->results[] = [$this->row(), $this->row(['id' => '4', 'kind' => 'reply', 'parent_id' => '3'])];

        $entries = cdcf_review_project_entries(40);

        $this->assertCount(2, $entries);
        $this->assertSame(3, $entries[1]['parent_id']);
        $this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $this->wpdb->queries[0]);
    }

    public function test_update_matches_version_token(): void
    {
        $this->wpdb->results[] = 1;

        $this->assertTrue(cdcf_review_update_entry(3, ['body_md' => 'New', 'vote' => 'reject'], '2026-10-15 10:00:00.000000'));
        $sql = $this->wpdb->queries[0];
        $this->assertStringContainsString("body_md = 'New'", $sql);
        $this->assertStringContainsString("vote = 'reject'", $sql);
        $this->assertStringContainsString("WHERE id = 3 AND COALESCE(edited_at, created_at) = '2026-10-15 10:00:00.000000'", $sql);
    }

    public function test_update_with_stale_version_returns_false(): void
    {
        $this->wpdb->results[] = 0;

        $this->assertFalse(cdcf_review_update_entry(3, ['body_md' => 'New'], '2026-10-15 10:00:00.000001'));
    }

    public function test_update_with_empty_version_never_writes(): void
    {
        $this->assertFalse(cdcf_review_update_entry(3, ['body_md' => 'New'], ''));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_entry_version_is_edited_at_or_created_at(): void
    {
        $this->assertSame('2026-10-15 10:00:00.000000', cdcf_review_entry_version(cdcf_review_normalize_row($this->row())));
        $this->assertSame('2026-10-16 08:00:00.000000', cdcf_review_entry_version(cdcf_review_normalize_row($this->row(['edited_at' => '2026-10-16 08:00:00.000000']))));
    }

    public function test_update_ignores_columns_outside_allowlist(): void
    {
        $this->assertFalse(cdcf_review_update_entry(3, ['author_user_id' => 1], 'v'));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_tallies_group_votes_per_project(): void
    {
        $this->wpdb->results[] = [
            ['project_id' => '40', 'vote' => 'cdcf', 'n' => '2'],
            ['project_id' => '40', 'vote' => 'reject', 'n' => '1'],
            ['project_id' => '41', 'vote' => 'community', 'n' => '1'],
        ];

        $tallies = cdcf_review_tallies([40, 41]);

        $this->assertSame(['cdcf' => 2, 'community' => 0, 'reject' => 1], $tallies[40]);
        $this->assertSame(['cdcf' => 0, 'community' => 1, 'reject' => 0], $tallies[41]);
        $this->assertStringContainsString('project_id IN (40,41)', $this->wpdb->queries[0]);
    }

    public function test_tallies_of_no_projects_skip_the_query(): void
    {
        $this->assertSame([], cdcf_review_tallies([]));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_plurality(): void
    {
        $this->assertSame('cdcf', cdcf_review_plurality(['cdcf' => 2, 'community' => 1, 'reject' => 0]));
        $this->assertNull(cdcf_review_plurality(['cdcf' => 1, 'community' => 1, 'reject' => 0]));
        $this->assertNull(cdcf_review_plurality(cdcf_review_empty_tally()));
    }

    public function test_votes_by_user(): void
    {
        $this->wpdb->results[] = [['project_id' => '40', 'vote' => 'community']];

        $this->assertSame([40 => 'community'], cdcf_review_votes_by_user(7, [40, 41]));
        $this->assertStringContainsString('author_user_id = 7', $this->wpdb->queries[0]);
    }

    public function test_reply_parent_author(): void
    {
        $this->wpdb->results[] = ['id' => '5', 'kind' => 'reply', 'parent_id' => '3', 'author_user_id' => '8'];
        $this->wpdb->results[] = ['id' => '3', 'kind' => 'review', 'parent_id' => null, 'author_user_id' => '7'];

        $this->assertSame(7, cdcf_review_reply_parent_author(5));
        $this->assertNull(cdcf_review_reply_parent_author(6), 'unknown entry');
    }

    public function test_reply_parent_authors_in_one_query(): void
    {
        $this->assertSame([], cdcf_review_reply_parent_authors([]));
        $this->assertSame([], $this->wpdb->queries, 'no query for an empty list');

        $this->wpdb->results[] = [['id' => '5', 'author_user_id' => '7']];
        $this->assertSame([5 => 7], cdcf_review_reply_parent_authors([5, 6]));
        $this->assertStringContainsString('JOIN wp_cdcf_review_entries p ON p.id = r.parent_id', $this->wpdb->queries[0]);
        $this->assertStringContainsString('r.id IN (5,6)', $this->wpdb->queries[0]);
    }
}
