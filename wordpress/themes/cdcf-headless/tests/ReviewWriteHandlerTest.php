<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the write side of includes/handlers/review.php.
 */
final class ReviewWriteHandlerTest extends TestCase
{
    /** @var list<array{0: int, 1: string, 2: ?int, 3: ?int, 4: array}> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->events = [];
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_post')->alias(static function (int $id) {
            $p = new stdClass();
            $p->ID = $id;
            $p->post_type = 'project';
            $p->post_status = 'pending';
            return $p;
        });
        Functions\when('get_post_meta')->justReturn('in_review');
        Functions\when('cdcf_review_membership')->justReturn(['technical' => true, 'ecclesial' => false, 'board' => false]);
        Functions\when('get_userdata')->justReturn(false);
        Functions\when('cdcf_review_record_event')->alias(
            function (int $pid, string $type, ?int $actor, ?int $entry = null, array $data = []): int {
                $this->events[] = [$pid, $type, $actor, $entry, $data];
                return 1;
            }
        );
        Functions\when('cdcf_review_get_entry')->alias(fn(int $id) => $this->entry(['id' => $id]));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function request(array $params): WP_REST_Request
    {
        $r = new WP_REST_Request();
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        return $r;
    }

    private function entry(array $o): array
    {
        return array_merge([
            'id' => 1, 'project_id' => 40, 'kind' => 'review', 'parent_id' => null,
            'author_user_id' => 7, 'review_slot' => 7, 'body_md' => 'Old body', 'vote' => 'reject',
            'created_at' => '2026-10-15 10:00:00.000000', 'edited_at' => null,
        ], $o);
    }

    // ─── body rules ──────────────────────────────────────────────────

    public function test_sanitize_markdown_strips_nul_and_invalid_utf8_and_trims(): void
    {
        Functions\when('wp_check_invalid_utf8')->alias(static fn(string $s): string => mb_check_encoding($s, 'UTF-8') ? $s : '');

        $this->assertSame("# Title\n\nText", cdcf_review_sanitize_markdown("  # Title\n\nT\0ext  "));
        $this->assertSame('', cdcf_review_sanitize_markdown("\xC3\x28"));
        $this->assertSame('', cdcf_review_sanitize_markdown(['not', 'a', 'string']));
    }

    public function test_whitespace_only_and_oversized_bodies_are_rejected(): void
    {
        $this->assertSame('empty_body', cdcf_review_validate_body('')->get_error_code());
        $this->assertSame('body_too_long', cdcf_review_validate_body(str_repeat('é', CDCF_REVIEW_BODY_MAX + 1))->get_error_code());
        $this->assertTrue(cdcf_review_validate_body(str_repeat('é', CDCF_REVIEW_BODY_MAX)));
    }

    // ─── PUT my-review ───────────────────────────────────────────────

    public function test_first_review_is_created_with_201_and_event(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn(null);
        $inserted = null;
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 5;
        });

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'Fits.']));

        $this->assertSame(201, $res->get_status());
        $this->assertSame(5, $res->get_data()['id']);
        $this->assertSame(
            ['project_id' => 40, 'kind' => 'review', 'author_user_id' => 7, 'body_md' => 'Fits.', 'vote' => 'cdcf'],
            $inserted
        );
        $this->assertSame([[40, 'review_posted', 7, 5, []]], $this->events);
    }

    public function test_duplicate_first_review_race_returns_409(): void
    {
        $calls = 0;
        Functions\when('cdcf_review_find_review')->alias(function () use (&$calls) {
            return $calls++ === 0 ? null : $this->entry([]);
        });
        Functions\when('cdcf_review_insert_entry')->justReturn(0);

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'Fits.']));

        $this->assertSame('review_exists', $res->get_error_code());
        $this->assertSame(409, $res->get_error_data()['status']);
        $this->assertSame([], $this->events);
    }

    public function test_insert_failure_without_race_returns_500(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn(null);
        Functions\when('cdcf_review_insert_entry')->justReturn(0);

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'Fits.']));

        $this->assertSame(500, $res->get_error_data()['status']);
    }

    public function test_changing_vote_and_body_records_both_events(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn($this->entry([]));
        $update = null;
        Functions\when('cdcf_review_update_entry')->alias(function (int $id, array $f, string $exp) use (&$update): bool {
            $update = [$id, $f, $exp];
            return true;
        });

        $res = cdcf_rest_review_put_my_review($this->request([
            'id' => 40, 'vote' => 'community', 'body_md' => 'New body', 'edited_at' => '',
        ]));

        $this->assertSame(200, $res->get_status());
        $this->assertSame([1, ['body_md' => 'New body', 'vote' => 'community'], ''], $update);
        $this->assertSame(
            [
                [40, 'vote_changed', 7, 1, ['from' => 'reject', 'to' => 'community']],
                [40, 'review_edited', 7, 1, []],
            ],
            $this->events
        );
    }

    public function test_stale_edited_at_returns_409_without_writing(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn($this->entry(['edited_at' => '2026-10-15 11:00:00.000000']));
        Functions\expect('cdcf_review_update_entry')->never();

        $res = cdcf_rest_review_put_my_review($this->request([
            'id' => 40, 'vote' => 'cdcf', 'body_md' => 'x', 'edited_at' => '',
        ]));

        $this->assertSame('stale_entry', $res->get_error_code());
        $this->assertSame(409, $res->get_error_data()['status']);
    }

    public function test_lost_compare_and_set_returns_409(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn($this->entry([]));
        Functions\when('cdcf_review_update_entry')->justReturn(false);

        $res = cdcf_rest_review_put_my_review($this->request([
            'id' => 40, 'vote' => 'cdcf', 'body_md' => 'x', 'edited_at' => '',
        ]));

        $this->assertSame(409, $res->get_error_data()['status']);
        $this->assertSame([], $this->events);
    }

    public function test_review_writes_are_locked_after_decision(): void
    {
        Functions\when('get_post_meta')->justReturn('decided');
        Functions\expect('cdcf_review_insert_entry')->never();

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'x']));

        $this->assertSame('review_locked', $res->get_error_code());
    }

    public function test_empty_review_body_is_400(): void
    {
        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => '']));

        $this->assertSame(400, $res->get_error_data()['status']);
    }

    // ─── POST entries ────────────────────────────────────────────────

    public function test_reply_to_a_review_is_created(): void
    {
        $inserted = null;
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 9;
        });

        $res = cdcf_rest_review_post_entry($this->request([
            'id' => 40, 'kind' => 'reply', 'parent_id' => 1, 'body_md' => 'Agreed.',
        ]));

        $this->assertSame(201, $res->get_status());
        $this->assertSame(1, $inserted['parent_id']);
        $this->assertSame([[40, 'reply_posted', 7, 9, []]], $this->events);
    }

    public function test_reply_to_non_review_or_other_project_is_invalid_parent(): void
    {
        Functions\expect('cdcf_review_insert_entry')->never();
        $cases = [
            'missing'       => null,
            'reply parent'  => $this->entry(['kind' => 'reply']),
            'discussion'    => $this->entry(['kind' => 'discussion']),
            'other project' => $this->entry(['project_id' => 41]),
        ];
        foreach ($cases as $label => $parent) {
            Functions\when('cdcf_review_get_entry')->justReturn($parent);

            $res = cdcf_rest_review_post_entry($this->request([
                'id' => 40, 'kind' => 'reply', 'parent_id' => 1, 'body_md' => 'x',
            ]));

            $this->assertSame('invalid_parent', $res->get_error_code(), $label);
            $this->assertSame(400, $res->get_error_data()['status'], $label);
        }
    }

    public function test_discussion_post_ignores_parent_and_records_event(): void
    {
        $inserted = null;
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 10;
        });

        cdcf_rest_review_post_entry($this->request([
            'id' => 40, 'kind' => 'discussion', 'parent_id' => 1, 'body_md' => 'Next call?',
        ]));

        $this->assertNull($inserted['parent_id']);
        $this->assertSame([[40, 'discussion_posted', 7, 10, []]], $this->events);
    }

    // ─── PATCH entries/{id} ──────────────────────────────────────────

    public function test_author_can_edit_own_reply(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry(['id' => 9, 'kind' => 'reply', 'parent_id' => 1, 'vote' => null]));
        Functions\expect('cdcf_review_update_entry')->once()->with(9, ['body_md' => 'Edited'], '')->andReturn(true);

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 9, 'body_md' => 'Edited', 'edited_at' => '']));

        $this->assertSame(200, $res->get_status());
        $this->assertSame([], $this->events);
    }

    public function test_editing_someone_elses_entry_is_403(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry(['kind' => 'reply', 'author_user_id' => 8]));

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 9, 'body_md' => 'x', 'edited_at' => '']));

        $this->assertSame('not_author', $res->get_error_code());
        $this->assertSame(403, $res->get_error_data()['status']);
    }

    public function test_reviews_must_be_edited_through_my_review(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry([]));

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 1, 'body_md' => 'x', 'edited_at' => '']));

        $this->assertSame('use_my_review', $res->get_error_code());
    }

    public function test_patch_unknown_entry_is_404(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn(null);

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 77, 'body_md' => 'x', 'edited_at' => '']));

        $this->assertSame(404, $res->get_error_data()['status']);
    }
}
