<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the attachment endpoints and the raw-bytes serve filter in
 * includes/handlers/review.php.
 */
final class ReviewAttachmentHandlerTest extends TestCase
{
    /** @var list<array> */
    private array $events = [];
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->events = [];
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_post')->alias(static function (int $id) {
            $p = new stdClass();
            $p->ID = $id;
            $p->post_type = 'project';
            $p->post_status = 'pending';
            return $p;
        });
        Functions\when('get_post_meta')->justReturn('in_review');
        Functions\when('get_temp_dir')->justReturn(sys_get_temp_dir() . '/');
        Functions\when('sanitize_file_name')->returnArg(1);
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry());
        Functions\when('cdcf_review_count_attachments')->justReturn(0);
        Functions\when('cdcf_review_is_uploaded_file')->justReturn(true);
        Functions\when('cdcf_review_validate_upload')->justReturn(['kind' => 'image', 'mime' => 'image/png', 'size' => 70]);
        Functions\when('cdcf_review_store_file')->justReturn(str_repeat('a', 64));
        Functions\when('cdcf_review_insert_attachment')->justReturn(4);
        Functions\when('cdcf_review_markdown_filename')->alias(static fn(string $t): string => ($t ?: 'notes') . '.md');
        Functions\when('cdcf_review_record_event')->alias(function (...$args): int {
            $this->events[] = $args;
            return 1;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $f) {
            @unlink($f);
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    private function entry(array $o = []): array
    {
        return array_merge([
            'id' => 9, 'project_id' => 40, 'kind' => 'reply', 'parent_id' => 1, 'author_user_id' => 7,
            'review_slot' => null, 'body_md' => 'x', 'vote' => null,
            'created_at' => '2026-10-15 10:00:00.000000', 'edited_at' => null,
        ], $o);
    }

    private function request(array $params, array $files = []): WP_REST_Request
    {
        $r = new WP_REST_Request();
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        $r->set_file_params($files);
        return $r;
    }

    private function upload(int $error = UPLOAD_ERR_OK): array
    {
        return ['file' => ['name' => 'diagram.png', 'tmp_name' => '/tmp/php123', 'error' => $error, 'size' => 70]];
    }

    // ─── upload ──────────────────────────────────────────────────────

    public function test_author_uploads_a_file(): void
    {
        $validated = null;
        Functions\when('cdcf_review_validate_upload')->alias(function (string $path, string $name) use (&$validated) {
            $validated = [$path, $name];
            return ['kind' => 'image', 'mime' => 'image/png', 'size' => 70];
        });

        $res = cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload()));

        $this->assertSame(201, $res->get_status());
        $this->assertSame(['id' => 4, 'kind' => 'image', 'mime' => 'image/png', 'name' => 'diagram.png', 'size' => 70], $res->get_data());
        $this->assertSame(['/tmp/php123', 'diagram.png'], $validated);
        $this->assertSame([[40, 'attachment_added', 7, 9, ['attachment_id' => 4]]], $this->events);
    }

    public function test_pasted_markdown_becomes_a_file(): void
    {
        $stored = null;
        Functions\when('cdcf_review_validate_upload')->alias(function (string $path, string $name) use (&$stored) {
            $stored = [file_get_contents($path), $name];
            $this->cleanup[] = $path;
            return ['kind' => 'markdown', 'mime' => 'text/markdown', 'size' => 9];
        });

        $res = cdcf_rest_review_post_attachment($this->request(['id' => 9, 'title' => 'license', 'markdown' => '# License']));

        $this->assertSame(201, $res->get_status());
        $this->assertSame(['# License', 'license.md'], $stored);
        $this->assertSame('license.md', $res->get_data()['name']);
    }

    public function test_pasted_markdown_temp_write_failure_is_500(): void
    {
        Functions\when('file_put_contents')->justReturn(false);
        Functions\expect('cdcf_review_validate_upload')->never();

        $res = cdcf_rest_review_post_attachment($this->request(['id' => 9, 'title' => 'license', 'markdown' => '# License']));

        $this->assertSame('store_failed', $res->get_error_code());
        $this->assertSame(500, $res->get_error_data()['status']);
    }

    public function test_neither_file_nor_markdown_is_400(): void
    {
        $res = cdcf_rest_review_post_attachment($this->request(['id' => 9, 'title' => '', 'markdown' => '']));

        $this->assertSame('empty_file', $res->get_error_code());
    }

    public function test_php_size_limit_error_is_413(): void
    {
        $res = cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload(UPLOAD_ERR_INI_SIZE)));

        $this->assertSame('file_too_large', $res->get_error_code());
        $this->assertSame(413, $res->get_error_data()['status']);
    }

    public function test_non_uploaded_tmp_path_is_rejected(): void
    {
        Functions\when('cdcf_review_is_uploaded_file')->justReturn(false);
        Functions\expect('cdcf_review_store_file')->never();

        $this->assertSame('upload_failed', cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload()))->get_error_code());
    }

    public function test_only_the_entry_author_can_attach(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry(['author_user_id' => 8]));

        $res = cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload()));

        $this->assertSame('not_author', $res->get_error_code());
        $this->assertSame(403, $res->get_error_data()['status']);
    }

    public function test_attachments_are_locked_after_decision(): void
    {
        Functions\when('get_post_meta')->justReturn('decided');

        $this->assertSame('review_locked', cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload()))->get_error_code());
    }

    public function test_attachment_count_is_capped(): void
    {
        Functions\when('cdcf_review_count_attachments')->justReturn(10);

        $this->assertSame('too_many_attachments', cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload()))->get_error_code());
    }

    public function test_validation_error_is_returned_and_nothing_stored(): void
    {
        Functions\when('cdcf_review_validate_upload')->justReturn(new WP_Error('type_mismatch', 'no', ['status' => 400]));
        Functions\expect('cdcf_review_store_file')->never();

        $this->assertSame('type_mismatch', cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload()))->get_error_code());
    }

    public function test_unknown_entry_is_404(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn(null);

        $this->assertSame(404, cdcf_rest_review_post_attachment($this->request(['id' => 9], $this->upload()))->get_error_data()['status']);
    }

    // ─── download ────────────────────────────────────────────────────

    private function attachment(array $o = []): array
    {
        return array_merge(['id' => 4, 'entry_id' => 9, 'kind' => 'image', 'mime' => 'image/png', 'original_name' => 'dia gram.png', 'storage_key' => 'k', 'size' => 5], $o);
    }

    public function test_download_sets_safe_headers_and_file_marker(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cdcfdl');
        file_put_contents($file, 'bytes');
        $this->cleanup[] = $file;
        Functions\when('cdcf_review_get_attachment')->justReturn($this->attachment());
        Functions\when('cdcf_review_attachment_path')->justReturn($file);

        $res = cdcf_rest_review_get_attachment($this->request(['id' => 4, 'download' => false]));

        $headers = $res->get_headers();
        $this->assertSame('image/png', $headers['Content-Type']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame("default-src 'none'; sandbox", $headers['Content-Security-Policy']);
        $this->assertSame('private, no-store', $headers['Cache-Control']);
        $this->assertSame("inline; filename*=UTF-8''dia%20gram.png", $headers['Content-Disposition']);
        $this->assertSame('5', $headers['Content-Length']);
        $this->assertSame([CDCF_REVIEW_FILE_MARKER => $file], $res->get_data());
    }

    public function test_download_param_and_markdown_charset(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cdcfdl');
        file_put_contents($file, '# x');
        $this->cleanup[] = $file;
        Functions\when('cdcf_review_get_attachment')->justReturn($this->attachment(['kind' => 'markdown', 'mime' => 'text/markdown', 'original_name' => 'n.md']));
        Functions\when('cdcf_review_attachment_path')->justReturn($file);

        $headers = cdcf_rest_review_get_attachment($this->request(['id' => 4, 'download' => true]))->get_headers();

        $this->assertSame('text/markdown; charset=utf-8', $headers['Content-Type']);
        $this->assertStringStartsWith('attachment;', $headers['Content-Disposition']);
    }

    public function test_download_of_missing_file_or_unreviewable_project_is_404(): void
    {
        Functions\when('cdcf_review_get_attachment')->justReturn($this->attachment());
        Functions\when('cdcf_review_attachment_path')->justReturn('/nonexistent/cdcf');
        $this->assertSame('file_missing', cdcf_rest_review_get_attachment($this->request(['id' => 4]))->get_error_code());

        Functions\when('get_post')->justReturn(null);
        $this->assertSame(404, cdcf_rest_review_get_attachment($this->request(['id' => 4]))->get_error_data()['status']);

        Functions\when('cdcf_review_get_attachment')->justReturn(null);
        $this->assertSame('attachment_not_found', cdcf_rest_review_get_attachment($this->request(['id' => 4]))->get_error_code());
    }

    public function test_serve_filter_streams_marked_responses_only(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cdcfdl');
        file_put_contents($file, 'raw-bytes');
        $this->cleanup[] = $file;
        $marked = new WP_REST_Response([CDCF_REVIEW_FILE_MARKER => $file], 200);

        $route = new WP_REST_Request();
        $route->set_route('/cdcf/v1/review/attachments/4');
        $other = new WP_REST_Request();
        $other->set_route('/wp/v2/settings');

        ob_start();
        $served = cdcf_review_serve_attachment(false, $marked, $route, null);
        $out = ob_get_clean();

        $this->assertTrue($served);
        $this->assertSame('raw-bytes', $out);
        $this->assertFalse(cdcf_review_serve_attachment(false, new WP_REST_Response(['ok' => true]), $route, null));
        $this->assertTrue(cdcf_review_serve_attachment(true, $marked, $route, null));

        // A marker smuggled into any other route's data is never served.
        ob_start();
        $this->assertFalse(cdcf_review_serve_attachment(false, $marked, $other, null));
        $this->assertSame('', ob_get_clean());
    }

    // ─── route args ──────────────────────────────────────────────────

    public function test_attachment_route_args(): void
    {
        $args = cdcf_review_route_args();

        $this->assertSame('cdcf_review_sanitize_markdown', $args['/review/entries/(?P<id>\d+)/attachments']['markdown']['sanitize_callback']);
        $this->assertSame('rest_validate_request_arg', $args['/review/attachments/(?P<id>\d+)']['download']['validate_callback']);
    }
}
