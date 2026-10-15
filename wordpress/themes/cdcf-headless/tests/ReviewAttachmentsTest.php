<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/attachments.php. Validation runs against real
 * temp files so finfo sniffing is exercised for real.
 */
final class ReviewAttachmentsTest extends TestCase
{
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private FakeWpdb $wpdb;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->dir = sys_get_temp_dir() . '/cdcf-review-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        Functions\when('cdcf_review_configured_storage_dir')->justReturn('');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->dir]);
        Functions\when('wp_mkdir_p')->alias(static fn(string $p): bool => is_dir($p) || mkdir($p, 0775, true));
        Functions\when('untrailingslashit')->alias(static fn(string $s): string => rtrim($s, '/\\'));
        Functions\when('sanitize_title')->alias(static fn(string $s): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'));
        Functions\when('sanitize_file_name')->returnArg(1);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $this->rmrf($this->dir);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $f) {
                $this->rmrf("{$path}/{$f}");
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    private function tmp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cdcfatt');
        file_put_contents($path, $contents);
        return $path;
    }

    // ─── storage directory ───────────────────────────────────────────

    public function test_storage_dir_falls_back_to_uploads(): void
    {
        $this->assertSame(['path' => $this->dir . '/cdcf-review', 'fallback' => true], cdcf_review_storage_dir());
    }

    public function test_storage_dir_uses_configured_path(): void
    {
        Functions\when('cdcf_review_configured_storage_dir')->justReturn('/srv/cdcf-review/');

        $this->assertSame(['path' => '/srv/cdcf-review', 'fallback' => false], cdcf_review_storage_dir());
    }

    public function test_ensure_storage_dir_writes_deny_guards_in_fallback(): void
    {
        $path = cdcf_review_ensure_storage_dir();

        $this->assertSame($this->dir . '/cdcf-review', $path);
        $this->assertStringContainsString('Require all denied', (string) file_get_contents("{$path}/.htaccess"));
        $this->assertFileExists("{$path}/index.php");
    }

    // ─── validation ──────────────────────────────────────────────────

    public function test_png_is_accepted_with_sniffed_mime(): void
    {
        $meta = cdcf_review_validate_upload($this->tmp(base64_decode(self::PNG_1PX)), 'diagram.PNG');

        $this->assertSame(['kind' => 'image', 'mime' => 'image/png', 'size' => 68], $meta);
    }

