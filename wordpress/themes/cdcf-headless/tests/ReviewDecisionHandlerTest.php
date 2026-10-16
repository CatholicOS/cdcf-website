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
    private string $emailResult = 'queued';
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
        Functions\when('add_post_meta')->alias(function (int $id, string $k, $v, bool $unique = false) {
            if (isset($this->meta[$id][$k])) {
                return false;
            }
            $this->meta[$id][$k] = $v;
            return 1;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $k): bool {
            unset($this->meta[$id][$k]);
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
            $this->events[] = $args;
            return 1;
        });
        Functions\when('cdcf_review_send_submitter_email')->alias(function (int $id, string $t, array $vars = []): string {
            $this->emails[] = [$id, $t, $vars];
            return $this->emailResult;
        });
    }

    protected function tearDown(): void
    {
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
    $this->assertSame([[40, 'decision', ['outcome' => 'cdcf', 'summary' => 'Thank you.']]], $this->emails);
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
    $this->meta[40]['_cdcf_review_deciding'] = (string) time();
    $res = $this->decide(['decision' => 'cdcf', 'rationale' => 'x']);
    $this->assertSame('decision_in_progress', $res->get_error_code());
    $this->assertSame(409, $res->get_error_data()['status']);
    $this->assertSame([], $this->applied);
}

public function test_a_stale_lock_is_taken_over(): void
{
    $this->meta[40]['_cdcf_review_deciding'] = (string) (time() - 600);
    $this->assertSame(200, $this->decide(['decision' => 'cdcf', 'rationale' => 'x'])->get_status());
    $this->assertArrayNotHasKey('_cdcf_review_deciding', $this->meta[40]);
}

public function test_a_failed_outcome_records_nothing_and_releases_the_lock(): void
{
    $this->applyResult = new WP_Error('conversion_failed', 'no', ['status' => 500]);
    $res = $this->decide(['decision' => 'community', 'rationale' => 'x']);
    $this->assertSame('conversion_failed', $res->get_error_code());
    $this->assertSame([], $this->recorded);
    $this->assertSame([], $this->emails);
    $this->assertArrayNotHasKey('_cdcf_review_deciding', $this->meta[40]);
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
    $this->assertSame('sanitize_textarea_field', $args['rationale']['sanitize_callback']);
    $this->assertSame('sanitize_textarea_field', $args['submitter_summary']['sanitize_callback']);
}
}
