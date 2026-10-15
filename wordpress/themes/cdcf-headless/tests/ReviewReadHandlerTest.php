<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the read side of includes/handlers/review.php.
 * Data-layer functions are stubbed; their own suites cover SQL.
 */
final class ReviewReadHandlerTest extends TestCase
{
    /** @var array<int, array<string, bool>> */
    private array $members = [
        7 => ['technical' => true, 'ecclesial' => false, 'board' => false],
        8 => ['technical' => false, 'ecclesial' => false, 'board' => true],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('cdcf_review_membership')->alias(
            fn(int $uid): array => $this->members[$uid] ?? ['technical' => false, 'ecclesial' => false, 'board' => false]
        );
        Functions\when('get_userdata')->alias(static function (int $uid) {
            if ($uid === 99) {
                return false;
            }
            $u = new WP_User($uid);
            $u->display_name = "User {$uid}";
            return $u;
        });
        Functions\when('get_post_meta')->alias(static fn(int $id, string $key) => match ($key) {
            '_cdcf_review_stage'          => $id === 41 ? '' : 'in_review',
            '_submission_submitter_name'  => 'Jane Doe',
            '_submission_submitter_email' => 'jane@example.org',
            '_submission_repo_urls'       => '["https://github.com/a/b","https://gitlab.com/c/d"]',
            default                       => '',
        });
        Functions\when('get_field')->alias(static fn(string $f) => match ($f) {
            'project_url'      => 'https://example.org',
            'project_category' => ['liturgy'],
            default            => null,
        });
        Functions\when('wp_get_object_terms')->justReturn(['bible', 'mass']);
        Functions\when('pll_get_post_language')->justReturn('it');
        Functions\when('get_post')->alias(fn(int $id) => $this->post($id));
        Functions\when('cdcf_review_member_user_ids')->justReturn([7, 10, 11]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function signIn(int $uid): void
    {
        Functions\when('is_user_logged_in')->justReturn($uid > 0);
        Functions\when('get_current_user_id')->justReturn($uid);
    }

    private function post(int $id, string $status = 'pending', string $type = 'project'): ?stdClass
    {
        if ($id === 404) {
            return null;
        }
        $p = new stdClass();
        $p->ID = $id;
        $p->post_title = "Project {$id}";
        $p->post_content = 'Description';
        $p->post_status = $id === 45 ? 'trash' : $status;
        $p->post_type = $id === 46 ? 'page' : $type;
        $p->post_date_gmt = '2026-10-01 09:00:00';
        return $p;
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
            'author_user_id' => 7, 'review_slot' => 7, 'body_md' => 'Body', 'vote' => 'cdcf',
            'created_at' => '2026-10-15 10:00:00.000000', 'edited_at' => null,
        ], $o);
    }

    // ─── permissions ─────────────────────────────────────────────────

    public function test_anonymous_gets_401(): void
    {
        $this->signIn(0);

        $result = cdcf_rest_review_permission_view($this->request([]));

        $this->assertSame(401, $result->get_error_data()['status']);
    }

    public function test_non_member_gets_403_and_board_cannot_vote(): void
    {
        $this->signIn(42);
        $this->assertSame(403, cdcf_rest_review_permission_view($this->request([]))->get_error_data()['status']);

        $this->signIn(8);
        $this->assertTrue(cdcf_rest_review_permission_view($this->request([])));
        $this->assertTrue(cdcf_rest_review_permission_discuss($this->request([])));
        $this->assertSame(403, cdcf_rest_review_permission_vote($this->request([]))->get_error_data()['status']);
    }

    public function test_me_reports_flags_for_members_and_non_members(): void
    {
        $this->signIn(7);
        $me = cdcf_rest_review_me($this->request([]));
        $this->assertTrue($me['member']);
        $this->assertSame(['technical'], $me['councils']);
        $this->assertSame(['view' => true, 'discuss' => true, 'vote' => true, 'decide' => false], $me['can']);

        $this->signIn(42);
        $this->assertFalse(cdcf_rest_review_me($this->request([]))['member']);
    }

    // ─── reviewable project guard ────────────────────────────────────

    public function test_unknown_trashed_wrong_type_or_unstaged_posts_are_404(): void
    {
        foreach ([404, 45, 46, 41] as $id) {
            $result = cdcf_review_get_reviewable_project($id);
            $this->assertInstanceOf(WP_Error::class, $result, "post {$id}");
            $this->assertSame(404, $result->get_error_data()['status']);
        }
        $this->assertSame(40, cdcf_review_get_reviewable_project(40)->ID);
    }

    public function test_decided_review_is_locked(): void
    {
        Functions\when('get_post_meta')->justReturn('decided');

        $this->assertSame('review_locked', cdcf_review_assert_open(40)->get_error_code());
    }

    // ─── list ────────────────────────────────────────────────────────