    public function test_svg_renamed_to_png_is_a_type_mismatch(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $result = cdcf_review_validate_upload($this->tmp($svg), 'innocent.png');

        $this->assertSame('type_mismatch', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function test_svg_extension_is_unsupported(): void
    {
        $this->assertSame('unsupported_type', cdcf_review_validate_upload($this->tmp('<svg/>'), 'x.svg')->get_error_code());
    }

    public function test_markdown_is_accepted_and_normalized(): void
    {
        $meta = cdcf_review_validate_upload($this->tmp("# Notes\n\nCaffè ✓\n"), 'notes.md');

        $this->assertSame('markdown', $meta['kind']);
        $this->assertSame('text/markdown', $meta['mime']);
    }

    public function test_markdown_with_html_is_still_markdown(): void
    {
        $meta = cdcf_review_validate_upload($this->tmp("<div>hi</div>\n\n# Title\n"), 'notes.md');

        $this->assertSame('text/markdown', $meta['mime']);
    }

    public function test_markdown_must_be_utf8(): void
    {
        $this->assertSame('invalid_utf8', cdcf_review_validate_upload($this->tmp("caf\xE9 latin-1 text\n"), 'notes.md')->get_error_code());
    }

    public function test_binary_named_markdown_is_a_type_mismatch(): void
    {
        $this->assertSame('type_mismatch', cdcf_review_validate_upload($this->tmp(base64_decode(self::PNG_1PX)), 'notes.md')->get_error_code());
    }

    public function test_markdown_that_sniffs_as_json_or_svg_is_accepted(): void
    {
        foreach (["{\"votes\": [1, 2]}\n", "<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>\n"] as $text) {
            $meta = cdcf_review_validate_upload($this->tmp($text), 'notes.md');

            $this->assertIsArray($meta, $text);
            $this->assertSame('text/markdown', $meta['mime']);
        }
    }

    public function test_markdown_with_nul_bytes_is_a_type_mismatch(): void
    {
        $this->assertSame('type_mismatch', cdcf_review_validate_upload($this->tmp("# Notes\0\0\0binary"), 'notes.md')->get_error_code());
    }

    public function test_oversized_markdown_is_413(): void
    {
        $result = cdcf_review_validate_upload($this->tmp(str_repeat('a', 512 * 1024 + 1)), 'big.md');

        $this->assertSame('file_too_large', $result->get_error_code());
        $this->assertSame(413, $result->get_error_data()['status']);
    }

    public function test_empty_file_is_rejected(): void
    {
        $this->assertSame('empty_file', cdcf_review_validate_upload($this->tmp(''), 'empty.md')->get_error_code());
    }

    // ─── storing and rows ────────────────────────────────────────────

    public function test_store_file_moves_into_storage_under_random_key(): void
    {
        $source = $this->tmp('hello');

        $key = cdcf_review_store_file($source);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertFileDoesNotExist($source);
        $this->assertSame('hello', file_get_contents($this->dir . "/cdcf-review/{$key}"));
    }

    public function test_insert_attachment_row(): void
    {
        $id = cdcf_review_insert_attachment(9, ['kind' => 'image', 'mime' => 'image/png', 'size' => 70], 'diagram.png', str_repeat('a', 64));

        $this->assertSame(1, $id);
        $row = $this->wpdb->inserts[0];
        $this->assertSame('wp_cdcf_review_attachments', $row['table']);
        $this->assertSame(9, $row['data']['entry_id']);
        $this->assertSame('diagram.png', $row['data']['original_name']);
    }

    public function test_attachments_for_entries_group_by_entry(): void
    {
        $this->wpdb->results[] = [
            ['id' => '1', 'entry_id' => '9', 'kind' => 'image', 'mime' => 'image/png', 'original_name' => 'a.png', 'storage_key' => 'k1', 'size' => '70', 'created_at' => 'x'],
            ['id' => '2', 'entry_id' => '9', 'kind' => 'markdown', 'mime' => 'text/markdown', 'original_name' => 'b.md', 'storage_key' => 'k2', 'size' => '5', 'created_at' => 'x'],
            ['id' => '3', 'entry_id' => '10', 'kind' => 'image', 'mime' => 'image/gif', 'original_name' => 'c.gif', 'storage_key' => 'k3', 'size' => '9', 'created_at' => 'x'],
        ];

        $grouped = cdcf_review_attachments_for_entries([9, 10, 11]);

        $this->assertSame([1, 2], array_column($grouped[9], 'id'));
        $this->assertSame([3], array_column($grouped[10], 'id'));
        $this->assertArrayNotHasKey(11, $grouped);
        $this->assertStringContainsString('entry_id IN (9,10,11) ORDER BY id ASC', $this->wpdb->queries[0]);
    }

    public function test_attachments_for_no_entries_skip_the_query(): void
    {
        $this->assertSame([], cdcf_review_attachments_for_entries([]));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_get_and_count_attachments(): void
    {
        $this->wpdb->results[] = ['id' => '4', 'entry_id' => '9', 'kind' => 'image', 'mime' => 'image/png', 'original_name' => 'a.png', 'storage_key' => 'k', 'size' => '70', 'created_at' => 'x'];
        $this->wpdb->results[] = '3';

        $this->assertSame(4, cdcf_review_get_attachment(4)['id']);
        $this->assertSame(3, cdcf_review_count_attachments(9));
    }

    public function test_present_attachment_and_path(): void
    {
        $att = ['id' => 4, 'entry_id' => 9, 'kind' => 'image', 'mime' => 'image/png', 'original_name' => 'a.png', 'storage_key' => 'abc', 'size' => 70];

        $this->assertSame(['id' => 4, 'kind' => 'image', 'mime' => 'image/png', 'name' => 'a.png', 'size' => 70], cdcf_review_present_attachment($att));
        $this->assertSame($this->dir . '/cdcf-review/abc', cdcf_review_attachment_path($att));
    }

    public function test_markdown_filename_from_title(): void
    {
        $this->assertSame('license-concerns.md', cdcf_review_markdown_filename('License concerns!'));
        $this->assertSame('notes.md', cdcf_review_markdown_filename('  '));
    }
}
