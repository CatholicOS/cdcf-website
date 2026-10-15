<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewQuestionHandlerTest extends TestCase
{
    private array $meta = [];
    private array $inserted = [];
    private array $events = [];
    private array $emails = [];
    private string $emailResult = 'queued';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [40 => [CDCF_REVIEW_STAGE_META => 'in_review', '_submission_submitter_email' => 'sam@example.org']];
        $this->inserted = $this->events = $this->emails = [];
        $this->emailResult = 'queued';

        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('is_email')->alias(fn($e) => str_contains((string) $e, '@'));
        Functions\when('get_post')->justReturn((object) ['ID' => 40, 'post_type' => 'project', 'post_status' => 'pending']);
        Functions\when('get_post_meta')->alias(fn(int $id, string $k, bool $s = false) => $this->meta[$id][$k] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v): bool {
            $this->meta[$id][$k] = $v;
            return true;
        });
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row): int {
            $this->inserted[] = $row;
            return 55;
        });
        Functions\when('cdcf_review_record_event')->alias(function (...$args): int {
            $this->events[] = $args;
            return 1;
        });
        Functions\when('cdcf_review_entry_response')->alias(
            fn(int $id, int $status) => new WP_REST_Response(['id' => $id, 'kind' => 'question'], $status)
        );
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

    private function ask(string $body, int $id = 40)
    {
        $r = new WP_REST_Request();
        $r->set_param('id', $id);
        $r->set_param('body', $body);
        return cdcf_rest_review_post_question($r);
    }

    public function test_question_is_saved_the_stage_moves_and_the_submitter_is_emailed(): void
    {
        $response = $this->ask('Which licence will you use?');

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['id' => 55, 'kind' => 'question', 'warnings' => []], $response->get_data());
        $this->assertSame([['project_id' => 40, 'kind' => 'question', 'author_user_id' => 7, 'body_md' => 'Which licence will you use?']], $this->inserted);
        $this->assertSame('awaiting_submitter', $this->meta[40][CDCF_REVIEW_STAGE_META]);
        $this->assertSame([[40, 'question_sent', 7, 55]], $this->events);
        $this->assertSame([[40, 'question', ['question' => 'Which licence will you use?']]], $this->emails);
    }

    public function test_an_email_failure_is_a_warning_not_an_error(): void
    {
        $this->emailResult = 'failed';

        $response = $this->ask('Which licence?');

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['email_failed'], $response->get_data()['warnings']);
    }

    public function test_locked_reviews_and_missing_addresses_refuse_before_saving(): void
    {
        $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
        $this->assertSame('review_locked', $this->ask('Q?')->get_error_code());

        $this->meta[40][CDCF_REVIEW_STAGE_META] = 'in_review';
        $this->meta[40]['_submission_submitter_email'] = '';
        $result = $this->ask('Q?');
        $this->assertSame('no_submitter_email', $result->get_error_code());
        $this->assertSame(409, $result->get_error_data()['status']);

        $this->assertSame([], $this->inserted);
        $this->assertSame([], $this->emails);
    }

    public function test_an_entry_that_cannot_be_read_back_returns_the_error(): void
    {
        $missing = new WP_Error('entry_missing', 'The entry was saved but could not be read back.', ['status' => 500]);
        Functions\when('cdcf_review_entry_response')->justReturn($missing);

        $this->assertSame($missing, $this->ask('Which licence?'));
    }

    public function test_empty_questions_are_refused(): void
    {
        $this->assertSame('empty_body', $this->ask("  \n ")->get_error_code());
        $this->assertSame([], $this->inserted);
    }
}
