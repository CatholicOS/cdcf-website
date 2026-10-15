<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SubmissionStatusHandlerTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private array $meta = [];
    private array $transients = [];
    private array $entries = [];
    private array $inserted = [];
    private array $events = [];
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        $this->meta = [40 => [
            CDCF_REVIEW_STAGE_META         => 'awaiting_submitter',
            CDCF_REVIEW_TOKEN_HASH_META    => hash('sha256', self::TOKEN),
            '_submission_submitter_email'  => 'Sam@Example.org',
        ]];
        $this->transients = $this->entries = $this->inserted = $this->events = $this->emails = [];
        $this->entries = [
            ['id' => 1, 'project_id' => 40, 'kind' => 'question', 'parent_id' => null, 'author_user_id' => 7, 'body_md' => 'Which licence?', 'created_at' => '2026-10-15 10:00:00.000000'],
        ];

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('rest_ensure_response')->alias(fn($data) => new WP_REST_Response($data));
        Functions\when('get_post_meta')->alias(fn(int $id, string $k) => $this->meta[$id][$k] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v): bool {
            $this->meta[$id][$k] = $v;
            return true;
        });
        Functions\when('get_transient')->alias(fn(string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, $v): bool {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('cdcf_review_get_reviewable_project')->alias(
            fn(int $id) => in_array($id, [40, 41], true)
                ? (object) ['ID' => $id, 'post_title' => 'Liturgy Helper', 'post_status' => 'pending', 'post_type' => 'project', 'post_date' => '2026-10-01 11:00:00', 'post_date_gmt' => '2026-10-01 09:00:00']
                : new WP_Error('review_not_found', 'No project under review with that id.', ['status' => 404])
        );
        Functions\when('cdcf_review_project_entries')->alias(fn() => $this->entries);
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row): int {
            $this->inserted[] = $row;
            return 99;
        });
        Functions\when('cdcf_review_record_event')->alias(function (...$args): int {
            $this->events[] = $args;
            return 1;
        });
        Functions\when('cdcf_review_post_language')->justReturn('it');
        Functions\when('cdcf_frontend_path_for')->justReturn(null);
        Functions\when('cdcf_is_spam_content')->justReturn(false);
        Functions\when('cdcf_review_send_submitter_email')->alias(function (int $id, string $t): string {
            $this->emails[] = [$id, $t];
            return 'queued';
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function request(array $params, ?string $token = self::TOKEN): WP_REST_Request
    {
        $r = new WP_REST_Request();
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        if ($token !== null) {
            $r->set_header('X-CDCF-Submission-Token', $token);
        }
        return $r;
    }

    private function assertNotFound($result): void
    {
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
        $this->assertSame(404, $result->get_error_data()['status']);
    }

    // ─── GET ───────────────────────────────────────────────────────────

    public function test_status_lists_questions_without_saying_who_asked(): void
    {
        $this->entries[] = ['id' => 2, 'project_id' => 40, 'kind' => 'answer', 'parent_id' => 1, 'author_user_id' => null, 'body_md' => 'MIT', 'created_at' => '2026-10-15 11:00:00.000000'];

        $out = cdcf_rest_submission_status_get($this->request(['id' => 40]))->get_data();

        $this->assertSame(40, $out['id']);
        $this->assertSame('Liturgy Helper', $out['title']);
        $this->assertSame('2026-10-01 09:00:00', $out['submitted_at']);
        $this->assertSame('it', $out['language']);
        $this->assertSame('awaiting_submitter', $out['stage']);
        $this->assertSame([[
            'id' => 1, 'body' => 'Which licence?', 'asked_at' => '2026-10-15 10:00:00.000000',
            'answer' => ['body' => 'MIT', 'answered_at' => '2026-10-15 11:00:00.000000'],
        ]], $out['questions']);
        $this->assertNull($out['decision']);
        $this->assertNull($out['public_url']);
    }

    public function test_missing_wrong_or_rotated_tokens_get_the_same_404(): void
    {
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40], null)));
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40], str_repeat('b', 64))));

        $this->meta[40][CDCF_REVIEW_TOKEN_HASH_META] = hash('sha256', str_repeat('c', 64)); // a newer email went out
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40])));
    }

    public function test_expired_token_and_unknown_project_get_the_same_404(): void
    {
        $this->meta[40][CDCF_REVIEW_TOKEN_EXPIRES_META] = time() - 1;
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40])));
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 41])));
    }

    public function test_decided_status_includes_the_decision_and_public_url(): void
    {
        $this->meta[40][CDCF_REVIEW_STAGE_META]     = 'decided';
        $this->meta[40]['_cdcf_review_decision']    = 'cdcf';
        $this->meta[40]['_cdcf_review_summary']     = 'Welcome aboard.';
        $this->meta[40]['_cdcf_review_decided_at']  = 1760000000;
        Functions\when('cdcf_frontend_path_for')->justReturn('/it/projects/liturgy-helper');

        $out = cdcf_rest_submission_status_get($this->request(['id' => 40]))->get_data();

        $this->assertSame(['outcome' => 'cdcf', 'summary' => 'Welcome aboard.', 'decided_at' => 1760000000], $out['decision']);
        $base = defined('CDCF_FRONTEND_URL') ? rtrim((string) CDCF_FRONTEND_URL, '/') : 'http://localhost:3000';
        $this->assertSame($base . '/it/projects/liturgy-helper', $out['public_url']);
    }

    public function test_a_valid_token_is_never_rate_limited(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertSame(200, cdcf_rest_submission_status_get($this->request(['id' => 40]))->get_status());
        }
    }

    public function test_repeated_failed_reads_are_limited_per_project_but_valid_tokens_still_work(): void
    {
        $bad = str_repeat('b', 64);
        for ($i = 0; $i < 120; $i++) {
            $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40], $bad)));
        }
        $result = cdcf_rest_submission_status_get($this->request(['id' => 40], $bad));
        $this->assertSame('rate_limited', $result->get_error_code());
        $this->assertSame(429, $result->get_error_data()['status']);

        $this->assertSame(200, cdcf_rest_submission_status_get($this->request(['id' => 40]))->get_status());
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 41], $bad)));
    }

    // ─── POST answer ───────────────────────────────────────────────────

    public function test_answer_is_saved_and_returns_the_review_to_in_review(): void
    {
        $response = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT licence']));

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['id' => 99, 'question_id' => 1, 'stage' => 'in_review'], $response->get_data());
        $this->assertSame([['project_id' => 40, 'kind' => 'answer', 'parent_id' => 1, 'author_user_id' => null, 'body_md' => 'MIT licence']], $this->inserted);
        $this->assertSame('in_review', $this->meta[40][CDCF_REVIEW_STAGE_META]);
        $this->assertSame([[40, 'submitter_answered', null, 99]], $this->events);
    }

    public function test_stage_stays_awaiting_while_another_question_is_open(): void
    {
        $this->entries[] = ['id' => 3, 'project_id' => 40, 'kind' => 'question', 'parent_id' => null, 'author_user_id' => 8, 'body_md' => 'Who maintains it?', 'created_at' => '2026-10-15 12:00:00.000000'];

        $response = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT']));

        $this->assertSame('awaiting_submitter', $response->get_data()['stage']);
        $this->assertSame('awaiting_submitter', $this->meta[40][CDCF_REVIEW_STAGE_META]);
    }

    public function test_answer_refusals_write_nothing(): void
    {
        $this->entries[] = ['id' => 2, 'project_id' => 40, 'kind' => 'answer', 'parent_id' => 1, 'author_user_id' => null, 'body_md' => 'MIT', 'created_at' => '2026-10-15 11:00:00.000000'];
        $cases = [
            'already_answered'   => [['question_id' => 1, 'body' => 'again'], 409],
            'question_not_found' => [['question_id' => 77, 'body' => 'x'], 404],
            'empty_body'         => [['question_id' => 1, 'body' => "  \n "], 400],
            'body_too_long'      => [['question_id' => 1, 'body' => str_repeat('a', 10001)], 400],
        ];
        foreach ($cases as $code => [$params, $status]) {
            $result = cdcf_rest_submission_status_answer($this->request(['id' => 40] + $params));
            $this->assertSame($code, $result->get_error_code(), $code);
            $this->assertSame($status, $result->get_error_data()['status'], $code);
        }
        $this->assertSame([], $this->inserted);
        $this->assertSame([], $this->events);
    }

    public function test_spam_and_decided_reviews_are_refused(): void
    {
        Functions\when('cdcf_is_spam_content')->justReturn(true);
        $spam = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'buy now']));
        $this->assertSame('answer_rejected', $spam->get_error_code());

        Functions\when('cdcf_is_spam_content')->justReturn(false);
        $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
        $closed = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT']));
        $this->assertSame('review_closed', $closed->get_error_code());
        $this->assertSame(409, $closed->get_error_data()['status']);
        $this->assertSame([], $this->inserted);
    }

    public function test_answer_needs_a_valid_token(): void
    {
        $this->assertNotFound(cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT'], str_repeat('b', 64))));
        $this->assertSame([], $this->inserted);
    }

    public function test_repeated_failed_answers_are_limited_but_a_valid_token_still_answers(): void
    {
        $bad = str_repeat('b', 64);
        for ($i = 0; $i < 20; $i++) {
            $this->assertNotFound(cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'x'], $bad)));
        }
        $result = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'x'], $bad));
        $this->assertSame('rate_limited', $result->get_error_code());
        $this->assertSame(429, $result->get_error_data()['status']);

        $ok = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT']));
        $this->assertSame(201, $ok->get_status());
    }

    public function test_answer_insert_failure_is_a_500_and_changes_nothing(): void
    {
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row): int {
            $this->inserted[] = $row;
            return 0;
        });

        $result = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT licence']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('insert_failed', $result->get_error_code());
        $this->assertSame(500, $result->get_error_data()['status']);
        $this->assertSame('awaiting_submitter', $this->meta[40][CDCF_REVIEW_STAGE_META]);
        $this->assertSame([], $this->events);
    }

    // ─── POST resend-link ──────────────────────────────────────────────

    public function test_matching_email_gets_a_new_link_case_insensitively(): void
    {
        $response = cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => ' sam@EXAMPLE.org '], null));

        $this->assertSame(202, $response->get_status());
        $this->assertSame(['accepted' => true], $response->get_data());
        $this->assertSame([[40, 'link']], $this->emails);
    }

    public function test_wrong_email_never_consumes_the_send_budget(): void
    {
        for ($i = 0; $i < 10; $i++) {
            cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'eve@example.org'], null));
        }
        $this->assertSame([], $this->emails);
        cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'sam@example.org'], null));
        $this->assertSame([[40, 'link']], $this->emails);
    }

    public function test_wrong_email_unknown_project_and_send_cap_all_look_the_same(): void
    {
        $responses = [
            cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'eve@example.org'], null)),
            cdcf_rest_submission_status_resend_link($this->request(['project_id' => 41, 'email' => 'sam@example.org'], null)),
        ];
        for ($i = 0; $i < 6; $i++) {
            $responses[] = cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'sam@example.org'], null));
        }

        foreach ($responses as $response) {
            $this->assertSame(202, $response->get_status());
            $this->assertSame(['accepted' => true], $response->get_data());
        }
        $this->assertCount(5, $this->emails, 'non-matching requests are not counted, so five of the six matching ones send (the cap)');
    }

    public function test_resend_with_no_stored_address_sends_nothing(): void
    {
        $this->meta[40]['_submission_submitter_email'] = '';

        $response = cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'sam@example.org'], null));

        $this->assertSame(202, $response->get_status());
        $this->assertSame(['accepted' => true], $response->get_data());
        $this->assertSame([], $this->emails);
    }
}