    public function test_list_returns_summaries_with_tally_my_vote_and_unread(): void
    {
        $this->signIn(7);
        $query = null;
        Functions\when('get_posts')->alias(function (array $q) use (&$query): array {
            $query = $q;
            return [$this->post(40), $this->post(42)];
        });
        Functions\when('cdcf_review_tallies')->justReturn([40 => ['cdcf' => 1, 'community' => 1, 'reject' => 0]]);
        Functions\when('cdcf_review_votes_by_user')->justReturn([40 => 'cdcf']);
        Functions\when('cdcf_review_unread_counts')->justReturn([42 => 2]);

        $out = cdcf_rest_review_list_projects($this->request(['stage' => 'in_review']));

        $this->assertSame('in_review', $query['meta_value']);
        $this->assertSame(['project', 'community_project'], $query['post_type']);
        $this->assertSame(
            [
                'id' => 40, 'title' => 'Project 40', 'category' => 'liturgy', 'language' => 'it',
                'submitted_at' => '2026-10-01 09:00:00', 'stage' => 'in_review',
                'tally' => ['cdcf' => 1, 'community' => 1, 'reject' => 0],
                'voters_pending' => 1, 'my_vote' => 'cdcf', 'unread' => 0,
            ],
            $out['projects'][0]
        );
        $this->assertSame(['cdcf' => 0, 'community' => 0, 'reject' => 0], $out['projects'][1]['tally']);
        $this->assertSame(3, $out['projects'][1]['voters_pending']);
        $this->assertNull($out['projects'][1]['my_vote']);
        $this->assertSame(2, $out['projects'][1]['unread']);
    }

    public function test_list_without_stage_omits_meta_value(): void
    {
        $this->signIn(7);
        $query = null;
        Functions\when('get_posts')->alias(function (array $q) use (&$query): array {
            $query = $q;
            return [];
        });

        $this->assertSame(['projects' => []], cdcf_rest_review_list_projects($this->request(['stage' => ''])));
        $this->assertArrayNotHasKey('meta_value', $query);
        $this->assertSame('_cdcf_review_stage', $query['meta_key']);
        // Polylang would otherwise narrow the query to the request's
        // current language and hide non-English submissions.
        $this->assertSame('', $query['lang']);
    }

    // ─── detail ──────────────────────────────────────────────────────

    public function test_detail_nests_replies_and_answers_and_hides_email_from_non_board(): void
    {
        $this->signIn(7);
        Functions\when('cdcf_review_project_entries')->justReturn([
            $this->entry(['id' => 1]),
            $this->entry(['id' => 2, 'kind' => 'reply', 'parent_id' => 1, 'author_user_id' => 8, 'vote' => null]),
            $this->entry(['id' => 3, 'kind' => 'discussion', 'author_user_id' => 99, 'vote' => null]),
            $this->entry(['id' => 4, 'kind' => 'question', 'author_user_id' => 8, 'vote' => null]),
            $this->entry(['id' => 5, 'kind' => 'answer', 'parent_id' => 4, 'author_user_id' => null, 'vote' => null]),
        ]);
        Functions\when('cdcf_review_tallies')->justReturn([40 => ['cdcf' => 1, 'community' => 0, 'reject' => 0]]);
        Functions\when('cdcf_review_find_review')->justReturn($this->entry(['id' => 1]));

        $out = cdcf_rest_review_get_project($this->request(['id' => 40]));

        $this->assertSame('Project 40', $out['title']);
        $this->assertSame('it', $out['language']);
        $this->assertSame(
            ['url' => 'https://example.org', 'category' => 'liturgy',
             'repo_urls' => ['https://github.com/a/b', 'https://gitlab.com/c/d'], 'tags' => ['bible', 'mass']],
            $out['fields']
        );
        $this->assertSame(['name' => 'Jane Doe', 'email' => null], $out['submitter']);
        $this->assertSame('cdcf', $out['plurality']);
        $this->assertSame(2, $out['voters_pending']);
        $this->assertCount(1, $out['reviews']);
        $this->assertSame(['id' => 7, 'name' => 'User 7', 'councils' => ['technical']], $out['reviews'][0]['author']);
        $this->assertSame(2, $out['reviews'][0]['replies'][0]['id']);
        $this->assertSame(['board'], $out['reviews'][0]['replies'][0]['author']['councils']);
        $this->assertSame('Former member', $out['discussion'][0]['author']['name']);
        $this->assertNull($out['questions'][0]['answers'][0]['author']);
        $this->assertSame([], $out['reviews'][0]['attachments']);
        $this->assertSame(['can_vote' => true, 'can_decide' => false, 'review_id' => 1], $out['me']);
    }

    public function test_detail_shows_submitter_email_to_board(): void
    {
        $this->signIn(8);
        Functions\when('cdcf_review_project_entries')->justReturn([]);
        Functions\when('cdcf_review_tallies')->justReturn([]);
        Functions\when('cdcf_review_find_review')->justReturn(null);

        $out = cdcf_rest_review_get_project($this->request(['id' => 40]));

        $this->assertSame('jane@example.org', $out['submitter']['email']);
        $this->assertNull($out['plurality']);
        $this->assertNull($out['me']['review_id']);
    }

    public function test_detail_of_trashed_project_is_404(): void
    {
        $this->signIn(7);

        $this->assertSame(404, cdcf_rest_review_get_project($this->request(['id' => 45]))->get_error_data()['status']);
    }

    public function test_repo_urls_fall_back_to_acf_field(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_field')->alias(static fn(string $f) => $f === 'project_repo_url' ? 'https://github.com/x/y' : '');

        $this->assertSame(['https://github.com/x/y'], cdcf_review_submission_fields(40)['repo_urls']);
    }
}
