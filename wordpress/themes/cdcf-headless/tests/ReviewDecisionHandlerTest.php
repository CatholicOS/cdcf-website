<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewDecisionHandlerTest extends TestCase
{
    private array $meta = [];
    private array $events = [];
    private array $emails = [];
    private array $applied = [];
    private array $recorded = [];
    private array $tally = ['cdcf' => 0, 'community' => 0, 'reject' => 0];
    private bool $decideOnLock = false;
    private string $lockResult = '1';
    /** @var list<string> */
    private array $lockSql = [];
    private string $emailResult = 'queued';
    private bool $referral = false;
    /** @var mixed */
    private $applyResult = true;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [40 => [CDCF_REVIEW_STAGE_META => 'in_review']];
        $this->events = $this->emails = $this->applied = $this->recorded = [];
        $this->tally = ['cdcf' => 0, 'community' => 0, 'reject' => 0];
        $this->emailResult = 'queued';
        $this->applyResult = true;
        $this->referral = false;
        Functions\when('cdcf_review_is_referral')->alias(fn(int $id): bool => $this->referral);
        $this->decideOnLock = false;
        $this->lockResult = '1';
        $this->lockSql = [];
        Functions\when('wp_cache_delete')->justReturn(true);
        $test = $this;
        $GLOBALS['wpdb'] = new class ($test) {
            public function __construct(private ReviewDecisionHandlerTest $test)
            {
            }

            public function prepare(string $query, mixed ...$args): string
            {
                return str_replace('%s', "'" . $args[0] . "'", $query);
            }

            public function get_var(string $sql): mixed
            {
                return $this->test->onLockQuery($sql);
            }
        };

        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('rest_ensure_response')->alias(static fn($d) => new WP_REST_Response($d, 200));
        Functions\when('get_current_user_id')->justReturn(9);
        Functions\when('get_post')->justReturn((object) ['ID' => 40, 'post_type' => 'project', 'post_status' => 'pending']);
        Functions\when('get_post_type')->justReturn('project');
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_post_meta')->alias(fn(int $id, string $k, bool $s = false) => $this->meta[$id][$k] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v): bool {
            $this->meta[$id][$k] = $v;
            return true;
        });
        Functions\when('cdcf_review_tallies')->alias(fn() => [40 => $this->tally]);
        Functions\when('cdcf_review_apply_outcome')->alias(function (int $id, string $d) {
            $this->applied[] = [$id, $d];
            return $this->applyResult;
        });
        Functions\when('cdcf_review_record_decision')->alias(function (int $id, string $d, ?int $uid, string $rationale, string $summary): void {
            $this->recorded[] = [$id, $d, $uid, $rationale, $summary];
            $this->meta[$id][CDCF_REVIEW_STAGE_META] = 'decided';
        });
        Functions\when('cdcf_review_record_event')->alias(function (...$args): int {
            // Note whether the decision lock was still held when the event fired.
            $args[] = ['lock_released' => in_array("SELECT RELEASE_LOCK('cdcf_review_decide_40')", $this->lockSql, true)];
            $this->events[] = $args;
            return 1;
        });
        Functions\when('cdcf_review_send_submitter_email')->alias(function (int $id, string $t, array $vars = []): string {
            $this->emails[] = [$id, $t, $vars];
            return $this->emailResult;
        });
    }

    /** Called by the $wpdb double for GET_LOCK / RELEASE_LOCK. */
    public function onLockQuery(string $sql): mixed
    {
        $this->lockSql[] = $sql;
        if (str_starts_with($sql, 'SELECT GET_LOCK(')) {
            if ($this->lockResult === '1' && $this->decideOnLock) {
                $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
            }
            return $this->lockResult;
        }
        return '1';
    }

    private function assertLockReleased(): void
    {
        $this->assertSame(
            ["SELECT GET_LOCK('cdcf_review_decide_40', 0)", "SELECT RELEASE_LOCK('cdcf_review_decide_40')"],
            $this->lockSql
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function decide(array $params): mixed
    {
        $r = new WP_REST_Request();
        foreach ($params + ['id' => 40, 'rationale' => '', 'submitter_summary' => 'Thank you.'] as $k => $v) {
            $r->set_param($k, $v);
        }
        return cdcf_rest_review_post_decision($r);
    }

    public function test_following_the_plurality_needs_no_rationale(): void
    {
        $this->tally = ['cdcf' => 2, 'community' => 1, 'reject' => 0];
        $res = $this->decide(['decision' => 'cdcf']);
        $this->assertSame(200, $res->get_status());
        $this->assertSame(['id' => 40, 'stage' => 'decided', 'decision' => 'cdcf', 'post_type' => 'project', 'post_status' => 'publish', 'warnings' => []], $res->get_data());
        $this->assertSame([[40, 'cdcf']], $this->applied);
        $this->assertSame([[40, 'cdcf', 9, '', 'Thank you.']], $this->recorded);
        $this->assertSame('decision_recorded', $this->events[0][1]);
        $this->assertSame(['lock_released' => true], end($this->events[0]), 'members are notified (and maybe emailed inline) only after the lock is released');
        $this->assertSame([[40, 'decision', ['outcome' => 'cdcf', 'summary' => 'Thank you.']]], $this->emails);
        $this->assertLockReleased();
    }

    public function test_overriding_the_plurality_requires_a_rationale(): void
    {
        $this->tally = ['cdcf' => 2, 'community' => 0, 'reject' => 0];
        $res = $this->decide(['decision' => 'reject']);
        $this->assertSame('rationale_required', $res->get_error_code());
        $this->assertSame(400, $res->get_error_data()['status']);
        $this->assertSame([], $this->applied);
    }

    public function test_no_votes_or_a_tie_requires_a_rationale(): void
    {
        $this->tally = ['cdcf' => 1, 'community' => 1, 'reject' => 0];
        $this->assertSame('rationale_required', $this->decide(['decision' => 'cdcf'])->get_error_code());
        $this->tally = ['cdcf' => 0, 'community' => 0, 'reject' => 0];
        $this->assertSame('rationale_required', $this->decide(['decision' => 'cdcf'])->get_error_code());
        $this->assertSame(200, $this->decide(['decision' => 'cdcf', 'rationale' => 'Clear case.'])->get_status());
    }

    public function test_cdcf_decision_is_rejected_for_any_community_project(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 40, 'post_type' => 'community_project', 'post_status' => 'pending']);
        $this->referral = false;
        $this->tally = ['cdcf' => 2, 'community' => 0, 'reject' => 0];
        $res = $this->decide(['decision' => 'cdcf']);
        $this->assertSame('invalid_decision', $res->get_error_code());
        $this->assertSame(400, $res->get_error_data()['status']);
        $this->assertSame('A community project can be accepted or rejected.', $res->get_error_message());
        $this->assertSame([], $this->applied);
    }

    public function test_referral_decision_cannot_be_cdcf(): void
    {
        $this->referral = true;
        $this->tally = ['cdcf' => 0, 'community' => 2, 'reject' => 0];
        $res = $this->decide(['decision' => 'cdcf', 'rationale' => 'x']);
        $this->assertSame('invalid_decision', $res->get_error_code());
        $this->assertSame(400, $res->get_error_data()['status']);
        $this->assertSame('A referral can be accepted or rejected.', $res->get_error_message());
        $this->assertSame([], $this->applied);
    }

    public function test_referral_tie_requires_a_rationale(): void
    {
        $this->referral = true;
        $this->tally = ['cdcf' => 0, 'community' => 1, 'reject' => 1];
        $this->assertSame('rationale_required', $this->decide(['decision' => 'community'])->get_error_code());
    }

    public function test_a_summary_is_required(): void
    {
        $this->tally = ['cdcf' => 1, 'community' => 0, 'reject' => 0];
        $this->assertSame('summary_required', $this->decide(['decision' => 'cdcf', 'submitter_summary' => '  '])->get_error_code());
    }

    public function test_overlong_texts_are_rejected(): void
    {
        $this->tally = ['cdcf' => 1, 'community' => 0, 'reject' => 0];
        $this->assertSame('body_too_long', $this->decide(['decision' => 'cdcf', 'submitter_summary' => str_repeat('a', 10001)])->get_error_code());
        $this->assertSame('body_too_long', $this->decide(['decision' => 'cdcf', 'rationale' => str_repeat('a', 50001)])->get_error_code());
    }

    public function test_a_decided_review_is_locked(): void
    {
        $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
        $this->assertSame('review_locked', $this->decide(['decision' => 'cdcf', 'rationale' => 'x'])->get_error_code());
    }

    public function test_a_concurrent_decision_is_refused(): void
    {
        $this->lockResult = '0';
        $res = $this->decide(['decision' => 'cdcf', 'rationale' => 'x']);
        $this->assertSame('decision_in_progress', $res->get_error_code());
        $this->assertSame(409, $res->get_error_data()['status']);
        $this->assertSame([], $this->applied);
        $this->assertSame([], $this->recorded);
        $this->assertSame([], $this->emails);
        // A lock that was never granted is not released.
        $this->assertSame(["SELECT GET_LOCK('cdcf_review_decide_40', 0)"], $this->lockSql);
    }

    public function test_a_lock_query_error_is_treated_as_in_progress(): void
    {
        $this->lockResult = '';
        $this->assertSame('decision_in_progress', $this->decide(['decision' => 'cdcf', 'rationale' => 'x'])->get_error_code());
        $this->assertSame([], $this->applied);
    }

    public function test_a_failed_outcome_records_nothing_and_releases_the_lock(): void
    {
        $this->applyResult = new WP_Error('conversion_failed', 'no', ['status' => 500]);
        $res = $this->decide(['decision' => 'community', 'rationale' => 'x']);
        $this->assertSame('conversion_failed', $res->get_error_code());
        $this->assertSame([], $this->recorded);
        $this->assertSame([], $this->emails);
        $this->assertLockReleased();
    }

    public function test_an_email_failure_is_a_warning_not_an_error(): void
    {
        $this->tally = ['cdcf' => 1, 'community' => 0, 'reject' => 0];
        $this->emailResult = 'failed';
        $res = $this->decide(['decision' => 'cdcf']);
        $this->assertSame(200, $res->get_status());
        $this->assertSame(['email_failed'], $res->get_data()['warnings']);
    }

    public function test_only_board_members_may_decide(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('cdcf_review_can')->alias(fn(int $uid, string $p) => $p !== 'decide');
        $this->assertSame('rest_forbidden', cdcf_rest_review_permission_decide(new WP_REST_Request())->get_error_code());
    }

    public function test_decision_args_validate_the_outcome(): void
    {
        $args = cdcf_review_route_args()['/review/projects/(?P<id>\d+)/decision'];
        $this->assertSame(CDCF_REVIEW_VOTES, $args['decision']['enum']);
        $this->assertSame('rest_validate_request_arg', $args['decision']['validate_callback']);
        $this->assertSame('cdcf_review_sanitize_markdown', $args['rationale']['sanitize_callback']);
        $this->assertSame('cdcf_review_sanitize_markdown', $args['submitter_summary']['sanitize_callback']);
    }

    public function test_a_review_decided_while_waiting_for_the_lock_is_refused(): void
    {
        $this->decideOnLock = true;
        $res = $this->decide(['decision' => 'cdcf', 'rationale' => 'x']);
        $this->assertSame('review_locked', $res->get_error_code());
        $this->assertSame([], $this->applied);
        $this->assertSame([], $this->emails);
        $this->assertLockReleased();
    }
}
