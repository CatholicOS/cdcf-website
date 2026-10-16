<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewInboxHandlerTest extends TestCase
{
    private array $marked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->marked = [];
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('cdcf_review_unread_total')->justReturn(3);
        Functions\when('cdcf_review_inbox')->alias(
            static fn(int $uid, bool $unread, int $page) => ['items' => [['event_id' => 1, 'uid' => $uid, 'unread' => $unread, 'page' => $page]], 'has_more' => false]
        );
        Functions\when('cdcf_review_mark_read')->alias(function (int $uid, ?array $ids = null, ?int $pid = null): void {
            $this->marked[] = [$uid, $ids, $pid];
        });
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

    public function test_inbox_returns_the_page_and_the_unread_total(): void
    {
        $out = cdcf_rest_review_inbox($this->request(['unread' => true, 'page' => 2]));

        $this->assertSame([['event_id' => 1, 'uid' => 7, 'unread' => true, 'page' => 2]], $out['items']);
        $this->assertSame(2, $out['page']);
        $this->assertFalse($out['has_more']);
        $this->assertSame(3, $out['unread']);
    }

    public function test_count(): void
    {
        $this->assertSame(['unread' => 3], cdcf_rest_review_inbox_count($this->request([])));
    }

    public function test_read_some_or_all(): void
    {
        $this->assertSame(['unread' => 3], cdcf_rest_review_inbox_read($this->request(['event_ids' => [4, 5], 'all' => false])));
        cdcf_rest_review_inbox_read($this->request(['event_ids' => [], 'all' => true]));

        $this->assertSame([[7, [4, 5], null], [7, null, null]], $this->marked);
    }

    public function test_read_with_nothing_to_mark_is_a_400(): void
    {
        $result = cdcf_rest_review_inbox_read($this->request(['event_ids' => [], 'all' => false]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_input', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertSame([], $this->marked);
    }

    public function test_id_list_sanitizer_keeps_positive_integers(): void
    {
        $this->assertSame([4, 9], cdcf_review_sanitize_id_list([4, '9', 0, -2, 'x']));
        $this->assertSame([], cdcf_review_sanitize_id_list('nope'));
    }

    public function test_inbox_routes_declare_sanitized_args(): void
    {
        $args = cdcf_review_route_args();

        $this->assertSame('rest_sanitize_boolean', $args['/review/inbox']['unread']['sanitize_callback']);
        $this->assertSame('absint', $args['/review/inbox']['page']['sanitize_callback']);
        $this->assertSame([], $args['/review/inbox/count']);
        $this->assertSame('cdcf_review_sanitize_id_list', $args['/review/inbox/read']['event_ids']['sanitize_callback']);
        $this->assertSame(200, $args['/review/inbox/read']['event_ids']['maxItems']);
        $this->assertSame('rest_sanitize_boolean', $args['/review/inbox/read']['all']['sanitize_callback']);
    }
}
