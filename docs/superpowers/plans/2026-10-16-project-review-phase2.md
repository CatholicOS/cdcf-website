# Council Project Review — Phase 2 (review UI, Markdown, attachments) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give council and Board members the `/review` pages in the Next.js
frontend: a list of submissions and a workspace for one project where they
vote, write Markdown reviews, reply, discuss, and attach Markdown files or
images that open in a side panel.

**Architecture:** WordPress gains two attachment endpoints (multipart/JSON
upload, authenticated byte stream) backed by files stored outside the web
root. Next.js talks to every `/cdcf/v1/review/*` endpoint through a
server-only client (`lib/review-api.ts`) that forwards the user's Zitadel
bearer token; thin route handlers under `app/api/review/*` serve the client
components. Pages are Server Components; composers, attachment controls and
the side panel are client components. Pure logic lives in `lib/` and is unit
tested; React components follow the repo's existing practice (no component
tests) except the shared Markdown renderer, whose safety settings are tested
with `react-dom/server`.

**Tech Stack:** PHP 8.3 + PHPUnit/Brain Monkey (theme); Next.js 16 App
Router, React 19, next-intl 4, Auth.js v5, Tailwind v4, `react-markdown`
10 + `remark-gfm` 4 (new), Vitest 5.

**Spec:** `docs/superpowers/specs/2026-10-15-project-review-design.md`
(sections "REST endpoints", "Next.js", "Attachment storage"). Phase 1
shipped in PR #345: tables (including `cdcf_review_attachments`),
membership, and the `/review/{me,projects,projects/{id},…/my-review,…/entries,entries/{id}}`
endpoints. Entries carry a `version` token (not `edited_at`) and
`attachments: []`; `GET /review/projects` is paged (`page`, response
`{projects, page, has_more}`).

## Global Constraints

- Attachment types and limits: `.md` up to 512 KB (valid UTF-8, sniffed as
  `text/*`, stored with MIME `text/markdown`); PNG, JPEG, WebP, GIF up to
  5 MB (MIME sniffed with `finfo` must match the extension). SVG and every
  other type are rejected. At most 10 attachments per entry.
- Attachment files are stored under `CDCF_REVIEW_STORAGE_DIR` when defined,
  otherwise `wp-content/uploads/cdcf-review/` guarded by a deny-all
  `.htaccess` and an empty `index.php`; file names are 64-hex random keys.
- `GET /review/attachments/{id}` responds with `X-Content-Type-Options:
nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`,
  `Cache-Control: private, no-store`, and `Content-Disposition` `inline`
  (default) or `attachment` (`?download=1`).
- Uploading requires being the entry's author, `discuss` permission, and the
  review not `decided`. Viewing requires `view`.
- Markdown is rendered only through `components/review/MarkdownView.tsx`:
  `remark-gfm`, `skipHtml`, links open in a new tab with
  `rel="noopener noreferrer nofollow"`, Markdown images render as links
  (never `<img>`), react-markdown's default URL sanitizer stays on.
- The bearer token never reaches client JavaScript: client components call
  `/api/review/*` (Auth.js cookie), route handlers call WordPress.
- All REST route args declare `sanitize_callback`; args with `enum`, `type`
  checks, or `minimum` also set `validate_callback => rest_validate_request_arg`.
- New PHP include files use the one-line `defined('ABSPATH') || exit;` guard.
- UI strings live under the `Review` namespace (plus `Auth.projectReview`)
  in all six `messages/*.json` files; reviewer-written content is never
  translated.
- Use `Link`, `useRouter`, `usePathname` from `@/src/i18n/navigation`, not
  `next/link`/`next/navigation` (except `useSearchParams`, `notFound`,
  `redirect` from `next/navigation`).
- No synchronous `setState` inside `useEffect` (eslint-config-next 16 ships
  `react-hooks/set-state-in-effect`); reset per-item state by `key`.
- Commit messages end with
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and
  `Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k`.

## Review Focus

1. **Expired session mid-edit** (Auth.js refresh failed, or WP rejects the
   token): a reviewer pressing "Post review" expects "your session has
   expired, sign in again", not a generic failure. Tests in Task 6
   (`reviewErrorKey` maps `no_token`/`rest_not_logged_in`/401 to
   `sessionExpired`) and Task 4 (`no_token` thrown before any fetch).
2. **Upload rejected by a proxy before WordPress** (nginx 413 HTML page,
   not JSON): the reviewer expects "file too large", not "something went
   wrong". Tests in Task 4 (413 non-JSON → `file_too_large`) and Task 6.
3. **Hostile Markdown in a review or `.md` attachment** (`<script>`, raw
   HTML, `javascript:` links, tracking-pixel images): nothing executes and
   nothing is fetched off-site. Tests in Task 3.
4. **SVG or HTML disguised with an image extension**: rejected at upload,
   never streamed back as an image. Test in Task 1 (`type_mismatch`) and
   Task 2 (nosniff/CSP headers on every stream).
5. **Acting on a review decided in another tab**: composers get
   `403 review_locked`; the reviewer expects "this review has been decided".
   Test in Task 6 (`review_locked` → `locked`).

---

## File structure

| File                                                                                    | Responsibility                                                                                                                                                                                                                                                       |
| --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `wordpress/themes/cdcf-headless/includes/review/attachments.php` (create)               | Storage dir, validation, file store, attachment rows, presentation                                                                                                                                                                                                   |
| `wordpress/themes/cdcf-headless/includes/handlers/review.php` (modify)                  | Upload + stream handlers, serve filter, attachments in entry payloads, `user_id` in `/review/me`, route args                                                                                                                                                         |
| `wordpress/themes/cdcf-headless/includes/admin/review-access.php` (modify)              | Warning while the storage fallback is in use                                                                                                                                                                                                                         |
| `wordpress/themes/cdcf-headless/functions.php` (modify)                                 | Require, routes, `rest_pre_serve_request` filter                                                                                                                                                                                                                     |
| `wordpress/themes/cdcf-headless/tests/*`                                                | `ReviewAttachmentsTest`, `ReviewAttachmentHandlerTest`, updates to existing review tests, bootstrap file params                                                                                                                                                      |
| `nginx/default.conf`, `wordpress/php/uploads.ini`, `docker-compose.yml` (modify/create) | Local stack: 8 MB bodies, deny fallback dir, PHP upload limits                                                                                                                                                                                                       |
| `lib/review-types.ts` (create)                                                          | Shared types and limits (client-safe)                                                                                                                                                                                                                                |
| `lib/review-api.ts` (create)                                                            | Server-only WP client                                                                                                                                                                                                                                                |
| `lib/review-route.ts` (create)                                                          | Route-handler helpers (ids, JSON bodies, error mapping, attachment passthrough)                                                                                                                                                                                      |
| `lib/review-ui.ts` (create)                                                             | Pure UI helpers (error keys, URL param, tally, dates, sizes, attachment index)                                                                                                                                                                                       |
| `lib/review-client.ts` (create)                                                         | Browser fetch wrapper and attachment upload                                                                                                                                                                                                                          |
| `app/api/review/**/route.ts` (create, 8 files)                                          | Thin proxies                                                                                                                                                                                                                                                         |
| `components/review/*.tsx` (create)                                                      | MarkdownView, TallyBar, ProjectCard, ReviewNotice, SubmissionCard, EntryCard, ReviewCard, QuestionsList, MarkdownEditor, AttachControl, PendingList, ComposerStatus, useEntrySubmit, EntryComposer, MyReviewComposer, EntryActions, AttachmentChips, AttachmentPanel |
| `app/[lang]/review/page.tsx`, `app/[lang]/review/[id]/page.tsx` (create)                | Pages                                                                                                                                                                                                                                                                |
| `components/AuthButton.tsx` (modify)                                                    | "Project review" menu link                                                                                                                                                                                                                                           |
| `messages/{en,it,es,fr,pt,de}.json` (modify)                                            | `Review` namespace, `Auth.projectReview`                                                                                                                                                                                                                             |
| `vitest.config.ts`, `package.json` (modify)                                             | `.tsx` tests under `components/`, new deps                                                                                                                                                                                                                           |
| `AGENTS.md`, spec (modify)                                                              | Endpoint rows, refinements                                                                                                                                                                                                                                           |

Theme commands run from `wordpress/themes/cdcf-headless/`; Next.js commands
from the repo root.

---

### Task 1: Attachment storage and validation (WordPress)

**Files:**

- Create: `wordpress/themes/cdcf-headless/includes/review/attachments.php`
- Modify: `wordpress/themes/cdcf-headless/tests/bootstrap.php`
- Test: `wordpress/themes/cdcf-headless/tests/ReviewAttachmentsTest.php`

**Interfaces:**

- Consumes: `cdcf_review_table()`, `cdcf_review_now()` (Phase 1 `schema.php`); `FakeWpdb`.
- Produces:
  - `CDCF_REVIEW_ATTACHMENT_TYPES` (ext ⇒ `{kind, mime, max}`), `CDCF_REVIEW_ATTACHMENTS_PER_ENTRY = 10`
  - `cdcf_review_configured_storage_dir(): string` — the constant or `''`
  - `cdcf_review_storage_dir(): array{path: string, fallback: bool}`
  - `cdcf_review_ensure_storage_dir(): string|WP_Error`
  - `cdcf_review_validate_upload(string $path, string $original_name): array{kind: string, mime: string, size: int}|WP_Error` — codes `unsupported_type`, `empty_file`, `file_too_large` (413), `type_mismatch`, `invalid_utf8` (400 otherwise)
  - `cdcf_review_store_file(string $source_path): string|WP_Error` — returns storage key
  - `cdcf_review_is_uploaded_file(string $path): bool` (wraps `is_uploaded_file`)
  - `cdcf_review_insert_attachment(int $entry_id, array $meta, string $original_name, string $storage_key): int`
  - `cdcf_review_get_attachment(int $id): ?array`, `cdcf_review_count_attachments(int $entry_id): int`
  - `cdcf_review_attachments_for_entries(array $entry_ids): array<int, list<array>>`
  - `cdcf_review_attachment_path(array $attachment): string`
  - `cdcf_review_present_attachment(array $attachment): array{id: int, kind: string, mime: string, name: string, size: int}`
  - `cdcf_review_markdown_filename(string $title): string`
  - bootstrap `WP_REST_Request::set_file_params()/get_file_params()`

- [ ] **Step 1: Give the test request class file params**

In `tests/bootstrap.php`, inside `class WP_REST_Request implements ArrayAccess`, after `private array $params = [];` add:

```php
        private array $files = [];

        public function set_file_params(array $files): void {
            $this->files = $files;
        }

        public function get_file_params(): array {
            return $this->files;
        }
```

After the `require_once __DIR__ . '/../includes/review/events.php';` line add:

```php
require_once __DIR__ . '/../includes/review/attachments.php';
```

- [ ] **Step 2: Write the failing tests**

`tests/ReviewAttachmentsTest.php`:

```php
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
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ReviewAttachmentsTest`
Expected: FAIL — `Failed to open stream` for `includes/review/attachments.php`.

- [ ] **Step 4: Implement `includes/review/attachments.php`**

```php
<?php
/**
 * Council review — attachment files.
 *
 * Files never go through the Media Library: anything under
 * wp-content/uploads is publicly fetchable by URL. They are stored under
 * CDCF_REVIEW_STORAGE_DIR (outside the web root) when wp-config.php
 * defines it, otherwise under uploads/cdcf-review/ behind deny-all guard
 * files (production must also deny that path at the web server). Files
 * are named by a random 64-hex key and only ever served through the
 * authenticated GET /cdcf/v1/review/attachments/{id}.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_ATTACHMENT_TYPES = [
    'md'   => ['kind' => 'markdown', 'mime' => 'text/markdown', 'max' => 524288],
    'png'  => ['kind' => 'image', 'mime' => 'image/png', 'max' => 5242880],
    'jpg'  => ['kind' => 'image', 'mime' => 'image/jpeg', 'max' => 5242880],
    'jpeg' => ['kind' => 'image', 'mime' => 'image/jpeg', 'max' => 5242880],
    'webp' => ['kind' => 'image', 'mime' => 'image/webp', 'max' => 5242880],
    'gif'  => ['kind' => 'image', 'mime' => 'image/gif', 'max' => 5242880],
];
const CDCF_REVIEW_ATTACHMENTS_PER_ENTRY = 10;

function cdcf_review_configured_storage_dir(): string {
    return defined('CDCF_REVIEW_STORAGE_DIR') ? (string) CDCF_REVIEW_STORAGE_DIR : '';
}

/** @return array{path: string, fallback: bool} */
function cdcf_review_storage_dir(): array {
    $configured = cdcf_review_configured_storage_dir();
    if ($configured !== '') {
        return ['path' => untrailingslashit($configured), 'fallback' => false];
    }
    $uploads = wp_upload_dir(null, false);
    return ['path' => untrailingslashit($uploads['basedir']) . '/cdcf-review', 'fallback' => true];
}

/** @return string|WP_Error The storage directory, created if missing. */
function cdcf_review_ensure_storage_dir() {
    $dir = cdcf_review_storage_dir();
    if (!wp_mkdir_p($dir['path'])) {
        return new WP_Error('storage_unavailable', 'The attachment storage directory cannot be created.', ['status' => 500]);
    }
    if ($dir['fallback']) {
        if (!file_exists($dir['path'] . '/.htaccess')) {
            file_put_contents($dir['path'] . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($dir['path'] . '/index.php')) {
            file_put_contents($dir['path'] . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }
    return $dir['path'];
}

/** @return array{kind: string, mime: string, size: int}|WP_Error */
function cdcf_review_validate_upload(string $path, string $original_name) {
    $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $type = CDCF_REVIEW_ATTACHMENT_TYPES[$ext] ?? null;
    if ($type === null) {
        return new WP_Error('unsupported_type', 'Only Markdown (.md) and PNG, JPEG, WebP or GIF images can be attached.', ['status' => 400]);
    }
    $size = (int) @filesize($path);
    if ($size <= 0) {
        return new WP_Error('empty_file', 'The file is empty.', ['status' => 400]);
    }
    if ($size > $type['max']) {
        return new WP_Error('file_too_large', 'The file is too large.', ['status' => 413]);
    }
    $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($type['kind'] === 'markdown') {
        if (!str_starts_with($sniffed, 'text/')) {
            return new WP_Error('type_mismatch', 'The file content does not match its extension.', ['status' => 400]);
        }
        if (!mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
            return new WP_Error('invalid_utf8', 'Markdown files must be UTF-8 text.', ['status' => 400]);
        }
    } elseif ($sniffed !== $type['mime']) {
        return new WP_Error('type_mismatch', 'The file content does not match its extension.', ['status' => 400]);
    }
    return ['kind' => $type['kind'], 'mime' => $type['mime'], 'size' => $size];
}

function cdcf_review_is_uploaded_file(string $path): bool {
    return is_uploaded_file($path);
}

/** @return string|WP_Error The new storage key. */
function cdcf_review_store_file(string $source_path) {
    $dir = cdcf_review_ensure_storage_dir();
    if (is_wp_error($dir)) {
        return $dir;
    }
    $key  = bin2hex(random_bytes(32));
    $dest = "{$dir}/{$key}";
    if (!@rename($source_path, $dest)) {
        if (!@copy($source_path, $dest)) {
            return new WP_Error('store_failed', 'The attachment could not be saved.', ['status' => 500]);
        }
        @unlink($source_path);
    }
    @chmod($dest, 0640);
    return $key;
}

function cdcf_review_normalize_attachment(array $row): array {
    foreach (['id', 'entry_id', 'size'] as $col) {
        $row[$col] = (int) $row[$col];
    }
    return $row;
}

function cdcf_review_insert_attachment(int $entry_id, array $meta, string $original_name, string $storage_key): int {
    global $wpdb;
    $ok = $wpdb->insert(cdcf_review_table('attachments'), [
        'entry_id'      => $entry_id,
        'kind'          => $meta['kind'],
        'mime'          => $meta['mime'],
        'original_name' => $original_name,
        'storage_key'   => $storage_key,
        'size'          => (int) $meta['size'],
        'created_at'    => cdcf_review_now(),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}

function cdcf_review_get_attachment(int $id): ?array {
    global $wpdb;
    $table = cdcf_review_table('attachments');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_attachment($row) : null;
}

function cdcf_review_count_attachments(int $entry_id): int {
    global $wpdb;
    $table = cdcf_review_table('attachments');
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entry_id = %d", $entry_id));
}

/** @return array<int, list<array>> entry id => attachment rows, oldest first */
function cdcf_review_attachments_for_entries(array $entry_ids): array {
    if (!$entry_ids) {
        return [];
    }
    global $wpdb;
    $table = cdcf_review_table('attachments');
    $rows = $wpdb->get_results(
        "SELECT * FROM {$table} WHERE entry_id IN (" . cdcf_review_id_list($entry_ids) . ') ORDER BY id ASC',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $row = cdcf_review_normalize_attachment($row);
        $out[$row['entry_id']][] = $row;
    }
    return $out;
}

function cdcf_review_attachment_path(array $attachment): string {
    return cdcf_review_storage_dir()['path'] . '/' . $attachment['storage_key'];
}

/** @return array{id: int, kind: string, mime: string, name: string, size: int} */
function cdcf_review_present_attachment(array $attachment): array {
    return [
        'id'   => (int) $attachment['id'],
        'kind' => (string) $attachment['kind'],
        'mime' => (string) $attachment['mime'],
        'name' => (string) $attachment['original_name'],
        'size' => (int) $attachment['size'],
    ];
}

function cdcf_review_markdown_filename(string $title): string {
    $slug = sanitize_title($title);
    return ($slug !== '' ? $slug : 'notes') . '.md';
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ReviewAttachmentsTest`
Expected: PASS (19 tests). If `finfo` reports a different MIME for the
`<div>` Markdown case on this PHP build, it is still `text/*` — the test only
asserts the stored MIME.

- [ ] **Step 6: Commit**

```bash
git add wordpress/themes/cdcf-headless/includes/review/attachments.php wordpress/themes/cdcf-headless/tests/bootstrap.php wordpress/themes/cdcf-headless/tests/ReviewAttachmentsTest.php
git commit -m "feat(review): attachment storage and validation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 2: Attachment endpoints, attachments in payloads, `user_id` in `/review/me` (WordPress)

**Files:**

- Modify: `wordpress/themes/cdcf-headless/includes/handlers/review.php`
- Modify: `wordpress/themes/cdcf-headless/includes/admin/review-access.php`
- Modify: `wordpress/themes/cdcf-headless/functions.php` (review block, ~line 802)
- Test: `wordpress/themes/cdcf-headless/tests/ReviewAttachmentHandlerTest.php`; update `ReviewReadHandlerTest.php`, `ReviewWriteHandlerTest.php`, `ReviewAccessScreenTest.php`

**Interfaces:**

- Consumes: Task 1 functions; Phase 1 `cdcf_review_get_entry`, `cdcf_review_get_reviewable_project`, `cdcf_review_assert_open`, `cdcf_review_record_event`, `cdcf_review_route_args`.
- Produces:
  - `cdcf_review_present_entry(array $row, array $attachments = []): array` — `attachments` now presented rows
  - `/review/me` response gains `user_id: int`
  - `cdcf_rest_review_post_attachment(WP_REST_Request): WP_REST_Response|WP_Error` (201, presented attachment; records `attachment_added` with data `{attachment_id}`)
  - `cdcf_rest_review_get_attachment(WP_REST_Request): WP_REST_Response|WP_Error` — response data `[CDCF_REVIEW_FILE_MARKER => path]` plus headers
  - `CDCF_REVIEW_FILE_MARKER = '__cdcf_review_file'`
  - `cdcf_review_serve_attachment($served, $result, $request, $server): bool` — `rest_pre_serve_request` filter
  - route args for `/review/entries/(?P<id>\d+)/attachments` (`id`, `title`, `markdown`) and `/review/attachments/(?P<id>\d+)` (`id`, `download`)

- [ ] **Step 1: Write the failing handler tests**

`tests/ReviewAttachmentHandlerTest.php`:

```php
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

        ob_start();
        $served = cdcf_review_serve_attachment(false, $marked, null, null);
        $out = ob_get_clean();

        $this->assertTrue($served);
        $this->assertSame('raw-bytes', $out);
        $this->assertFalse(cdcf_review_serve_attachment(false, new WP_REST_Response(['ok' => true]), null, null));
        $this->assertTrue(cdcf_review_serve_attachment(true, $marked, null, null));
    }

    // ─── route args ──────────────────────────────────────────────────

    public function test_attachment_route_args(): void
    {
        $args = cdcf_review_route_args();

        $this->assertSame('cdcf_review_sanitize_markdown', $args['/review/entries/(?P<id>\d+)/attachments']['markdown']['sanitize_callback']);
        $this->assertSame('rest_validate_request_arg', $args['/review/attachments/(?P<id>\d+)']['download']['validate_callback']);
    }
}
```

`WP_REST_Response` in the test bootstrap has no headers; add to it (in
`tests/bootstrap.php`, inside `class WP_REST_Response`):

```php
        private array $headers = [];

        public function header(string $key, string $value): void {
            $this->headers[$key] = $value;
        }

        public function get_headers(): array {
            return $this->headers;
        }

        public function set_data(mixed $data): void {
            $this->data = $data;
        }
```

- [ ] **Step 2: Update the existing review tests for the new payload shape**

In `tests/ReviewReadHandlerTest.php` `setUp()` add:

```php
        Functions\when('cdcf_review_attachments_for_entries')->justReturn([]);
```

and in `test_me_reports_flags_for_members_and_non_members`, after
`$this->assertTrue($me['member']);` add:

```php
        $this->assertSame(7, $me['user_id']);
```

Then add this test to `ReviewReadHandlerTest`:

```php
    public function test_detail_presents_attachments_per_entry(): void
    {
        $this->signIn(7);
        Functions\when('cdcf_review_project_entries')->justReturn([$this->entry(['id' => 1])]);
        Functions\when('cdcf_review_tallies')->justReturn([]);
        Functions\when('cdcf_review_find_review')->justReturn(null);
        $asked = null;
        Functions\when('cdcf_review_attachments_for_entries')->alias(function (array $ids) use (&$asked): array {
            $asked = $ids;
            return [1 => [['id' => 4, 'entry_id' => 1, 'kind' => 'image', 'mime' => 'image/png', 'original_name' => 'a.png', 'storage_key' => 'k', 'size' => 70]]];
        });

        $out = cdcf_rest_review_get_project($this->request(['id' => 40]));

        $this->assertSame([1], $asked);
        $this->assertSame([['id' => 4, 'kind' => 'image', 'mime' => 'image/png', 'name' => 'a.png', 'size' => 70]], $out['reviews'][0]['attachments']);
    }
```

In `tests/ReviewWriteHandlerTest.php` `setUp()` add:

```php
        Functions\when('cdcf_review_attachments_for_entries')->justReturn([]);
```

In `tests/ReviewAccessScreenTest.php` `setUp()` add:

```php
        Functions\when('cdcf_review_storage_dir')->justReturn(['path' => '/srv/x', 'fallback' => false]);
```

and add:

```php
    public function test_render_warns_while_storage_fallback_is_used(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('cdcf_review_roster')->justReturn([]);
        Functions\when('cdcf_review_storage_dir')->justReturn(['path' => '/var/www/wp-content/uploads/cdcf-review', 'fallback' => true]);

        ob_start();
        cdcf_review_access_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('CDCF_REVIEW_STORAGE_DIR', $html);
        $this->assertStringContainsString('notice-warning', $html);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'Review'`
Expected: FAIL — `Call to undefined function cdcf_rest_review_post_attachment()`
plus the new assertions in the read/screen tests.

- [ ] **Step 4: Implement the handler changes in `includes/handlers/review.php`**

4a. Replace the `cdcf_review_present_entry` function with:

```php
function cdcf_review_present_entry(array $row, array $attachments = []): array {
    $author = null;
    if ($row['author_user_id'] !== null) {
        $uid    = (int) $row['author_user_id'];
        $user   = get_userdata($uid);
        $author = [
            'id'       => $uid,
            'name'     => $user ? (string) $user->display_name : 'Former member',
            'councils' => array_keys(array_filter(cdcf_review_membership($uid))),
        ];
    }
    return [
        'id'          => (int) $row['id'],
        'kind'        => (string) $row['kind'],
        'parent_id'   => $row['parent_id'],
        'body_md'     => (string) $row['body_md'],
        'vote'        => $row['vote'],
        'author'      => $author,
        'created_at'  => (string) $row['created_at'],
        'edited_at'   => $row['edited_at'],
        'version'     => cdcf_review_entry_version($row),
        'attachments' => array_map('cdcf_review_present_attachment', $attachments),
    ];
}
```

4b. In `cdcf_rest_review_me`, change the response array to:

```php
    return rest_ensure_response([
        'user_id'  => $uid,
        'member'   => $can['view'],
        'councils' => array_keys(array_filter($m)),
        'can'      => $can,
    ]);
```

4c. In `cdcf_rest_review_get_project`, replace

```php
    $reviews = $questions = $discussion = $replies = $answers = [];
    foreach (cdcf_review_project_entries($pid) as $row) {
        $entry = cdcf_review_present_entry($row);
```

with

```php
    $rows        = cdcf_review_project_entries($pid);
    $attachments = cdcf_review_attachments_for_entries(array_column($rows, 'id'));

    $reviews = $questions = $discussion = $replies = $answers = [];
    foreach ($rows as $row) {
        $entry = cdcf_review_present_entry($row, $attachments[$row['id']] ?? []);
```

4d. In `cdcf_review_entry_response`, change the return line to:

```php
    $attachments = cdcf_review_attachments_for_entries([$id])[$id] ?? [];
    return new WP_REST_Response(cdcf_review_present_entry($entry, $attachments), $status);
```

4e. Append the attachment handlers and serve filter:

```php
const CDCF_REVIEW_FILE_MARKER = '__cdcf_review_file';

/**
 * POST /review/entries/{id}/attachments — a multipart `file`, or JSON
 * `{title, markdown}` for pasted Markdown. Only the entry's author may
 * attach, and only while the review is open.
 */
function cdcf_rest_review_post_attachment(WP_REST_Request $request) {
    $entry = cdcf_review_get_entry((int) $request['id']);
    if ($entry === null) {
        return new WP_Error('entry_not_found', 'No such entry.', ['status' => 404]);
    }
    if ($entry['author_user_id'] !== get_current_user_id()) {
        return new WP_Error('not_author', 'You can only attach files to your own posts.', ['status' => 403]);
    }
    foreach ([cdcf_review_get_reviewable_project($entry['project_id']), cdcf_review_assert_open($entry['project_id'])] as $check) {
        if (is_wp_error($check)) {
            return $check;
        }
    }
    if (cdcf_review_count_attachments($entry['id']) >= CDCF_REVIEW_ATTACHMENTS_PER_ENTRY) {
        return new WP_Error('too_many_attachments', 'This post already has the maximum number of attachments.', ['status' => 400]);
    }

    $files = $request->get_file_params();
    if (!empty($files['file'])) {
        $file = $files['file'];
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return new WP_Error('file_too_large', 'The file is too large.', ['status' => 413]);
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !cdcf_review_is_uploaded_file((string) $file['tmp_name'])) {
            return new WP_Error('upload_failed', 'The upload did not complete.', ['status' => 400]);
        }
        $source = (string) $file['tmp_name'];
        $name   = sanitize_file_name((string) $file['name']);
    } else {
        $markdown = (string) $request['markdown'];
        if ($markdown === '') {
            return new WP_Error('empty_file', 'Paste some Markdown or choose a file.', ['status' => 400]);
        }
        $source = tempnam(get_temp_dir(), 'cdcfmd');
        file_put_contents($source, $markdown);
        $name = cdcf_review_markdown_filename((string) $request['title']);
    }

    $meta = cdcf_review_validate_upload($source, $name);
    if (is_wp_error($meta)) {
        @unlink($source);
        return $meta;
    }
    $key = cdcf_review_store_file($source);
    if (is_wp_error($key)) {
        return $key;
    }
    $id = cdcf_review_insert_attachment($entry['id'], $meta, $name, $key);
    if ($id <= 0) {
        @unlink(cdcf_review_attachment_path(['storage_key' => $key]));
        return new WP_Error('insert_failed', 'The attachment could not be saved.', ['status' => 500]);
    }
    cdcf_review_record_event($entry['project_id'], 'attachment_added', get_current_user_id(), $entry['id'], ['attachment_id' => $id]);
    return new WP_REST_Response(cdcf_review_present_attachment([
        'id' => $id, 'kind' => $meta['kind'], 'mime' => $meta['mime'], 'original_name' => $name, 'size' => $meta['size'],
    ]), 201);
}

/**
 * GET /review/attachments/{id}[?download=1] — the file bytes. The REST
 * response carries headers plus a marker; cdcf_review_serve_attachment()
 * (rest_pre_serve_request) writes the bytes instead of JSON.
 */
function cdcf_rest_review_get_attachment(WP_REST_Request $request) {
    $attachment = cdcf_review_get_attachment((int) $request['id']);
    if ($attachment === null) {
        return new WP_Error('attachment_not_found', 'No such attachment.', ['status' => 404]);
    }
    $entry   = cdcf_review_get_entry($attachment['entry_id']);
    $project = $entry ? cdcf_review_get_reviewable_project($entry['project_id']) : null;
    if ($entry === null || is_wp_error($project)) {
        return new WP_Error('attachment_not_found', 'No such attachment.', ['status' => 404]);
    }
    $path = cdcf_review_attachment_path($attachment);
    if (!is_readable($path)) {
        return new WP_Error('file_missing', 'The attachment file is missing.', ['status' => 404]);
    }
    $disposition = !empty($request['download']) ? 'attachment' : 'inline';
    $response = new WP_REST_Response(null, 200);
    $response->set_data([CDCF_REVIEW_FILE_MARKER => $path]);
    $response->header('Content-Type', $attachment['mime'] . ($attachment['kind'] === 'markdown' ? '; charset=utf-8' : ''));
    $response->header('Content-Length', (string) filesize($path));
    $response->header('Content-Disposition', $disposition . "; filename*=UTF-8''" . rawurlencode($attachment['original_name']));
    $response->header('X-Content-Type-Options', 'nosniff');
    $response->header('Content-Security-Policy', "default-src 'none'; sandbox");
    $response->header('Cache-Control', 'private, no-store');
    return $response;
}

/** rest_pre_serve_request: write raw bytes for marked attachment responses. */
function cdcf_review_serve_attachment($served, $result, $request, $server): bool {
    if ($served) {
        return true;
    }
    if (!($result instanceof WP_REST_Response)) {
        return false;
    }
    $data = $result->get_data();
    if (!is_array($data) || !isset($data[CDCF_REVIEW_FILE_MARKER])) {
        return false;
    }
    readfile($data[CDCF_REVIEW_FILE_MARKER]);
    return true;
}
```

4f. In `cdcf_review_route_args()`, add two entries to the returned array
(after `'/review/entries/(?P<id>\d+)' => [...]`):

```php
        '/review/entries/(?P<id>\d+)/attachments' => [
            'id'       => $id,
            'title'    => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ],
            'markdown' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'cdcf_review_sanitize_markdown',
                'default'           => '',
            ],
        ],
        '/review/attachments/(?P<id>\d+)' => [
            'id'       => $id,
            'download' => [
                'required'          => false,
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ],
```

- [ ] **Step 5: Warn about the storage fallback in `includes/admin/review-access.php`**

In `cdcf_review_access_render()`, directly after the first `echo` (the
`<div class="wrap"><h1>` line) add:

```php
    if (cdcf_review_storage_dir()['fallback']) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html('Review attachments are stored under wp-content/uploads/cdcf-review/. Define CDCF_REVIEW_STORAGE_DIR in wp-config.php to keep them outside the web root, or make sure the web server denies that path.')
            . '</p></div>';
    }
```

- [ ] **Step 6: Register in `functions.php`**

Add after `require_once __DIR__ . '/includes/review/events.php';`:

```php
require_once __DIR__ . '/includes/review/attachments.php';
```

Add after `add_action('admin_menu', 'cdcf_review_access_admin_menu', 20);`:

```php
add_filter('rest_pre_serve_request', 'cdcf_review_serve_attachment', 10, 4);
```

In the `$routes` table inside the review `rest_api_init` closure add:

```php
        '/review/entries/(?P<id>\d+)/attachments' => ['POST', 'cdcf_rest_review_post_attachment', 'cdcf_rest_review_permission_discuss'],
        '/review/attachments/(?P<id>\d+)'         => ['GET', 'cdcf_rest_review_get_attachment', 'cdcf_rest_review_permission_view'],
```

Also add the two new paths to the header comment of `includes/handlers/review.php`:

```php
 *   POST  /cdcf/v1/review/entries/{id}/attachments upload (multipart or pasted Markdown)
 *   GET   /cdcf/v1/review/attachments/{id}       stream an attachment
```

- [ ] **Step 7: Run tests and lint**

Run: `vendor/bin/phpunit --filter 'Review' && composer test && php -l functions.php`
Expected: PASS everywhere; `No syntax errors detected`.

- [ ] **Step 8: Commit**

```bash
git add wordpress/themes/cdcf-headless
git commit -m "feat(review): attachment upload and stream endpoints

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 3: Local stack limits, Markdown dependencies, and the shared renderer

**Files:**

- Modify: `nginx/default.conf`, `docker-compose.yml`; create `wordpress/php/uploads.ini`
- Modify: `package.json`, `package-lock.json` (via npm), `vitest.config.ts`
- Create: `components/review/MarkdownView.tsx`
- Test: `components/review/MarkdownView.test.tsx`

**Interfaces:**

- Produces: `MarkdownView({ source: string; className?: string }): JSX.Element` (no `'use client'`, usable from server and client components)

- [ ] **Step 1: Local stack upload limits**

In `nginx/default.conf`, inside the `server {` block directly after
`server_name _;` add:

```nginx
    # Review attachments: up to 5 MB images plus multipart overhead.
    client_max_body_size 8m;

    # Review attachments fall back to this directory when
    # CDCF_REVIEW_STORAGE_DIR is unset; never serve it.
    location ^~ /wp-content/uploads/cdcf-review/ {
        return 404;
    }
```

Create `wordpress/php/uploads.ini`:

```ini
; Council review attachments: images up to 5 MB.
upload_max_filesize = 6M
post_max_size = 8M
```

In `docker-compose.yml`, in the `wordpress:` service `volumes:` list, add:

```yaml
- ./wordpress/php/uploads.ini:/usr/local/etc/php/conf.d/cdcf-uploads.ini:ro
```

- [ ] **Step 2: Install the Markdown libraries**

Run: `npm install react-markdown@^10.1.0 remark-gfm@^4.0.1`
Expected: both added to `dependencies` in `package.json`.

- [ ] **Step 3: Let Vitest run component tests**

In `vitest.config.ts`, replace `include: ['lib/**/*.test.ts'],` with:

```ts
    include: ['lib/**/*.test.ts', 'components/**/*.test.tsx'],
```

- [ ] **Step 4: Write the failing test**

`components/review/MarkdownView.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { MarkdownView } from "./MarkdownView";

const render = (source: string) =>
  renderToStaticMarkup(<MarkdownView source={source} />);

describe("MarkdownView", () => {
  it("renders GitHub-flavoured Markdown", () => {
    const html = render(
      "# Title\n\n- [x] done\n\n| a | b |\n| - | - |\n| 1 | 2 |",
    );
    expect(html).toContain("<h1>Title</h1>");
    expect(html).toContain('type="checkbox"');
    expect(html).toContain("<table>");
  });

  it("never renders raw HTML", () => {
    const html = render(
      '<script>alert(1)</script>\n\n<b onclick="x()">bold</b> text',
    );
    expect(html).not.toContain("<script");
    expect(html).not.toContain("onclick");
    expect(html).not.toContain("<b>");
  });

  it("opens links in a new tab without referrer or ranking", () => {
    const html = render("[site](https://example.org)");
    expect(html).toContain('href="https://example.org"');
    expect(html).toContain('target="_blank"');
    expect(html).toContain('rel="noopener noreferrer nofollow"');
  });

  it("strips javascript: URLs", () => {
    const html = render("[click](javascript:alert(1))");
    expect(html).not.toContain("javascript:");
  });

  it("turns Markdown images into links instead of loading them", () => {
    const html = render("![pixel](https://tracker.example/p.gif)");
    expect(html).not.toContain("<img");
    expect(html).toContain('href="https://tracker.example/p.gif"');
    expect(html).toContain(">pixel</a>");
  });

  it("wraps output in prose and accepts extra classes", () => {
    const html = renderToStaticMarkup(
      <MarkdownView source="x" className="mt-2" />,
    );
    expect(
      html.startsWith('<div class="prose prose-sm max-w-none mt-2">'),
    ).toBe(true);
  });
});
```

- [ ] **Step 5: Run it to verify it fails**

Run: `npx vitest run components/review/MarkdownView.test.tsx`
Expected: FAIL — `Failed to resolve import "./MarkdownView"`.

- [ ] **Step 6: Implement `components/review/MarkdownView.tsx`**

```tsx
import clsx from "clsx";
import Markdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";

/**
 * The only Markdown renderer for council review content (review bodies
 * and .md attachments). Raw HTML is dropped, links open in a new tab
 * without a referrer, and Markdown images become plain links so a
 * confidential page never loads third-party resources. react-markdown's
 * default urlTransform strips javascript: and other unsafe schemes.
 */
const components: Components = {
  a: ({ href, children }) => (
    <a href={href} target="_blank" rel="noopener noreferrer nofollow">
      {children}
    </a>
  ),
  img: ({ src, alt }) =>
    typeof src === "string" && src !== "" ? (
      <a href={src} target="_blank" rel="noopener noreferrer nofollow">
        {alt || src}
      </a>
    ) : null,
};

export function MarkdownView({
  source,
  className,
}: {
  source: string;
  className?: string;
}) {
  return (
    <div className={clsx("prose prose-sm max-w-none", className)}>
      <Markdown remarkPlugins={[remarkGfm]} skipHtml components={components}>
        {source}
      </Markdown>
    </div>
  );
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `npx vitest run components/review/MarkdownView.test.tsx && npm test`
Expected: PASS (6 new tests; existing suite green).

- [ ] **Step 8: Commit**

```bash
git add nginx/default.conf docker-compose.yml wordpress/php/uploads.ini package.json package-lock.json vitest.config.ts components/review/MarkdownView.tsx components/review/MarkdownView.test.tsx
git commit -m "feat(review): safe Markdown renderer and local upload limits

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 4: Shared types and the server-only review client

**Files:**

- Create: `lib/review-types.ts`, `lib/review-api.ts`
- Test: `lib/review-api.test.ts`

**Interfaces:**

- Produces (`lib/review-types.ts`, client-safe):
  - `ReviewVote`, `REVIEW_VOTES`, `ReviewStage`, `REVIEW_STAGES`, `Council`
  - `ReviewTally`, `ReviewMe`, `ReviewProjectSummary`, `ReviewProjectList`, `ReviewAttachment`, `ReviewAuthor`, `ReviewEntry`, `ReviewWithReplies`, `QuestionWithAnswers`, `ReviewProject`
  - `ATTACHMENT_LIMITS = { markdownBytes, imageBytes, perEntry }`, `IMAGE_EXTENSIONS`
  - `PendingAttachment` (`{ key; kind: 'file'; file: File } | { key; kind: 'paste'; title; markdown }`)
- Produces (`lib/review-api.ts`, server-only):
  - `class ReviewApiError extends Error { status: number; code?: string }`
  - `fetchReviewMe(session)`, `fetchReviewProjects(session, { stage?, page? })`, `fetchReviewProject(session, id)`
  - `saveMyReview(session, projectId, { vote, body_md, version? })`, `postReviewEntry(session, projectId, { kind, parent_id?, body_md })`, `patchReviewEntry(session, entryId, { body_md, version })`
  - `uploadReviewAttachment(session, entryId, FormData | { title, markdown }): Promise<ReviewAttachment>`
  - `fetchReviewAttachment(session, id, download?): Promise<Response>`

- [ ] **Step 1: Create `lib/review-types.ts`**

```ts
/**
 * Shapes of the council review API (`/cdcf/v1/review/*`), shared by the
 * server-only client, route handlers and client components. No runtime
 * dependencies, so client components can import it.
 */

export type ReviewVote = "cdcf" | "community" | "reject";
export const REVIEW_VOTES: readonly ReviewVote[] = [
  "cdcf",
  "community",
  "reject",
];

export type ReviewStage = "in_review" | "awaiting_submitter" | "decided";
export const REVIEW_STAGES: readonly ReviewStage[] = [
  "in_review",
  "awaiting_submitter",
  "decided",
];

export type Council = "technical" | "ecclesial" | "board";

export interface ReviewTally {
  cdcf: number;
  community: number;
  reject: number;
}

export interface ReviewMe {
  user_id: number;
  member: boolean;
  councils: Council[];
  can: { view: boolean; discuss: boolean; vote: boolean; decide: boolean };
}

export interface ReviewProjectSummary {
  id: number;
  title: string;
  category: string;
  language: string;
  submitted_at: string;
  stage: ReviewStage;
  tally: ReviewTally;
  voters_pending: number;
  my_vote: ReviewVote | null;
  unread: number;
}

export interface ReviewProjectList {
  projects: ReviewProjectSummary[];
  page: number;
  has_more: boolean;
}

export interface ReviewAttachment {
  id: number;
  kind: "markdown" | "image";
  mime: string;
  name: string;
  size: number;
}

export interface ReviewAuthor {
  id: number;
  name: string;
  councils: Council[];
}

export interface ReviewEntry {
  id: number;
  kind: "review" | "reply" | "discussion" | "question" | "answer";
  parent_id: number | null;
  body_md: string;
  vote: ReviewVote | null;
  author: ReviewAuthor | null;
  created_at: string;
  edited_at: string | null;
  version: string;
  attachments: ReviewAttachment[];
}

export interface ReviewWithReplies extends ReviewEntry {
  replies: ReviewEntry[];
}

export interface QuestionWithAnswers extends ReviewEntry {
  answers: ReviewEntry[];
}

export interface ReviewProject {
  id: number;
  title: string;
  content: string;
  post_type: string;
  post_status: string;
  stage: ReviewStage;
  language: string;
  submitted_at: string;
  fields: {
    url: string;
    category: string;
    repo_urls: string[];
    tags: string[];
  };
  submitter: { name: string; email: string | null };
  tally: ReviewTally;
  plurality: ReviewVote | null;
  voters_pending: number;
  reviews: ReviewWithReplies[];
  discussion: ReviewEntry[];
  questions: QuestionWithAnswers[];
  me: { can_vote: boolean; can_decide: boolean; review_id: number | null };
}

export const ATTACHMENT_LIMITS = {
  markdownBytes: 512 * 1024,
  imageBytes: 5 * 1024 * 1024,
  perEntry: 10,
} as const;

export const IMAGE_EXTENSIONS = ["png", "jpg", "jpeg", "webp", "gif"] as const;

export type PendingAttachment =
  | { key: string; kind: "file"; file: File }
  | { key: string; kind: "paste"; title: string; markdown: string };
```

- [ ] **Step 2: Write the failing client tests**

`lib/review-api.test.ts`:

```ts
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from "vitest";
import type { Session } from "next-auth";

vi.mock("@/lib/auth-utils", () => ({ getAccessToken: vi.fn() }));

import { getAccessToken } from "@/lib/auth-utils";
import {
  ReviewApiError,
  fetchReviewAttachment,
  fetchReviewMe,
  fetchReviewProject,
  fetchReviewProjects,
  patchReviewEntry,
  postReviewEntry,
  saveMyReview,
  uploadReviewAttachment,
} from "./review-api";

const mockedGetAccessToken = getAccessToken as unknown as Mock;
const session = { user: { email: "a@example.org" } } as unknown as Session;
let fetchMock: Mock;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  vi.resetAllMocks();
  process.env.WP_REST_URL = "https://wp.example.org/wp-json/";
  delete process.env.WP_GRAPHQL_URL;
  mockedGetAccessToken.mockReturnValue("token-abc");
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("review-api", () => {
  it("fetches membership with the bearer token and no caching", async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { member: true }));

    await expect(fetchReviewMe(session)).resolves.toEqual({ member: true });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("https://wp.example.org/wp-json/cdcf/v1/review/me");
    expect(init.headers).toEqual({ Authorization: "Bearer token-abc" });
    expect(init.cache).toBe("no-store");
    expect(init.method).toBe("GET");
  });

  it("builds the project list query string", async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, { projects: [], page: 2, has_more: false }),
    );

    await fetchReviewProjects(session, { stage: "decided", page: 2 });
    await fetchReviewProjects(session, { page: 1 });

    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/projects?stage=decided&page=2",
    );
    expect(fetchMock.mock.calls[1][0]).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/projects",
    );
  });

  it("rejects non-positive ids without calling WordPress", async () => {
    await expect(fetchReviewProject(session, 0)).rejects.toMatchObject({
      status: 400,
      code: "invalid_id",
    });
    await expect(
      patchReviewEntry(session, 1.5, { body_md: "x", version: "v" }),
    ).rejects.toBeInstanceOf(ReviewApiError);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("throws no_token before fetching when the session has no token", async () => {
    mockedGetAccessToken.mockReturnValue(undefined);

    await expect(fetchReviewMe(session)).rejects.toMatchObject({
      status: 401,
      code: "no_token",
    });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("falls back to WP_GRAPHQL_URL and reports missing config", async () => {
    delete process.env.WP_REST_URL;
    process.env.WP_GRAPHQL_URL = "https://cms.example.org/graphql";
    fetchMock.mockResolvedValue(jsonResponse(200, {}));
    await fetchReviewMe(session);
    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://cms.example.org/wp-json/cdcf/v1/review/me",
    );

    delete process.env.WP_GRAPHQL_URL;
    await expect(fetchReviewMe(session)).rejects.toMatchObject({
      status: 500,
      code: "config_missing",
    });
  });

  it("sends my review as JSON with PUT", async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { id: 5 }));

    await saveMyReview(session, 40, {
      vote: "cdcf",
      body_md: "Fits.",
      version: "v1",
    });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/projects/40/my-review",
    );
    expect(init.method).toBe("PUT");
    expect(init.headers["Content-Type"]).toBe("application/json");
    expect(JSON.parse(init.body)).toEqual({
      vote: "cdcf",
      body_md: "Fits.",
      version: "v1",
    });
  });

  it("posts entries and patches them", async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { id: 9 }));

    await postReviewEntry(session, 40, {
      kind: "reply",
      parent_id: 1,
      body_md: "Agreed",
    });
    await patchReviewEntry(session, 9, { body_md: "Edited", version: "v2" });

    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/projects/40/entries",
    );
    expect(fetchMock.mock.calls[0][1].method).toBe("POST");
    expect(fetchMock.mock.calls[1][0]).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/entries/9",
    );
    expect(fetchMock.mock.calls[1][1].method).toBe("PATCH");
  });

  it("maps WordPress error bodies to ReviewApiError", async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(409, { code: "stale_entry", message: "Changed." }),
    );

    await expect(
      patchReviewEntry(session, 9, { body_md: "x", version: "v" }),
    ).rejects.toMatchObject({
      name: "ReviewApiError",
      status: 409,
      code: "stale_entry",
      message: "Changed.",
    });
  });

  it("maps a non-JSON 413 from a proxy to file_too_large", async () => {
    fetchMock.mockResolvedValue(
      new Response("<html>413 Request Entity Too Large</html>", {
        status: 413,
      }),
    );

    await expect(
      uploadReviewAttachment(session, 9, new FormData()),
    ).rejects.toMatchObject({ status: 413, code: "file_too_large" });
  });

  it("uploads FormData untouched and pasted Markdown as JSON", async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { id: 4 }));
    const form = new FormData();
    form.append("file", new Blob(["x"]), "a.md");

    await uploadReviewAttachment(session, 9, form);
    await uploadReviewAttachment(session, 9, {
      title: "License",
      markdown: "# L",
    });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/entries/9/attachments",
    );
    expect(init.body).toBe(form);
    expect(init.headers["Content-Type"]).toBeUndefined();
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
      title: "License",
      markdown: "# L",
    });
  });

  it("returns the raw attachment response and asks for downloads", async () => {
    const wp = new Response("bytes", {
      status: 200,
      headers: { "Content-Type": "image/png" },
    });
    fetchMock.mockResolvedValue(wp);

    await expect(fetchReviewAttachment(session, 4, true)).resolves.toBe(wp);
    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/attachments/4?download=1",
    );
  });

  it("throws on a failed attachment fetch", async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(404, { code: "file_missing", message: "Missing." }),
    );

    await expect(fetchReviewAttachment(session, 4)).rejects.toMatchObject({
      status: 404,
      code: "file_missing",
    });
  });
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `npx vitest run lib/review-api.test.ts`
Expected: FAIL — `Failed to resolve import "./review-api"`.

- [ ] **Step 4: Implement `lib/review-api.ts`**

```ts
/**
 * Server-only client for the council review endpoints
 * (`/cdcf/v1/review/*`). Every call forwards the signed-in user's Zitadel
 * access token as a bearer, so WordPress enforces council membership the
 * same way it does for wp-admin. Mirrors lib/bio-api.ts.
 */
import "server-only";
import type { Session } from "next-auth";
import { getAccessToken } from "@/lib/auth-utils";
import type {
  ReviewAttachment,
  ReviewEntry,
  ReviewMe,
  ReviewProject,
  ReviewProjectList,
  ReviewStage,
  ReviewVote,
} from "@/lib/review-types";

export class ReviewApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string,
  ) {
    super(message);
    this.name = "ReviewApiError";
  }
}

function getWpRestUrl(): string {
  const url =
    process.env.WP_REST_URL ??
    process.env.WP_GRAPHQL_URL?.replace(/\/graphql\/?$/, "/wp-json");
  if (!url) {
    throw new ReviewApiError(
      "Neither WP_REST_URL nor WP_GRAPHQL_URL is configured",
      500,
      "config_missing",
    );
  }
  return url.replace(/\/$/, "");
}

function bearerHeader(
  session: Session | null | undefined,
): Record<string, string> {
  const token = getAccessToken(session);
  if (!token) {
    throw new ReviewApiError("No access token on session", 401, "no_token");
  }
  return { Authorization: `Bearer ${token}` };
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function toReviewApiError(
  body: unknown,
  fallback: string,
  status: number,
): ReviewApiError {
  if (body && typeof body === "object") {
    const obj = body as { message?: unknown; code?: unknown };
    const message = typeof obj.message === "string" ? obj.message : fallback;
    const code = typeof obj.code === "string" ? obj.code : undefined;
    return new ReviewApiError(message, status, code);
  }
  // A proxy in front of WordPress (nginx) rejects oversized bodies with an
  // HTML 413 page that never reaches PHP.
  if (status === 413) {
    return new ReviewApiError(
      "The file is too large for the server",
      413,
      "file_too_large",
    );
  }
  return new ReviewApiError(fallback, status);
}

function assertId(id: number): void {
  if (!Number.isInteger(id) || id <= 0) {
    throw new ReviewApiError("Invalid id", 400, "invalid_id");
  }
}

interface WpRequestInit {
  method?: "GET" | "POST" | "PUT" | "PATCH";
  json?: unknown;
  body?: FormData;
}

async function wpRequest<T>(
  session: Session | null,
  path: string,
  init: WpRequestInit = {},
): Promise<T> {
  const headers = bearerHeader(session);
  let body: BodyInit | undefined;
  if (init.json !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(init.json);
  } else if (init.body) {
    body = init.body;
  }
  const response = await fetch(`${getWpRestUrl()}${path}`, {
    method: init.method ?? "GET",
    headers,
    body,
    cache: "no-store",
  });
  const data = await readJson(response);
  if (!response.ok) {
    throw toReviewApiError(
      data,
      `Review request failed: ${path}`,
      response.status,
    );
  }
  return data as T;
}

export function fetchReviewMe(session: Session | null): Promise<ReviewMe> {
  return wpRequest<ReviewMe>(session, "/cdcf/v1/review/me");
}

export function fetchReviewProjects(
  session: Session | null,
  options: { stage?: ReviewStage; page?: number } = {},
): Promise<ReviewProjectList> {
  const params = new URLSearchParams();
  if (options.stage) params.set("stage", options.stage);
  if (options.page && options.page > 1)
    params.set("page", String(options.page));
  const qs = params.toString();
  return wpRequest<ReviewProjectList>(
    session,
    `/cdcf/v1/review/projects${qs ? `?${qs}` : ""}`,
  );
}

export async function fetchReviewProject(
  session: Session | null,
  id: number,
): Promise<ReviewProject> {
  assertId(id);
  return wpRequest<ReviewProject>(session, `/cdcf/v1/review/projects/${id}`);
}

export interface MyReviewPayload {
  vote: ReviewVote;
  body_md: string;
  version?: string;
}

export async function saveMyReview(
  session: Session | null,
  projectId: number,
  payload: MyReviewPayload,
): Promise<ReviewEntry> {
  assertId(projectId);
  return wpRequest<ReviewEntry>(
    session,
    `/cdcf/v1/review/projects/${projectId}/my-review`,
    { method: "PUT", json: payload },
  );
}

export interface EntryPayload {
  kind: "reply" | "discussion";
  parent_id?: number;
  body_md: string;
}

export async function postReviewEntry(
  session: Session | null,
  projectId: number,
  payload: EntryPayload,
): Promise<ReviewEntry> {
  assertId(projectId);
  return wpRequest<ReviewEntry>(
    session,
    `/cdcf/v1/review/projects/${projectId}/entries`,
    { method: "POST", json: payload },
  );
}

export interface EntryEditPayload {
  body_md: string;
  version: string;
}

export async function patchReviewEntry(
  session: Session | null,
  entryId: number,
  payload: EntryEditPayload,
): Promise<ReviewEntry> {
  assertId(entryId);
  return wpRequest<ReviewEntry>(session, `/cdcf/v1/review/entries/${entryId}`, {
    method: "PATCH",
    json: payload,
  });
}

export async function uploadReviewAttachment(
  session: Session | null,
  entryId: number,
  upload: FormData | { title: string; markdown: string },
): Promise<ReviewAttachment> {
  assertId(entryId);
  return wpRequest<ReviewAttachment>(
    session,
    `/cdcf/v1/review/entries/${entryId}/attachments`,
    upload instanceof FormData
      ? { method: "POST", body: upload }
      : { method: "POST", json: upload },
  );
}

export async function fetchReviewAttachment(
  session: Session | null,
  id: number,
  download = false,
): Promise<Response> {
  assertId(id);
  const response = await fetch(
    `${getWpRestUrl()}/cdcf/v1/review/attachments/${id}${download ? "?download=1" : ""}`,
    { headers: bearerHeader(session), cache: "no-store" },
  );
  if (!response.ok) {
    throw toReviewApiError(
      await readJson(response),
      "Attachment request failed",
      response.status,
    );
  }
  return response;
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npx vitest run lib/review-api.test.ts`
Expected: PASS (12 tests).

- [ ] **Step 6: Commit**

```bash
git add lib/review-types.ts lib/review-api.ts lib/review-api.test.ts
git commit -m "feat(review): server-only client for the review API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 5: Route-handler helpers and the `/api/review/*` routes

**Files:**

- Create: `lib/review-route.ts`; test `lib/review-route.test.ts`
- Create: `app/api/review/me/route.ts`, `app/api/review/projects/route.ts`,
  `app/api/review/projects/[id]/route.ts`, `app/api/review/projects/[id]/my-review/route.ts`,
  `app/api/review/projects/[id]/entries/route.ts`, `app/api/review/entries/[id]/route.ts`,
  `app/api/review/entries/[id]/attachments/route.ts`, `app/api/review/attachments/[id]/route.ts`

**Interfaces:**

- Consumes: Task 4.
- Produces (`lib/review-route.ts`):
  - `parseId(raw: string): number | null`
  - `readJsonObject(request: Request): Promise<Record<string, unknown> | null>`
  - `badRequest(error: string, field?: string): NextResponse`, `unauthorized(): NextResponse`
  - `reviewErrorResponse(err: unknown): NextResponse` (rethrows non-`ReviewApiError`)
  - `attachmentResponse(wp: Response): Response`
  - `ATTACHMENT_PASSTHROUGH_HEADERS`
- Produces (HTTP, same-origin, Auth.js cookie): `GET /api/review/me`,
  `GET /api/review/projects?stage&page`, `GET /api/review/projects/{id}`,
  `PUT /api/review/projects/{id}/my-review`, `POST /api/review/projects/{id}/entries`,
  `PATCH /api/review/entries/{id}`, `POST /api/review/entries/{id}/attachments`
  (multipart `file` or JSON `{title, markdown}`), `GET /api/review/attachments/{id}[?download=1]`.
  Errors: JSON `{ error: code, message? }` with the WordPress status.

- [ ] **Step 1: Write the failing helper tests**

`lib/review-route.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { ReviewApiError } from "./review-api";
import {
  attachmentResponse,
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from "./review-route";

describe("review-route helpers", () => {
  it("parses positive integer ids only", () => {
    expect(parseId("42")).toBe(42);
    for (const bad of [
      "0",
      "-1",
      "1.5",
      "abc",
      "",
      "01",
      "99999999999999999999",
    ]) {
      expect(parseId(bad)).toBeNull();
    }
  });

  it("reads JSON objects and rejects everything else", async () => {
    const req = (body: string) =>
      new Request("http://x", { method: "POST", body });
    expect(await readJsonObject(req('{"a":1}'))).toEqual({ a: 1 });
    expect(await readJsonObject(req("[1]"))).toBeNull();
    expect(await readJsonObject(req("null"))).toBeNull();
    expect(await readJsonObject(req("{oops"))).toBeNull();
  });

  it("builds 400 and 401 responses", async () => {
    const r = badRequest("invalid_field", "body_md");
    expect(r.status).toBe(400);
    expect(await r.json()).toEqual({
      error: "invalid_field",
      field: "body_md",
    });
    expect(unauthorized().status).toBe(401);
  });

  it("maps ReviewApiError to its status and code", async () => {
    const r = reviewErrorResponse(
      new ReviewApiError("Locked.", 403, "review_locked"),
    );
    expect(r.status).toBe(403);
    expect(await r.json()).toEqual({
      error: "review_locked",
      message: "Locked.",
    });
    const generic = reviewErrorResponse(new ReviewApiError("Nope", 502));
    expect(await generic.json()).toEqual({
      error: "wp_error",
      message: "Nope",
    });
  });

  it("rethrows unexpected errors", () => {
    expect(() => reviewErrorResponse(new Error("boom"))).toThrow("boom");
  });

  it("passes attachment bytes and only the safe headers through", async () => {
    const wp = new Response("bytes", {
      status: 200,
      headers: {
        "Content-Type": "image/png",
        "Content-Disposition": "inline",
        "X-Content-Type-Options": "nosniff",
        "Content-Security-Policy": "default-src 'none'; sandbox",
        "Cache-Control": "private, no-store",
        "Set-Cookie": "wp=1",
      },
    });

    const out = attachmentResponse(wp);

    expect(await out.text()).toBe("bytes");
    expect(out.headers.get("content-type")).toBe("image/png");
    expect(out.headers.get("x-content-type-options")).toBe("nosniff");
    expect(out.headers.get("content-security-policy")).toBe(
      "default-src 'none'; sandbox",
    );
    expect(out.headers.get("set-cookie")).toBeNull();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run lib/review-route.test.ts`
Expected: FAIL — `Failed to resolve import "./review-route"`.

- [ ] **Step 3: Implement `lib/review-route.ts`**

```ts
/**
 * Helpers shared by the /api/review/* route handlers: id parsing, JSON
 * bodies, error mapping, and the attachment byte passthrough.
 */
import { NextResponse } from "next/server";
import { ReviewApiError } from "@/lib/review-api";

export function parseId(raw: string): number | null {
  return /^[1-9]\d{0,15}$/.test(raw) ? Number(raw) : null;
}

export async function readJsonObject(
  request: Request,
): Promise<Record<string, unknown> | null> {
  try {
    const body: unknown = await request.json();
    return body && typeof body === "object" && !Array.isArray(body)
      ? (body as Record<string, unknown>)
      : null;
  } catch {
    return null;
  }
}

export function badRequest(error: string, field?: string): NextResponse {
  return NextResponse.json(field ? { error, field } : { error }, {
    status: 400,
  });
}

export function unauthorized(): NextResponse {
  return NextResponse.json({ error: "unauthorized" }, { status: 401 });
}

export function reviewErrorResponse(err: unknown): NextResponse {
  if (err instanceof ReviewApiError) {
    return NextResponse.json(
      { error: err.code ?? "wp_error", message: err.message },
      { status: err.status },
    );
  }
  throw err;
}

export const ATTACHMENT_PASSTHROUGH_HEADERS = [
  "content-type",
  "content-length",
  "content-disposition",
  "cache-control",
  "x-content-type-options",
  "content-security-policy",
] as const;

export function attachmentResponse(wp: Response): Response {
  const headers = new Headers();
  for (const name of ATTACHMENT_PASSTHROUGH_HEADERS) {
    const value = wp.headers.get(name);
    if (value) headers.set(name, value);
  }
  return new Response(wp.body, { status: wp.status, headers });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run lib/review-route.test.ts`
Expected: PASS (6 tests).

- [ ] **Step 5: Create the route handlers**

`app/api/review/me/route.ts`:

```ts
import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { fetchReviewMe } from "@/lib/review-api";

/**
 * Fail-soft membership probe for the header menu: anonymous users,
 * non-members and WordPress errors all read as `{ member: false }`.
 */
export async function GET() {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ member: false });
  }
  try {
    return NextResponse.json(await fetchReviewMe(session));
  } catch (err) {
    console.error("review/me failed", err);
    return NextResponse.json({ member: false });
  }
}
```

`app/api/review/projects/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { fetchReviewProjects } from "@/lib/review-api";
import { REVIEW_STAGES, type ReviewStage } from "@/lib/review-types";
import {
  badRequest,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

export async function GET(request: NextRequest) {
  const session = await auth();
  if (!session?.user) return unauthorized();
  const stageParam = request.nextUrl.searchParams.get("stage") ?? "";
  if (
    stageParam !== "" &&
    !(REVIEW_STAGES as readonly string[]).includes(stageParam)
  ) {
    return badRequest("invalid_stage");
  }
  const page = Number.parseInt(
    request.nextUrl.searchParams.get("page") ?? "1",
    10,
  );
  try {
    return NextResponse.json(
      await fetchReviewProjects(session, {
        stage: stageParam === "" ? undefined : (stageParam as ReviewStage),
        page: Number.isFinite(page) && page > 0 ? page : 1,
      }),
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/projects/[id]/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { fetchReviewProject } from "@/lib/review-api";
import {
  badRequest,
  parseId,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

export async function GET(
  _request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const session = await auth();
  if (!session?.user) return unauthorized();
  try {
    return NextResponse.json(await fetchReviewProject(session, id));
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/projects/[id]/my-review/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { saveMyReview } from "@/lib/review-api";
import { REVIEW_VOTES, type ReviewVote } from "@/lib/review-types";
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

export async function PUT(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const session = await auth();
  if (!session?.user) return unauthorized();
  const body = await readJsonObject(request);
  if (!body) return badRequest("invalid_body");
  const { vote, body_md, version } = body;
  if (
    typeof vote !== "string" ||
    !(REVIEW_VOTES as readonly string[]).includes(vote)
  ) {
    return badRequest("invalid_vote");
  }
  if (typeof body_md !== "string")
    return badRequest("invalid_field", "body_md");
  if (version !== undefined && typeof version !== "string")
    return badRequest("invalid_field", "version");
  try {
    return NextResponse.json(
      await saveMyReview(session, id, {
        vote: vote as ReviewVote,
        body_md,
        ...(version ? { version } : {}),
      }),
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/projects/[id]/entries/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { postReviewEntry } from "@/lib/review-api";
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const session = await auth();
  if (!session?.user) return unauthorized();
  const body = await readJsonObject(request);
  if (!body) return badRequest("invalid_body");
  const { kind, parent_id, body_md } = body;
  if (kind !== "reply" && kind !== "discussion")
    return badRequest("invalid_kind");
  if (typeof body_md !== "string")
    return badRequest("invalid_field", "body_md");
  if (
    kind === "reply" &&
    (typeof parent_id !== "number" ||
      !Number.isInteger(parent_id) ||
      parent_id <= 0)
  ) {
    return badRequest("invalid_field", "parent_id");
  }
  try {
    return NextResponse.json(
      await postReviewEntry(session, id, {
        kind,
        body_md,
        ...(kind === "reply" ? { parent_id: parent_id as number } : {}),
      }),
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/entries/[id]/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { patchReviewEntry } from "@/lib/review-api";
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

export async function PATCH(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const session = await auth();
  if (!session?.user) return unauthorized();
  const body = await readJsonObject(request);
  if (!body) return badRequest("invalid_body");
  const { body_md, version } = body;
  if (typeof body_md !== "string")
    return badRequest("invalid_field", "body_md");
  if (typeof version !== "string" || version === "")
    return badRequest("invalid_field", "version");
  try {
    return NextResponse.json(
      await patchReviewEntry(session, id, { body_md, version }),
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/entries/[id]/attachments/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { uploadReviewAttachment } from "@/lib/review-api";
import { ATTACHMENT_LIMITS } from "@/lib/review-types";
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

const tooLarge = () =>
  NextResponse.json({ error: "file_too_large" }, { status: 413 });

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const session = await auth();
  if (!session?.user) return unauthorized();

  try {
    if (
      (request.headers.get("content-type") ?? "").startsWith(
        "multipart/form-data",
      )
    ) {
      const incoming = await request.formData();
      const file = incoming.get("file");
      if (!(file instanceof File) || file.size === 0)
        return badRequest("empty_file");
      if (file.size > ATTACHMENT_LIMITS.imageBytes) return tooLarge();
      const form = new FormData();
      form.append("file", file, file.name);
      return NextResponse.json(
        await uploadReviewAttachment(session, id, form),
        { status: 201 },
      );
    }
    const body = await readJsonObject(request);
    if (
      !body ||
      typeof body.markdown !== "string" ||
      body.markdown.trim() === ""
    )
      return badRequest("empty_file");
    if (
      new TextEncoder().encode(body.markdown).length >
      ATTACHMENT_LIMITS.markdownBytes
    )
      return tooLarge();
    const title = typeof body.title === "string" ? body.title : "";
    return NextResponse.json(
      await uploadReviewAttachment(session, id, {
        title,
        markdown: body.markdown,
      }),
      { status: 201 },
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/attachments/[id]/route.ts`:

```ts
import { type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { fetchReviewAttachment } from "@/lib/review-api";
import {
  attachmentResponse,
  badRequest,
  parseId,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

export async function GET(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const session = await auth();
  if (!session?.user) return unauthorized();
  try {
    const download = request.nextUrl.searchParams.get("download") === "1";
    return attachmentResponse(
      await fetchReviewAttachment(session, id, download),
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

- [ ] **Step 6: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint lib/review-route.ts app/api/review`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add lib/review-route.ts lib/review-route.test.ts app/api/review
git commit -m "feat(review): /api/review route handlers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 6: UI helpers and the browser fetch wrapper

**Files:**

- Create: `lib/review-ui.ts`, `lib/review-client.ts`
- Test: `lib/review-ui.test.ts`, `lib/review-client.test.ts`

**Interfaces:**

- Consumes: `lib/review-types.ts`.
- Produces (`lib/review-ui.ts`):
  - `type ReviewErrorKey = 'sessionExpired' | 'forbidden' | 'locked' | 'stale' | 'reviewExists' | 'tooLarge' | 'unsupportedType' | 'tooMany' | 'invalidInput' | 'notFound' | 'generic'`
  - `reviewErrorKey(status: number, code?: string): ReviewErrorKey`
  - `withAttachmentParam(search: string, id: number | null): string` (`''` or `?…`)
  - `parseAttachmentParam(value: string | null): number | null`
  - `tallySegments(tally, pending): Array<{ key: ReviewVote | 'pending'; count: number; percent: number }>`
  - `parseWpGmt(value: string): Date`, `formatBytes(bytes: number): string`
  - `imageNeighbours(attachments, currentId): { prev: number | null; next: number | null }`
  - `type AttachmentIndex = Record<string, { attachment: ReviewAttachment; siblings: ReviewAttachment[] }>`, `buildAttachmentIndex(project: ReviewProject): AttachmentIndex`
  - `checkAttachmentFile(name: string, size: number): ReviewErrorKey | null`
  - `pendingName(p: PendingAttachment): string`
- Produces (`lib/review-client.ts`):
  - `type ReviewResult<T> = { ok: true; data: T } | { ok: false; status: number; code?: string; errorKey: ReviewErrorKey }`
  - `reviewFetch<T>(url: string, init?: RequestInit): Promise<ReviewResult<T>>`
  - `jsonInit(method: string, payload: unknown): RequestInit`
  - `uploadAttachment(entryId: number, pending: PendingAttachment): Promise<ReviewResult<ReviewAttachment>>`

- [ ] **Step 1: Write the failing tests**

`lib/review-ui.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import type {
  ReviewAttachment,
  ReviewEntry,
  ReviewProject,
} from "./review-types";
import {
  buildAttachmentIndex,
  checkAttachmentFile,
  formatBytes,
  imageNeighbours,
  parseAttachmentParam,
  parseWpGmt,
  pendingName,
  reviewErrorKey,
  tallySegments,
  withAttachmentParam,
} from "./review-ui";

const att = (
  id: number,
  kind: ReviewAttachment["kind"] = "image",
): ReviewAttachment => ({
  id,
  kind,
  mime: kind === "image" ? "image/png" : "text/markdown",
  name: `f${id}`,
  size: 10,
});

const entry = (
  id: number,
  attachments: ReviewAttachment[],
  extra: Partial<ReviewEntry> = {},
): ReviewEntry => ({
  id,
  kind: "review",
  parent_id: null,
  body_md: "",
  vote: null,
  author: null,
  created_at: "",
  edited_at: null,
  version: "v",
  attachments,
  ...extra,
});

describe("reviewErrorKey", () => {
  it("prefers specific codes", () => {
    expect(reviewErrorKey(403, "review_locked")).toBe("locked");
    expect(reviewErrorKey(409, "stale_entry")).toBe("stale");
    expect(reviewErrorKey(409, "review_exists")).toBe("reviewExists");
    expect(reviewErrorKey(413, "file_too_large")).toBe("tooLarge");
    expect(reviewErrorKey(400, "type_mismatch")).toBe("unsupportedType");
    expect(reviewErrorKey(400, "unsupported_type")).toBe("unsupportedType");
    expect(reviewErrorKey(400, "too_many_attachments")).toBe("tooMany");
  });

  it("treats every expired-session signal as sessionExpired", () => {
    expect(reviewErrorKey(401, "no_token")).toBe("sessionExpired");
    expect(reviewErrorKey(401, "rest_not_logged_in")).toBe("sessionExpired");
    expect(reviewErrorKey(401, "unauthorized")).toBe("sessionExpired");
    expect(reviewErrorKey(401)).toBe("sessionExpired");
  });

  it("falls back on status", () => {
    expect(reviewErrorKey(403, "rest_forbidden")).toBe("forbidden");
    expect(reviewErrorKey(404)).toBe("notFound");
    expect(reviewErrorKey(413)).toBe("tooLarge");
    expect(reviewErrorKey(400, "empty_body")).toBe("invalidInput");
    expect(reviewErrorKey(500)).toBe("generic");
    expect(reviewErrorKey(0)).toBe("generic");
  });
});

describe("attachment URL param", () => {
  it("sets and clears att while keeping other params", () => {
    expect(withAttachmentParam("stage=x", 4)).toBe("?stage=x&att=4");
    expect(withAttachmentParam("stage=x&att=4", null)).toBe("?stage=x");
    expect(withAttachmentParam("att=4", null)).toBe("");
  });

  it("parses only positive integers", () => {
    expect(parseAttachmentParam("7")).toBe(7);
    expect(parseAttachmentParam(null)).toBeNull();
    expect(parseAttachmentParam("0")).toBeNull();
    expect(parseAttachmentParam("x")).toBeNull();
  });
});

describe("tallySegments", () => {
  it("computes shares including pending voters", () => {
    expect(tallySegments({ cdcf: 2, community: 1, reject: 0 }, 1)).toEqual([
      { key: "cdcf", count: 2, percent: 50 },
      { key: "community", count: 1, percent: 25 },
      { key: "reject", count: 0, percent: 0 },
      { key: "pending", count: 1, percent: 25 },
    ]);
  });

  it("is all zero with nobody to count", () => {
    expect(
      tallySegments({ cdcf: 0, community: 0, reject: 0 }, 0).every(
        (s) => s.percent === 0,
      ),
    ).toBe(true);
  });
});

describe("formatting", () => {
  it("parses WordPress GMT datetimes as UTC", () => {
    expect(parseWpGmt("2026-10-01 09:00:00").toISOString()).toBe(
      "2026-10-01T09:00:00.000Z",
    );
  });

  it("formats byte sizes", () => {
    expect(formatBytes(512)).toBe("512 B");
    expect(formatBytes(1536)).toBe("1.5 KB");
    expect(formatBytes(5 * 1024 * 1024)).toBe("5.0 MB");
  });
});

describe("attachments", () => {
  it("finds previous and next images, skipping Markdown", () => {
    const list = [att(1), att(2, "markdown"), att(3), att(4)];
    expect(imageNeighbours(list, 3)).toEqual({ prev: 1, next: 4 });
    expect(imageNeighbours(list, 1)).toEqual({ prev: null, next: 3 });
    expect(imageNeighbours(list, 2)).toEqual({ prev: null, next: null });
  });

  it("indexes every attachment on the page with its siblings", () => {
    const reply = entry(5, [att(30)], { kind: "reply" });
    const project = {
      reviews: [{ ...entry(1, [att(10), att(11)]), replies: [reply] }],
      discussion: [entry(6, [att(40, "markdown")], { kind: "discussion" })],
      questions: [{ ...entry(7, [], { kind: "question" }), answers: [] }],
    } as unknown as ReviewProject;

    const index = buildAttachmentIndex(project);

    expect(Object.keys(index).sort()).toEqual(["10", "11", "30", "40"]);
    expect(index["11"].siblings.map((a) => a.id)).toEqual([10, 11]);
    expect(index["30"].siblings.map((a) => a.id)).toEqual([30]);
  });

  it("pre-checks files against the upload limits", () => {
    expect(checkAttachmentFile("notes.md", 1000)).toBeNull();
    expect(checkAttachmentFile("photo.JPEG", 1000)).toBeNull();
    expect(checkAttachmentFile("notes.md", 512 * 1024 + 1)).toBe("tooLarge");
    expect(checkAttachmentFile("photo.png", 5 * 1024 * 1024 + 1)).toBe(
      "tooLarge",
    );
    expect(checkAttachmentFile("logo.svg", 10)).toBe("unsupportedType");
    expect(checkAttachmentFile("README", 10)).toBe("unsupportedType");
  });

  it("names pending attachments", () => {
    expect(
      pendingName({
        key: "a",
        kind: "paste",
        title: "License concerns",
        markdown: "#",
      }),
    ).toBe("License concerns.md");
    expect(
      pendingName({ key: "b", kind: "paste", title: " ", markdown: "#" }),
    ).toBe("notes.md");
    expect(
      pendingName({ key: "c", kind: "file", file: new File(["x"], "a.png") }),
    ).toBe("a.png");
  });
});
```

`lib/review-client.test.ts`:

```ts
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from "vitest";
import { jsonInit, reviewFetch, uploadAttachment } from "./review-client";

let fetchMock: Mock;

beforeEach(() => {
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });

describe("reviewFetch", () => {
  it("returns data on success without caching", async () => {
    fetchMock.mockResolvedValue(json(200, { id: 1 }));

    await expect(reviewFetch("/api/review/me")).resolves.toEqual({
      ok: true,
      data: { id: 1 },
    });
    expect(fetchMock.mock.calls[0][1].cache).toBe("no-store");
  });

  it("maps error bodies to an error key", async () => {
    fetchMock.mockResolvedValue(
      json(403, { error: "review_locked", message: "Locked." }),
    );

    await expect(reviewFetch("/x")).resolves.toEqual({
      ok: false,
      status: 403,
      code: "review_locked",
      errorKey: "locked",
    });
  });

  it("handles non-JSON errors and network failures", async () => {
    fetchMock.mockResolvedValueOnce(new Response("<html>", { status: 413 }));
    await expect(reviewFetch("/x")).resolves.toMatchObject({
      ok: false,
      status: 413,
      errorKey: "tooLarge",
    });

    fetchMock.mockRejectedValueOnce(new TypeError("offline"));
    await expect(reviewFetch("/x")).resolves.toEqual({
      ok: false,
      status: 0,
      errorKey: "generic",
    });
  });
});

describe("jsonInit", () => {
  it("serialises the payload", () => {
    expect(jsonInit("PUT", { a: 1 })).toEqual({
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: '{"a":1}',
    });
  });
});

describe("uploadAttachment", () => {
  it("sends files as multipart and pastes as JSON", async () => {
    fetchMock.mockResolvedValue(json(201, { id: 4 }));
    const file = new File(["x"], "a.png", { type: "image/png" });

    await uploadAttachment(9, { key: "k", kind: "file", file });
    await uploadAttachment(9, {
      key: "p",
      kind: "paste",
      title: "T",
      markdown: "# T",
    });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("/api/review/entries/9/attachments");
    expect(init.body).toBeInstanceOf(FormData);
    expect((init.body as FormData).get("file")).toBeInstanceOf(File);
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
      title: "T",
      markdown: "# T",
    });
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run lib/review-ui.test.ts lib/review-client.test.ts`
Expected: FAIL — unresolved imports.

- [ ] **Step 3: Implement `lib/review-ui.ts`**

```ts
/**
 * Pure helpers for the review UI. No React, no fetch — unit tested.
 */
import {
  ATTACHMENT_LIMITS,
  IMAGE_EXTENSIONS,
  type PendingAttachment,
  type ReviewAttachment,
  type ReviewEntry,
  type ReviewProject,
  type ReviewTally,
  type ReviewVote,
} from "@/lib/review-types";

export type ReviewErrorKey =
  | "sessionExpired"
  | "forbidden"
  | "locked"
  | "stale"
  | "reviewExists"
  | "tooLarge"
  | "unsupportedType"
  | "tooMany"
  | "invalidInput"
  | "notFound"
  | "generic";

const CODE_KEYS: Record<string, ReviewErrorKey> = {
  review_locked: "locked",
  stale_entry: "stale",
  review_exists: "reviewExists",
  file_too_large: "tooLarge",
  unsupported_type: "unsupportedType",
  type_mismatch: "unsupportedType",
  too_many_attachments: "tooMany",
  no_token: "sessionExpired",
  rest_not_logged_in: "sessionExpired",
  unauthorized: "sessionExpired",
};

export function reviewErrorKey(status: number, code?: string): ReviewErrorKey {
  if (code && CODE_KEYS[code]) return CODE_KEYS[code];
  if (status === 401) return "sessionExpired";
  if (status === 403) return "forbidden";
  if (status === 404) return "notFound";
  if (status === 413) return "tooLarge";
  if (status === 400) return "invalidInput";
  return "generic";
}

export function withAttachmentParam(search: string, id: number | null): string {
  const params = new URLSearchParams(search);
  if (id === null) params.delete("att");
  else params.set("att", String(id));
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

export function parseAttachmentParam(value: string | null): number | null {
  return value && /^[1-9]\d*$/.test(value) ? Number(value) : null;
}

export interface TallySegment {
  key: ReviewVote | "pending";
  count: number;
  percent: number;
}

export function tallySegments(
  tally: ReviewTally,
  pending: number,
): TallySegment[] {
  const counts: Array<[TallySegment["key"], number]> = [
    ["cdcf", tally.cdcf],
    ["community", tally.community],
    ["reject", tally.reject],
    ["pending", pending],
  ];
  const total = counts.reduce((sum, [, n]) => sum + n, 0);
  return counts.map(([key, count]) => ({
    key,
    count,
    percent: total ? Math.round((count * 1000) / total) / 10 : 0,
  }));
}

export function parseWpGmt(value: string): Date {
  return new Date(`${value.replace(" ", "T")}Z`);
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function imageNeighbours(
  attachments: ReviewAttachment[],
  currentId: number,
): { prev: number | null; next: number | null } {
  const images = attachments.filter((a) => a.kind === "image");
  const i = images.findIndex((a) => a.id === currentId);
  if (i < 0) return { prev: null, next: null };
  return {
    prev: i > 0 ? images[i - 1].id : null,
    next: i < images.length - 1 ? images[i + 1].id : null,
  };
}

export type AttachmentIndex = Record<
  string,
  { attachment: ReviewAttachment; siblings: ReviewAttachment[] }
>;

export function buildAttachmentIndex(project: ReviewProject): AttachmentIndex {
  const entries: ReviewEntry[] = [
    ...project.reviews.flatMap((r) => [r, ...r.replies]),
    ...project.discussion,
    ...project.questions.flatMap((q) => [q, ...q.answers]),
  ];
  const index: AttachmentIndex = {};
  for (const entry of entries) {
    for (const attachment of entry.attachments) {
      index[String(attachment.id)] = {
        attachment,
        siblings: entry.attachments,
      };
    }
  }
  return index;
}

export function checkAttachmentFile(
  name: string,
  size: number,
): ReviewErrorKey | null {
  const ext = name.includes(".") ? name.split(".").pop()!.toLowerCase() : "";
  if (ext === "md")
    return size > ATTACHMENT_LIMITS.markdownBytes ? "tooLarge" : null;
  if ((IMAGE_EXTENSIONS as readonly string[]).includes(ext)) {
    return size > ATTACHMENT_LIMITS.imageBytes ? "tooLarge" : null;
  }
  return "unsupportedType";
}

export function pendingName(p: PendingAttachment): string {
  if (p.kind === "file") return p.file.name;
  const title = p.title.trim();
  return `${title !== "" ? title : "notes"}.md`;
}
```

- [ ] **Step 4: Implement `lib/review-client.ts`**

```ts
/**
 * Browser-side calls to /api/review/*. Never throws: every outcome is a
 * ReviewResult the UI can render.
 */
import type { PendingAttachment, ReviewAttachment } from "@/lib/review-types";
import { reviewErrorKey, type ReviewErrorKey } from "@/lib/review-ui";

export type ReviewResult<T> =
  | { ok: true; data: T }
  | { ok: false; status: number; code?: string; errorKey: ReviewErrorKey };

export async function reviewFetch<T>(
  url: string,
  init: RequestInit = {},
): Promise<ReviewResult<T>> {
  let response: Response;
  try {
    response = await fetch(url, { cache: "no-store", ...init });
  } catch {
    return { ok: false, status: 0, errorKey: "generic" };
  }
  let body: unknown = null;
  try {
    body = await response.json();
  } catch {
    body = null;
  }
  if (response.ok) return { ok: true, data: body as T };
  const code =
    body &&
    typeof body === "object" &&
    typeof (body as { error?: unknown }).error === "string"
      ? (body as { error: string }).error
      : undefined;
  return {
    ok: false,
    status: response.status,
    code,
    errorKey: reviewErrorKey(response.status, code),
  };
}

export function jsonInit(method: string, payload: unknown): RequestInit {
  return {
    method,
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  };
}

export function uploadAttachment(
  entryId: number,
  pending: PendingAttachment,
): Promise<ReviewResult<ReviewAttachment>> {
  const url = `/api/review/entries/${entryId}/attachments`;
  if (pending.kind === "file") {
    const form = new FormData();
    form.append("file", pending.file, pending.file.name);
    return reviewFetch<ReviewAttachment>(url, { method: "POST", body: form });
  }
  return reviewFetch<ReviewAttachment>(
    url,
    jsonInit("POST", { title: pending.title, markdown: pending.markdown }),
  );
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npx vitest run lib/review-ui.test.ts lib/review-client.test.ts && npm test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/review-ui.ts lib/review-ui.test.ts lib/review-client.ts lib/review-client.test.ts
git commit -m "feat(review): UI helpers and browser fetch wrapper

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 7: UI strings and the header menu link

**Files:**

- Modify: `messages/en.json`, `messages/it.json`, `messages/es.json`, `messages/fr.json`, `messages/pt.json`, `messages/de.json`
- Modify: `components/AuthButton.tsx`
- Create: `scripts/tests/check-review-messages.mjs` (temporary check, deleted in Step 5)

**Interfaces:**

- Produces: `Review.*` keys used by Tasks 8–11 (exact key list below); `Auth.projectReview`.

- [ ] **Step 1: Write a failing key-parity check**

`scripts/tests/check-review-messages.mjs`:

```js
import { readFileSync } from "node:fs";

const flatten = (obj, prefix = "") =>
  Object.entries(obj).flatMap(([k, v]) =>
    v && typeof v === "object"
      ? flatten(v, `${prefix}${k}.`)
      : [`${prefix}${k}`],
  );

const load = (l) => JSON.parse(readFileSync(`messages/${l}.json`, "utf8"));
const en = load("en");
if (!en.Review || !en.Auth?.projectReview)
  throw new Error("en.json is missing Review / Auth.projectReview");
const expected = flatten(en.Review).sort();
for (const l of ["it", "es", "fr", "pt", "de"]) {
  const m = load(l);
  const got = flatten(m.Review ?? {}).sort();
  const missing = expected.filter((k) => !got.includes(k));
  const extra = got.filter((k) => !expected.includes(k));
  if (missing.length || extra.length || !m.Auth?.projectReview) {
    throw new Error(
      `${l}: missing ${missing.join(", ") || "-"}; extra ${extra.join(", ") || "-"}; projectReview ${Boolean(m.Auth?.projectReview)}`,
    );
  }
}
console.log(`Review messages OK: ${expected.length} keys × 6 locales`);
```

Run: `node scripts/tests/check-review-messages.mjs`
Expected: FAIL — `en.json is missing Review / Auth.projectReview`.

- [ ] **Step 2: Add the strings**

Add `"projectReview"` to each file's `Auth` object and a top-level `"Review"`
object after `"MyBio"`. Write each file's JSON with the same 2-space
formatting the files already use.

`en`: `"projectReview": "Project review"` and

```json
"Review": {
  "title": "Project review",
  "intro": "Submitted projects awaiting the advisory councils' review.",
  "notMember": "Project review is open to members of the Technical Council, the Ecclesial Council and the Board of Directors. If you are one, ask an administrator to link your account to your bio card.",
  "backToList": "All submissions",
  "empty": "No submissions here.",
  "newer": "Newer",
  "older": "Older",
  "stage": { "in_review": "In review", "awaiting_submitter": "Awaiting submitter", "decided": "Decided" },
  "vote": { "cdcf": "Accept as CDCF project", "community": "Accept as community project", "reject": "Reject" },
  "council": { "technical": "Technical Council", "ecclesial": "Ecclesial Council", "board": "Board" },
  "tally": { "heading": "Votes", "pending": "{count} not yet voted" },
  "plurality": "Most votes: {vote}",
  "yourVote": "Your vote: {vote}",
  "awaitingYourVote": "Awaiting your vote",
  "unread": "{count} new",
  "submittedOn": "Submitted {date}",
  "locked": "This review has been decided. Reviews and discussion are now read-only.",
  "edited": "edited",
  "cancel": "Cancel",
  "save": "Save",
  "saving": "Saving…",
  "edit": "Edit",
  "submission": { "heading": "Submission", "website": "Website", "repositories": "Repositories", "tags": "Tags", "submitter": "Submitted by", "language": "Language", "category": "Category" },
  "myReview": { "heading": "Your review", "voteLabel": "Your vote", "rationale": "Your review", "submit": "Post review", "update": "Update review", "edit": "Edit your review", "voteRequired": "Choose a vote first." },
  "editor": { "write": "Write", "preview": "Preview", "placeholder": "Markdown is supported.", "emptyPreview": "Nothing to preview." },
  "reviews": { "heading": "Reviews", "none": "No reviews yet.", "reply": "Reply", "postReply": "Post reply", "replyLabel": "Your reply" },
  "discussion": { "heading": "General discussion", "none": "No discussion yet.", "post": "Post", "label": "Add to the discussion" },
  "questions": { "heading": "Questions to the submitter", "none": "No questions have been sent to the submitter.", "submitter": "Submitter", "awaiting": "Awaiting the submitter's answer." },
  "attach": { "button": "Attach file", "paste": "Paste Markdown", "titleLabel": "Title", "markdownLabel": "Markdown", "add": "Add", "hint": "Markdown (.md, up to 512 KB) or images (PNG, JPEG, WebP, GIF, up to 5 MB).", "remove": "Remove {name}", "failed": "{name}: {error}" },
  "panel": { "close": "Close", "raw": "Show source", "rendered": "Show rendered", "download": "Download", "previous": "Previous", "next": "Next", "loading": "Loading…", "loadFailed": "Could not load the attachment." },
  "errors": { "sessionExpired": "Your session has expired. Please sign in again.", "forbidden": "You don't have permission to do that.", "locked": "This review has been decided and can no longer be changed.", "stale": "This was changed since you opened it. Reload to see the latest version.", "reviewExists": "You have already posted a review. Reload to edit it.", "tooLarge": "The file is too large.", "unsupportedType": "That file type isn't supported.", "tooMany": "This post already has the maximum number of attachments.", "invalidInput": "Please check what you entered.", "notFound": "Not found.", "generic": "Something went wrong. Please try again.", "reload": "Reload" }
}
```

`it`: `"projectReview": "Revisione progetti"` and

```json
"Review": {
  "title": "Revisione progetti",
  "intro": "Progetti proposti in attesa della revisione dei consigli consultivi.",
  "notMember": "La revisione dei progetti è riservata ai membri del Consiglio tecnico, del Consiglio ecclesiale e del Consiglio di amministrazione. Se ne fai parte, chiedi a un amministratore di collegare il tuo account alla tua scheda biografica.",
  "backToList": "Tutte le proposte",
  "empty": "Nessuna proposta qui.",
  "newer": "Più recenti",
  "older": "Meno recenti",
  "stage": { "in_review": "In revisione", "awaiting_submitter": "In attesa del proponente", "decided": "Decisi" },
  "vote": { "cdcf": "Accetta come progetto CDCF", "community": "Accetta come progetto della comunità", "reject": "Rifiuta" },
  "council": { "technical": "Consiglio tecnico", "ecclesial": "Consiglio ecclesiale", "board": "Consiglio di amministrazione" },
  "tally": { "heading": "Voti", "pending": "{count} non hanno ancora votato" },
  "plurality": "Più votato: {vote}",
  "yourVote": "Il tuo voto: {vote}",
  "awaitingYourVote": "In attesa del tuo voto",
  "unread": "{count} nuovi",
  "submittedOn": "Proposto il {date}",
  "locked": "Questa revisione è stata decisa. Revisioni e discussione sono ora in sola lettura.",
  "edited": "modificato",
  "cancel": "Annulla",
  "save": "Salva",
  "saving": "Salvataggio…",
  "edit": "Modifica",
  "submission": { "heading": "Proposta", "website": "Sito web", "repositories": "Repository", "tags": "Tag", "submitter": "Proposto da", "language": "Lingua", "category": "Categoria" },
  "myReview": { "heading": "La tua revisione", "voteLabel": "Il tuo voto", "rationale": "La tua revisione", "submit": "Pubblica revisione", "update": "Aggiorna revisione", "edit": "Modifica la tua revisione", "voteRequired": "Scegli prima un voto." },
  "editor": { "write": "Scrivi", "preview": "Anteprima", "placeholder": "È supportato il Markdown.", "emptyPreview": "Niente da visualizzare." },
  "reviews": { "heading": "Revisioni", "none": "Ancora nessuna revisione.", "reply": "Rispondi", "postReply": "Pubblica risposta", "replyLabel": "La tua risposta" },
  "discussion": { "heading": "Discussione generale", "none": "Ancora nessuna discussione.", "post": "Pubblica", "label": "Contribuisci alla discussione" },
  "questions": { "heading": "Domande al proponente", "none": "Non sono state inviate domande al proponente.", "submitter": "Proponente", "awaiting": "In attesa della risposta del proponente." },
  "attach": { "button": "Allega file", "paste": "Incolla Markdown", "titleLabel": "Titolo", "markdownLabel": "Markdown", "add": "Aggiungi", "hint": "Markdown (.md, fino a 512 KB) o immagini (PNG, JPEG, WebP, GIF, fino a 5 MB).", "remove": "Rimuovi {name}", "failed": "{name}: {error}" },
  "panel": { "close": "Chiudi", "raw": "Mostra sorgente", "rendered": "Mostra formattato", "download": "Scarica", "previous": "Precedente", "next": "Successiva", "loading": "Caricamento…", "loadFailed": "Impossibile caricare l'allegato." },
  "errors": { "sessionExpired": "La sessione è scaduta. Accedi di nuovo.", "forbidden": "Non hai il permesso di farlo.", "locked": "Questa revisione è stata decisa e non può più essere modificata.", "stale": "È stato modificato da quando l'hai aperto. Ricarica per vedere la versione più recente.", "reviewExists": "Hai già pubblicato una revisione. Ricarica per modificarla.", "tooLarge": "Il file è troppo grande.", "unsupportedType": "Questo tipo di file non è supportato.", "tooMany": "Questo messaggio ha già il numero massimo di allegati.", "invalidInput": "Controlla i dati inseriti.", "notFound": "Non trovato.", "generic": "Qualcosa è andato storto. Riprova.", "reload": "Ricarica" }
}
```

`es`: `"projectReview": "Revisión de proyectos"` and

```json
"Review": {
  "title": "Revisión de proyectos",
  "intro": "Proyectos presentados a la espera de la revisión de los consejos asesores.",
  "notMember": "La revisión de proyectos está abierta a los miembros del Consejo Técnico, el Consejo Eclesial y la Junta Directiva. Si eres uno de ellos, pide a un administrador que vincule tu cuenta a tu ficha biográfica.",
  "backToList": "Todas las propuestas",
  "empty": "No hay propuestas aquí.",
  "newer": "Más recientes",
  "older": "Más antiguas",
  "stage": { "in_review": "En revisión", "awaiting_submitter": "Esperando al proponente", "decided": "Decididas" },
  "vote": { "cdcf": "Aceptar como proyecto CDCF", "community": "Aceptar como proyecto comunitario", "reject": "Rechazar" },
  "council": { "technical": "Consejo Técnico", "ecclesial": "Consejo Eclesial", "board": "Junta Directiva" },
  "tally": { "heading": "Votos", "pending": "{count} aún sin votar" },
  "plurality": "Más votado: {vote}",
  "yourVote": "Tu voto: {vote}",
  "awaitingYourVote": "Pendiente de tu voto",
  "unread": "{count} nuevos",
  "submittedOn": "Presentado el {date}",
  "locked": "Esta revisión ya se ha decidido. Las revisiones y la discusión son ahora de solo lectura.",
  "edited": "editado",
  "cancel": "Cancelar",
  "save": "Guardar",
  "saving": "Guardando…",
  "edit": "Editar",
  "submission": { "heading": "Propuesta", "website": "Sitio web", "repositories": "Repositorios", "tags": "Etiquetas", "submitter": "Presentado por", "language": "Idioma", "category": "Categoría" },
  "myReview": { "heading": "Tu revisión", "voteLabel": "Tu voto", "rationale": "Tu revisión", "submit": "Publicar revisión", "update": "Actualizar revisión", "edit": "Editar tu revisión", "voteRequired": "Elige primero un voto." },
  "editor": { "write": "Escribir", "preview": "Vista previa", "placeholder": "Se admite Markdown.", "emptyPreview": "Nada que mostrar." },
  "reviews": { "heading": "Revisiones", "none": "Aún no hay revisiones.", "reply": "Responder", "postReply": "Publicar respuesta", "replyLabel": "Tu respuesta" },
  "discussion": { "heading": "Discusión general", "none": "Aún no hay discusión.", "post": "Publicar", "label": "Aportar a la discusión" },
  "questions": { "heading": "Preguntas al proponente", "none": "No se han enviado preguntas al proponente.", "submitter": "Proponente", "awaiting": "Esperando la respuesta del proponente." },
  "attach": { "button": "Adjuntar archivo", "paste": "Pegar Markdown", "titleLabel": "Título", "markdownLabel": "Markdown", "add": "Añadir", "hint": "Markdown (.md, hasta 512 KB) o imágenes (PNG, JPEG, WebP, GIF, hasta 5 MB).", "remove": "Quitar {name}", "failed": "{name}: {error}" },
  "panel": { "close": "Cerrar", "raw": "Ver código fuente", "rendered": "Ver con formato", "download": "Descargar", "previous": "Anterior", "next": "Siguiente", "loading": "Cargando…", "loadFailed": "No se pudo cargar el adjunto." },
  "errors": { "sessionExpired": "Tu sesión ha caducado. Vuelve a iniciar sesión.", "forbidden": "No tienes permiso para hacer eso.", "locked": "Esta revisión ya se ha decidido y no se puede modificar.", "stale": "Ha cambiado desde que lo abriste. Recarga para ver la versión más reciente.", "reviewExists": "Ya has publicado una revisión. Recarga para editarla.", "tooLarge": "El archivo es demasiado grande.", "unsupportedType": "Ese tipo de archivo no es compatible.", "tooMany": "Esta publicación ya tiene el número máximo de adjuntos.", "invalidInput": "Revisa los datos introducidos.", "notFound": "No encontrado.", "generic": "Algo ha salido mal. Inténtalo de nuevo.", "reload": "Recargar" }
}
```

`fr`: `"projectReview": "Évaluation des projets"` and

```json
"Review": {
  "title": "Évaluation des projets",
  "intro": "Projets soumis en attente de l'évaluation des conseils consultatifs.",
  "notMember": "L'évaluation des projets est réservée aux membres du Conseil technique, du Conseil ecclésial et du Conseil d'administration. Si vous en faites partie, demandez à un administrateur de lier votre compte à votre fiche biographique.",
  "backToList": "Toutes les soumissions",
  "empty": "Aucune soumission ici.",
  "newer": "Plus récentes",
  "older": "Plus anciennes",
  "stage": { "in_review": "En évaluation", "awaiting_submitter": "En attente du proposant", "decided": "Décidées" },
  "vote": { "cdcf": "Accepter comme projet CDCF", "community": "Accepter comme projet communautaire", "reject": "Refuser" },
  "council": { "technical": "Conseil technique", "ecclesial": "Conseil ecclésial", "board": "Conseil d'administration" },
  "tally": { "heading": "Votes", "pending": "{count} n'ont pas encore voté" },
  "plurality": "Le plus de voix : {vote}",
  "yourVote": "Votre vote : {vote}",
  "awaitingYourVote": "En attente de votre vote",
  "unread": "{count} nouveaux",
  "submittedOn": "Soumis le {date}",
  "locked": "Cette évaluation a été tranchée. Les évaluations et la discussion sont désormais en lecture seule.",
  "edited": "modifié",
  "cancel": "Annuler",
  "save": "Enregistrer",
  "saving": "Enregistrement…",
  "edit": "Modifier",
  "submission": { "heading": "Soumission", "website": "Site web", "repositories": "Dépôts", "tags": "Étiquettes", "submitter": "Soumis par", "language": "Langue", "category": "Catégorie" },
  "myReview": { "heading": "Votre évaluation", "voteLabel": "Votre vote", "rationale": "Votre évaluation", "submit": "Publier l'évaluation", "update": "Mettre à jour l'évaluation", "edit": "Modifier votre évaluation", "voteRequired": "Choisissez d'abord un vote." },
  "editor": { "write": "Écrire", "preview": "Aperçu", "placeholder": "Le Markdown est pris en charge.", "emptyPreview": "Rien à afficher." },
  "reviews": { "heading": "Évaluations", "none": "Aucune évaluation pour l'instant.", "reply": "Répondre", "postReply": "Publier la réponse", "replyLabel": "Votre réponse" },
  "discussion": { "heading": "Discussion générale", "none": "Aucune discussion pour l'instant.", "post": "Publier", "label": "Contribuer à la discussion" },
  "questions": { "heading": "Questions au proposant", "none": "Aucune question n'a été envoyée au proposant.", "submitter": "Proposant", "awaiting": "En attente de la réponse du proposant." },
  "attach": { "button": "Joindre un fichier", "paste": "Coller du Markdown", "titleLabel": "Titre", "markdownLabel": "Markdown", "add": "Ajouter", "hint": "Markdown (.md, jusqu'à 512 Ko) ou images (PNG, JPEG, WebP, GIF, jusqu'à 5 Mo).", "remove": "Retirer {name}", "failed": "{name} : {error}" },
  "panel": { "close": "Fermer", "raw": "Afficher la source", "rendered": "Afficher le rendu", "download": "Télécharger", "previous": "Précédente", "next": "Suivante", "loading": "Chargement…", "loadFailed": "Impossible de charger la pièce jointe." },
  "errors": { "sessionExpired": "Votre session a expiré. Veuillez vous reconnecter.", "forbidden": "Vous n'avez pas l'autorisation de faire cela.", "locked": "Cette évaluation a été tranchée et ne peut plus être modifiée.", "stale": "Cet élément a changé depuis son ouverture. Rechargez pour voir la dernière version.", "reviewExists": "Vous avez déjà publié une évaluation. Rechargez pour la modifier.", "tooLarge": "Le fichier est trop volumineux.", "unsupportedType": "Ce type de fichier n'est pas pris en charge.", "tooMany": "Ce message a déjà le nombre maximal de pièces jointes.", "invalidInput": "Vérifiez votre saisie.", "notFound": "Introuvable.", "generic": "Une erreur s'est produite. Veuillez réessayer.", "reload": "Recharger" }
}
```

`pt`: `"projectReview": "Revisão de projetos"` and

```json
"Review": {
  "title": "Revisão de projetos",
  "intro": "Projetos submetidos que aguardam a revisão dos conselhos consultivos.",
  "notMember": "A revisão de projetos está aberta aos membros do Conselho Técnico, do Conselho Eclesial e da Direção. Se é um deles, peça a um administrador que associe a sua conta à sua ficha biográfica.",
  "backToList": "Todas as submissões",
  "empty": "Não há submissões aqui.",
  "newer": "Mais recentes",
  "older": "Mais antigas",
  "stage": { "in_review": "Em revisão", "awaiting_submitter": "A aguardar o proponente", "decided": "Decididas" },
  "vote": { "cdcf": "Aceitar como projeto CDCF", "community": "Aceitar como projeto comunitário", "reject": "Rejeitar" },
  "council": { "technical": "Conselho Técnico", "ecclesial": "Conselho Eclesial", "board": "Direção" },
  "tally": { "heading": "Votos", "pending": "{count} ainda não votaram" },
  "plurality": "Mais votado: {vote}",
  "yourVote": "O seu voto: {vote}",
  "awaitingYourVote": "A aguardar o seu voto",
  "unread": "{count} novos",
  "submittedOn": "Submetido em {date}",
  "locked": "Esta revisão já foi decidida. As revisões e a discussão são agora só de leitura.",
  "edited": "editado",
  "cancel": "Cancelar",
  "save": "Guardar",
  "saving": "A guardar…",
  "edit": "Editar",
  "submission": { "heading": "Submissão", "website": "Sítio web", "repositories": "Repositórios", "tags": "Etiquetas", "submitter": "Submetido por", "language": "Idioma", "category": "Categoria" },
  "myReview": { "heading": "A sua revisão", "voteLabel": "O seu voto", "rationale": "A sua revisão", "submit": "Publicar revisão", "update": "Atualizar revisão", "edit": "Editar a sua revisão", "voteRequired": "Escolha primeiro um voto." },
  "editor": { "write": "Escrever", "preview": "Pré-visualizar", "placeholder": "O Markdown é suportado.", "emptyPreview": "Nada para pré-visualizar." },
  "reviews": { "heading": "Revisões", "none": "Ainda não há revisões.", "reply": "Responder", "postReply": "Publicar resposta", "replyLabel": "A sua resposta" },
  "discussion": { "heading": "Discussão geral", "none": "Ainda não há discussão.", "post": "Publicar", "label": "Contribuir para a discussão" },
  "questions": { "heading": "Perguntas ao proponente", "none": "Não foram enviadas perguntas ao proponente.", "submitter": "Proponente", "awaiting": "A aguardar a resposta do proponente." },
  "attach": { "button": "Anexar ficheiro", "paste": "Colar Markdown", "titleLabel": "Título", "markdownLabel": "Markdown", "add": "Adicionar", "hint": "Markdown (.md, até 512 KB) ou imagens (PNG, JPEG, WebP, GIF, até 5 MB).", "remove": "Remover {name}", "failed": "{name}: {error}" },
  "panel": { "close": "Fechar", "raw": "Mostrar fonte", "rendered": "Mostrar formatado", "download": "Transferir", "previous": "Anterior", "next": "Seguinte", "loading": "A carregar…", "loadFailed": "Não foi possível carregar o anexo." },
  "errors": { "sessionExpired": "A sua sessão expirou. Inicie sessão novamente.", "forbidden": "Não tem permissão para fazer isso.", "locked": "Esta revisão já foi decidida e não pode ser alterada.", "stale": "Foi alterado desde que o abriu. Recarregue para ver a versão mais recente.", "reviewExists": "Já publicou uma revisão. Recarregue para a editar.", "tooLarge": "O ficheiro é demasiado grande.", "unsupportedType": "Esse tipo de ficheiro não é suportado.", "tooMany": "Esta publicação já tem o número máximo de anexos.", "invalidInput": "Verifique os dados introduzidos.", "notFound": "Não encontrado.", "generic": "Ocorreu um erro. Tente novamente.", "reload": "Recarregar" }
}
```

`de`: `"projectReview": "Projektprüfung"` and

```json
"Review": {
  "title": "Projektprüfung",
  "intro": "Eingereichte Projekte, die auf die Prüfung durch die Beiräte warten.",
  "notMember": "Die Projektprüfung steht Mitgliedern des Technischen Rates, des Kirchlichen Rates und des Vorstands offen. Wenn Sie dazugehören, bitten Sie eine Administratorin oder einen Administrator, Ihr Konto mit Ihrer Profilkarte zu verknüpfen.",
  "backToList": "Alle Einreichungen",
  "empty": "Hier gibt es keine Einreichungen.",
  "newer": "Neuere",
  "older": "Ältere",
  "stage": { "in_review": "In Prüfung", "awaiting_submitter": "Wartet auf Einreichende", "decided": "Entschieden" },
  "vote": { "cdcf": "Als CDCF-Projekt annehmen", "community": "Als Community-Projekt annehmen", "reject": "Ablehnen" },
  "council": { "technical": "Technischer Rat", "ecclesial": "Kirchlicher Rat", "board": "Vorstand" },
  "tally": { "heading": "Stimmen", "pending": "{count} haben noch nicht abgestimmt" },
  "plurality": "Meiste Stimmen: {vote}",
  "yourVote": "Ihre Stimme: {vote}",
  "awaitingYourVote": "Wartet auf Ihre Stimme",
  "unread": "{count} neu",
  "submittedOn": "Eingereicht am {date}",
  "locked": "Über diese Prüfung wurde entschieden. Bewertungen und Diskussion sind jetzt schreibgeschützt.",
  "edited": "bearbeitet",
  "cancel": "Abbrechen",
  "save": "Speichern",
  "saving": "Wird gespeichert …",
  "edit": "Bearbeiten",
  "submission": { "heading": "Einreichung", "website": "Website", "repositories": "Repositorys", "tags": "Schlagwörter", "submitter": "Eingereicht von", "language": "Sprache", "category": "Kategorie" },
  "myReview": { "heading": "Ihre Bewertung", "voteLabel": "Ihre Stimme", "rationale": "Ihre Bewertung", "submit": "Bewertung veröffentlichen", "update": "Bewertung aktualisieren", "edit": "Ihre Bewertung bearbeiten", "voteRequired": "Wählen Sie zuerst eine Stimme." },
  "editor": { "write": "Schreiben", "preview": "Vorschau", "placeholder": "Markdown wird unterstützt.", "emptyPreview": "Nichts anzuzeigen." },
  "reviews": { "heading": "Bewertungen", "none": "Noch keine Bewertungen.", "reply": "Antworten", "postReply": "Antwort veröffentlichen", "replyLabel": "Ihre Antwort" },
  "discussion": { "heading": "Allgemeine Diskussion", "none": "Noch keine Diskussion.", "post": "Veröffentlichen", "label": "Zur Diskussion beitragen" },
  "questions": { "heading": "Fragen an die Einreichenden", "none": "Es wurden keine Fragen an die Einreichenden gesendet.", "submitter": "Einreichende", "awaiting": "Wartet auf die Antwort der Einreichenden." },
  "attach": { "button": "Datei anhängen", "paste": "Markdown einfügen", "titleLabel": "Titel", "markdownLabel": "Markdown", "add": "Hinzufügen", "hint": "Markdown (.md, bis 512 KB) oder Bilder (PNG, JPEG, WebP, GIF, bis 5 MB).", "remove": "{name} entfernen", "failed": "{name}: {error}" },
  "panel": { "close": "Schließen", "raw": "Quelltext anzeigen", "rendered": "Formatiert anzeigen", "download": "Herunterladen", "previous": "Zurück", "next": "Weiter", "loading": "Wird geladen …", "loadFailed": "Der Anhang konnte nicht geladen werden." },
  "errors": { "sessionExpired": "Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.", "forbidden": "Dazu sind Sie nicht berechtigt.", "locked": "Über diese Prüfung wurde entschieden; sie kann nicht mehr geändert werden.", "stale": "Dies wurde seit dem Öffnen geändert. Laden Sie die Seite neu, um die aktuelle Fassung zu sehen.", "reviewExists": "Sie haben bereits eine Bewertung veröffentlicht. Laden Sie neu, um sie zu bearbeiten.", "tooLarge": "Die Datei ist zu groß.", "unsupportedType": "Dieser Dateityp wird nicht unterstützt.", "tooMany": "Dieser Beitrag hat bereits die maximale Anzahl an Anhängen.", "invalidInput": "Bitte prüfen Sie Ihre Eingabe.", "notFound": "Nicht gefunden.", "generic": "Etwas ist schiefgelaufen. Bitte versuchen Sie es erneut.", "reload": "Neu laden" }
}
```

- [ ] **Step 3: Run the parity check**

Run: `node scripts/tests/check-review-messages.mjs`
Expected: `Review messages OK: 104 keys × 6 locales` (the count is whatever
`en` defines; every locale must match it).

- [ ] **Step 4: Add the menu link in `components/AuthButton.tsx`**

Next to `const [hasBioLink, setHasBioLink] = useState(false)` add:

```tsx
const [hasReviewAccess, setHasReviewAccess] = useState(false);
```

Directly after the existing `useEffect` that fetches `/api/my-bio/check`,
add:

```tsx
// Same fire-and-forget pattern for council/Board review access.
useEffect(() => {
  if (status !== "authenticated") return;
  const controller = new AbortController();
  fetch("/api/review/me", { signal: controller.signal, cache: "no-store" })
    .then((res) => (res.ok ? res.json() : null))
    .then((body) => {
      if (body && typeof body === "object" && "member" in body) {
        setHasReviewAccess(Boolean(body.member));
      }
    })
    .catch(() => {
      /* aborted or network error — leave hasReviewAccess as-is */
    });
  return () => controller.abort();
}, [status]);
```

After the `{hasBioLink && (<Link href="/my-bio" …>…</Link>)}` block add:

```tsx
{
  hasReviewAccess && (
    <Link
      href="/review"
      role="menuitem"
      onClick={() => setIsOpen(false)}
      className="block px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
    >
      {t("projectReview")}
    </Link>
  );
}
```

- [ ] **Step 5: Remove the temporary check, lint, commit**

```bash
rm scripts/tests/check-review-messages.mjs
npx eslint components/AuthButton.tsx
git add messages components/AuthButton.tsx
git commit -m "feat(review): UI strings in six languages and header menu link

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

Expected: eslint clean.

---

### Task 8: The `/review` list page

**Files:**

- Create: `components/review/TallyBar.tsx`, `components/review/ProjectCard.tsx`, `components/review/ReviewNotice.tsx`
- Create: `app/[lang]/review/page.tsx`

**Interfaces:**

- Consumes: Tasks 4, 6, 7.
- Produces: `TallyBar({ tally, pending })`, `ProjectCard({ project, canVote })`,
  `ReviewNotice({ title, message })` — all server-safe (no `'use client'`).

- [ ] **Step 1: `components/review/TallyBar.tsx`**

```tsx
import clsx from "clsx";
import { useTranslations } from "next-intl";
import type { ReviewTally } from "@/lib/review-types";
import { tallySegments } from "@/lib/review-ui";

const COLORS = {
  cdcf: "bg-emerald-600",
  community: "bg-sky-600",
  reject: "bg-rose-600",
  pending: "bg-gray-300",
} as const;

export function TallyBar({
  tally,
  pending,
}: {
  tally: ReviewTally;
  pending: number;
}) {
  const t = useTranslations("Review");
  const segments = tallySegments(tally, pending);
  return (
    <div>
      <div
        className="flex h-2 w-full overflow-hidden rounded-full bg-gray-100"
        aria-hidden="true"
      >
        {segments
          .filter((s) => s.percent > 0)
          .map((s) => (
            <div
              key={s.key}
              className={COLORS[s.key]}
              style={{ width: `${s.percent}%` }}
            />
          ))}
      </div>
      <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
        {segments.map((s) => (
          <li key={s.key} className="flex items-center gap-1.5">
            <span
              className={clsx(
                "inline-block h-2 w-2 rounded-full",
                COLORS[s.key],
              )}
            />
            {s.key === "pending"
              ? t("tally.pending", { count: s.count })
              : `${t(`vote.${s.key}`)}: ${s.count}`}
          </li>
        ))}
      </ul>
    </div>
  );
}
```

- [ ] **Step 2: `components/review/ProjectCard.tsx`**

```tsx
import { useFormatter, useTranslations } from "next-intl";
import { Link } from "@/src/i18n/navigation";
import type { ReviewProjectSummary } from "@/lib/review-types";
import { parseWpGmt } from "@/lib/review-ui";
import { TallyBar } from "./TallyBar";

export function ProjectCard({
  project,
  canVote,
}: {
  project: ReviewProjectSummary;
  canVote: boolean;
}) {
  const t = useTranslations("Review");
  const format = useFormatter();
  const meta = [
    project.category,
    project.language.toUpperCase(),
    t("submittedOn", {
      date: format.dateTime(parseWpGmt(project.submitted_at), {
        dateStyle: "medium",
      }),
    }),
  ].filter(Boolean);
  return (
    <li>
      <Link
        href={`/review/${project.id}`}
        className="cdcf-card block space-y-3"
      >
        <div className="flex items-start justify-between gap-3">
          <h2 className="cdcf-heading text-lg">{project.title}</h2>
          {project.unread > 0 && (
            <span className="shrink-0 rounded-full bg-cdcf-navy px-2 py-0.5 text-xs font-medium text-white">
              {t("unread", { count: project.unread })}
            </span>
          )}
        </div>
        <p className="text-xs text-gray-500">{meta.join(" · ")}</p>
        <TallyBar tally={project.tally} pending={project.voters_pending} />
        {project.my_vote ? (
          <p className="text-xs text-gray-700">
            {t("yourVote", { vote: t(`vote.${project.my_vote}`) })}
          </p>
        ) : canVote && project.stage !== "decided" ? (
          <p className="text-xs font-medium text-amber-700">
            {t("awaitingYourVote")}
          </p>
        ) : null}
      </Link>
    </li>
  );
}
```

- [ ] **Step 3: `components/review/ReviewNotice.tsx`**

```tsx
export function ReviewNotice({
  title,
  message,
}: {
  title: string;
  message: string;
}) {
  return (
    <main className="cdcf-section">
      <div className="prose max-w-prose">
        <h1>{title}</h1>
        <p>{message}</p>
      </div>
    </main>
  );
}
```

- [ ] **Step 4: `app/[lang]/review/page.tsx`**

```tsx
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import clsx from "clsx";
import { auth } from "@/lib/auth";
import {
  ReviewApiError,
  fetchReviewMe,
  fetchReviewProjects,
} from "@/lib/review-api";
import {
  REVIEW_STAGES,
  type ReviewProjectList,
  type ReviewStage,
} from "@/lib/review-types";
import { Link } from "@/src/i18n/navigation";
import { ProjectCard } from "@/components/review/ProjectCard";
import { ReviewNotice } from "@/components/review/ReviewNotice";

export default async function ReviewListPage({
  searchParams,
}: {
  params: Promise<{ lang: string }>;
  searchParams: Promise<{ stage?: string; page?: string }>;
}) {
  const session = await auth();
  if (!session?.user) {
    redirect("/api/auth/signin");
  }
  const t = await getTranslations("Review");
  const query = await searchParams;
  const stage: ReviewStage = (REVIEW_STAGES as readonly string[]).includes(
    query.stage ?? "",
  )
    ? (query.stage as ReviewStage)
    : "in_review";
  const page = Math.max(1, Number.parseInt(query.page ?? "1", 10) || 1);

  let list: ReviewProjectList;
  let canVote = false;
  try {
    const me = await fetchReviewMe(session);
    if (!me.member) {
      return <ReviewNotice title={t("title")} message={t("notMember")} />;
    }
    canVote = me.can.vote;
    list = await fetchReviewProjects(session, { stage, page });
  } catch (err) {
    if (
      err instanceof ReviewApiError &&
      (err.status === 401 || err.status === 403)
    ) {
      return (
        <ReviewNotice
          title={t("title")}
          message={t(
            err.status === 401 ? "errors.sessionExpired" : "notMember",
          )}
        />
      );
    }
    throw err;
  }

  const href = (s: ReviewStage, p = 1) =>
    `/review?stage=${s}${p > 1 ? `&page=${p}` : ""}`;

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-4xl space-y-6">
        <header className="space-y-2">
          <h1 className="cdcf-heading text-3xl">{t("title")}</h1>
          <p className="text-gray-600">{t("intro")}</p>
        </header>
        <nav
          className="flex gap-2 border-b border-gray-200"
          aria-label={t("title")}
        >
          {REVIEW_STAGES.map((s) => (
            <Link
              key={s}
              href={href(s)}
              aria-current={s === stage ? "page" : undefined}
              className={clsx(
                "-mb-px border-b-2 px-3 py-2 text-sm font-medium",
                s === stage
                  ? "border-cdcf-navy text-cdcf-navy"
                  : "border-transparent text-gray-500 hover:text-gray-800",
              )}
            >
              {t(`stage.${s}`)}
            </Link>
          ))}
        </nav>
        {list.projects.length === 0 ? (
          <p className="text-gray-500">{t("empty")}</p>
        ) : (
          <ul className="space-y-4">
            {list.projects.map((project) => (
              <ProjectCard
                key={project.id}
                project={project}
                canVote={canVote}
              />
            ))}
          </ul>
        )}
        {(page > 1 || list.has_more) && (
          <nav className="flex justify-between text-sm">
            {page > 1 ? (
              <Link
                href={href(stage, page - 1)}
                className="text-cdcf-navy hover:underline"
              >
                ← {t("newer")}
              </Link>
            ) : (
              <span />
            )}
            {list.has_more && (
              <Link
                href={href(stage, page + 1)}
                className="text-cdcf-navy hover:underline"
              >
                {t("older")} →
              </Link>
            )}
          </nav>
        )}
      </div>
    </main>
  );
}
```

- [ ] **Step 5: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint components/review app/[lang]/review`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add components/review/TallyBar.tsx components/review/ProjectCard.tsx components/review/ReviewNotice.tsx "app/[lang]/review/page.tsx"
git commit -m "feat(review): submissions list page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 9: Composers and Markdown editor (client components)

**Files:**

- Create: `components/review/MarkdownEditor.tsx`, `components/review/AttachControl.tsx`,
  `components/review/PendingList.tsx`, `components/review/ComposerStatus.tsx`,
  `components/review/useEntrySubmit.ts`, `components/review/EntryComposer.tsx`,
  `components/review/MyReviewComposer.tsx`, `components/review/EntryActions.tsx`

**Interfaces:**

- Consumes: `lib/review-client.ts`, `lib/review-ui.ts`, `lib/review-types.ts`, `MarkdownView`.
- Produces:
  - `MarkdownEditor({ value, onChange, label, rows?, disabled? })`
  - `AttachControl({ onAdd: (p: PendingAttachment) => void, disabled? })`
  - `PendingList({ pending, onRemove })`
  - `ComposerStatus({ errorKey, failed })`
  - `useEntrySubmit(): { busy, errorKey, failed, submit(save, pending): Promise<boolean> }`
  - `EntryComposer({ projectId, kind: 'reply' | 'discussion', parentId? })`
  - `MyReviewComposer({ projectId, existing: { vote, body_md, version } | null })`
  - `EntryActions({ entryId, body, version, allowEdit })`

- [ ] **Step 1: `components/review/MarkdownEditor.tsx`**

```tsx
"use client";

import { useId, useState } from "react";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { MarkdownView } from "./MarkdownView";

export function MarkdownEditor({
  value,
  onChange,
  label,
  rows = 6,
  disabled = false,
}: {
  value: string;
  onChange: (value: string) => void;
  label: string;
  rows?: number;
  disabled?: boolean;
}) {
  const t = useTranslations("Review.editor");
  const [tab, setTab] = useState<"write" | "preview">("write");
  const id = useId();
  return (
    <div>
      <div className="flex items-center justify-between">
        <label
          htmlFor={id}
          className="block text-xs font-medium uppercase tracking-wide text-gray-500"
        >
          {label}
        </label>
        <div role="tablist" className="flex gap-1 text-xs">
          {(["write", "preview"] as const).map((k) => (
            <button
              key={k}
              type="button"
              role="tab"
              aria-selected={tab === k}
              onClick={() => setTab(k)}
              className={clsx(
                "rounded px-2 py-1",
                tab === k
                  ? "bg-cdcf-navy text-white"
                  : "text-gray-600 hover:bg-gray-100",
              )}
            >
              {t(k)}
            </button>
          ))}
        </div>
      </div>
      {tab === "write" ? (
        <textarea
          id={id}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          rows={rows}
          disabled={disabled}
          placeholder={t("placeholder")}
          className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
        />
      ) : (
        <div className="mt-1 min-h-24 rounded-md border border-gray-200 px-3 py-2">
          {value.trim() ? (
            <MarkdownView source={value} />
          ) : (
            <p className="text-sm text-gray-400">{t("emptyPreview")}</p>
          )}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: `components/review/AttachControl.tsx`**

```tsx
"use client";

import { useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { DocumentTextIcon, PaperClipIcon } from "@heroicons/react/24/outline";
import type { PendingAttachment } from "@/lib/review-types";
import { checkAttachmentFile, type ReviewErrorKey } from "@/lib/review-ui";

const ACCEPT =
  ".md,.png,.jpg,.jpeg,.webp,.gif,text/markdown,image/png,image/jpeg,image/webp,image/gif";

let counter = 0;
const nextKey = () => `pending-${Date.now()}-${counter++}`;

export function AttachControl({
  onAdd,
  disabled = false,
}: {
  onAdd: (p: PendingAttachment) => void;
  disabled?: boolean;
}) {
  const t = useTranslations("Review");
  const input = useRef<HTMLInputElement>(null);
  const [pasting, setPasting] = useState(false);
  const [title, setTitle] = useState("");
  const [markdown, setMarkdown] = useState("");
  const [rejected, setRejected] = useState<
    { name: string; errorKey: ReviewErrorKey }[]
  >([]);

  function onFiles(files: FileList | null) {
    const problems: { name: string; errorKey: ReviewErrorKey }[] = [];
    for (const file of Array.from(files ?? [])) {
      const problem = checkAttachmentFile(file.name, file.size);
      if (problem) problems.push({ name: file.name, errorKey: problem });
      else onAdd({ key: nextKey(), kind: "file", file });
    }
    setRejected(problems);
    if (input.current) input.current.value = "";
  }

  function addPaste() {
    if (!markdown.trim()) return;
    onAdd({ key: nextKey(), kind: "paste", title, markdown });
    setTitle("");
    setMarkdown("");
    setPasting(false);
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <button
          type="button"
          disabled={disabled}
          onClick={() => input.current?.click()}
          className="inline-flex items-center gap-1 text-cdcf-navy hover:underline disabled:opacity-50"
        >
          <PaperClipIcon className="h-4 w-4" aria-hidden="true" />
          {t("attach.button")}
        </button>
        <button
          type="button"
          disabled={disabled}
          onClick={() => setPasting((p) => !p)}
          className="inline-flex items-center gap-1 text-cdcf-navy hover:underline disabled:opacity-50"
        >
          <DocumentTextIcon className="h-4 w-4" aria-hidden="true" />
          {t("attach.paste")}
        </button>
        <input
          ref={input}
          type="file"
          accept={ACCEPT}
          multiple
          hidden
          onChange={(e) => onFiles(e.target.files)}
        />
      </div>
      <p className="text-xs text-gray-500">{t("attach.hint")}</p>
      {rejected.map((r) => (
        <p key={r.name} className="text-xs text-red-700">
          {t("attach.failed", {
            name: r.name,
            error: t(`errors.${r.errorKey}`),
          })}
        </p>
      ))}
      {pasting && (
        <div className="space-y-2 rounded-md border border-gray-200 p-3">
          <label className="block text-xs font-medium uppercase tracking-wide text-gray-500">
            {t("attach.titleLabel")}
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm normal-case tracking-normal"
            />
          </label>
          <label className="block text-xs font-medium uppercase tracking-wide text-gray-500">
            {t("attach.markdownLabel")}
            <textarea
              value={markdown}
              onChange={(e) => setMarkdown(e.target.value)}
              rows={8}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm normal-case tracking-normal"
            />
          </label>
          <div className="flex gap-3 text-sm">
            <button
              type="button"
              onClick={addPaste}
              disabled={!markdown.trim()}
              className="cdcf-btn-secondary px-3 py-1.5 text-sm"
            >
              {t("attach.add")}
            </button>
            <button
              type="button"
              onClick={() => setPasting(false)}
              className="text-gray-600 hover:underline"
            >
              {t("cancel")}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 3: `components/review/PendingList.tsx`**

```tsx
"use client";

import { useTranslations } from "next-intl";
import { XMarkIcon } from "@heroicons/react/24/outline";
import type { PendingAttachment } from "@/lib/review-types";
import { pendingName } from "@/lib/review-ui";

export function PendingList({
  pending,
  onRemove,
}: {
  pending: PendingAttachment[];
  onRemove: (key: string) => void;
}) {
  const t = useTranslations("Review");
  if (pending.length === 0) return null;
  return (
    <ul className="flex flex-wrap gap-2">
      {pending.map((p) => {
        const name = pendingName(p);
        return (
          <li
            key={p.key}
            className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
          >
            {name}
            <button
              type="button"
              onClick={() => onRemove(p.key)}
              aria-label={t("attach.remove", { name })}
              className="rounded-full p-0.5 hover:bg-gray-200"
            >
              <XMarkIcon className="h-3 w-3" aria-hidden="true" />
            </button>
          </li>
        );
      })}
    </ul>
  );
}
```

- [ ] **Step 4: `components/review/ComposerStatus.tsx`**

```tsx
"use client";

import { useTranslations } from "next-intl";
import type { ReviewErrorKey } from "@/lib/review-ui";

export function ComposerStatus({
  errorKey,
  failed,
}: {
  errorKey: ReviewErrorKey | null;
  failed: { name: string; errorKey: ReviewErrorKey }[];
}) {
  const t = useTranslations("Review");
  if (!errorKey && failed.length === 0) return null;
  const needsReload = errorKey === "stale" || errorKey === "reviewExists";
  return (
    <div className="space-y-1 text-sm text-red-700" role="alert">
      {errorKey && (
        <p>
          {t(`errors.${errorKey}`)}{" "}
          {needsReload && (
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="font-medium underline"
            >
              {t("errors.reload")}
            </button>
          )}
        </p>
      )}
      {failed.map((f) => (
        <p key={f.name}>
          {t("attach.failed", {
            name: f.name,
            error: t(`errors.${f.errorKey}`),
          })}
        </p>
      ))}
    </div>
  );
}
```

- [ ] **Step 5: `components/review/useEntrySubmit.ts`**

```ts
"use client";

import { useState } from "react";
import { useRouter } from "@/src/i18n/navigation";
import { uploadAttachment, type ReviewResult } from "@/lib/review-client";
import type { PendingAttachment, ReviewEntry } from "@/lib/review-types";
import { pendingName, type ReviewErrorKey } from "@/lib/review-ui";

/**
 * Save an entry, then upload its pending attachments one by one (they can
 * only be attached once the entry exists), then refresh the server page.
 * An attachment failure does not undo the saved entry; it is reported.
 */
export function useEntrySubmit() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null);
  const [failed, setFailed] = useState<
    { name: string; errorKey: ReviewErrorKey }[]
  >([]);

  async function submit(
    save: () => Promise<ReviewResult<ReviewEntry>>,
    pending: PendingAttachment[],
  ): Promise<boolean> {
    setBusy(true);
    setErrorKey(null);
    setFailed([]);
    const saved = await save();
    if (!saved.ok) {
      setErrorKey(saved.errorKey);
      setBusy(false);
      return false;
    }
    const failures: { name: string; errorKey: ReviewErrorKey }[] = [];
    for (const p of pending) {
      const result = await uploadAttachment(saved.data.id, p);
      if (!result.ok)
        failures.push({ name: pendingName(p), errorKey: result.errorKey });
    }
    setFailed(failures);
    setBusy(false);
    router.refresh();
    return true;
  }

  return { busy, errorKey, failed, submit };
}
```

- [ ] **Step 6: `components/review/EntryComposer.tsx`**

```tsx
"use client";

import { useState, type FormEvent } from "react";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { jsonInit, reviewFetch } from "@/lib/review-client";
import type { PendingAttachment, ReviewEntry } from "@/lib/review-types";
import { AttachControl } from "./AttachControl";
import { ComposerStatus } from "./ComposerStatus";
import { MarkdownEditor } from "./MarkdownEditor";
import { PendingList } from "./PendingList";
import { useEntrySubmit } from "./useEntrySubmit";

export function EntryComposer({
  projectId,
  kind,
  parentId,
}: {
  projectId: number;
  kind: "reply" | "discussion";
  parentId?: number;
}) {
  const t = useTranslations("Review");
  const [open, setOpen] = useState(kind === "discussion");
  const [body, setBody] = useState("");
  const [pending, setPending] = useState<PendingAttachment[]>([]);
  const { busy, errorKey, failed, submit } = useEntrySubmit();

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="text-sm font-medium text-cdcf-navy hover:underline"
      >
        {t("reviews.reply")}
      </button>
    );
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const ok = await submit(
      () =>
        reviewFetch<ReviewEntry>(
          `/api/review/projects/${projectId}/entries`,
          jsonInit("POST", {
            kind,
            body_md: body,
            ...(kind === "reply" ? { parent_id: parentId } : {}),
          }),
        ),
      pending,
    );
    if (ok) {
      setBody("");
      setPending([]);
      if (kind === "reply") setOpen(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-3">
      <MarkdownEditor
        value={body}
        onChange={setBody}
        label={
          kind === "reply" ? t("reviews.replyLabel") : t("discussion.label")
        }
        rows={kind === "reply" ? 3 : 4}
        disabled={busy}
      />
      <PendingList
        pending={pending}
        onRemove={(key) => setPending((ps) => ps.filter((p) => p.key !== key))}
      />
      <AttachControl
        onAdd={(p) => setPending((ps) => [...ps, p])}
        disabled={busy}
      />
      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={busy || !body.trim()}
          className={clsx(
            "cdcf-btn-primary px-4 py-2 text-sm",
            (busy || !body.trim()) && "cursor-not-allowed opacity-60",
          )}
        >
          {busy
            ? t("saving")
            : kind === "reply"
              ? t("reviews.postReply")
              : t("discussion.post")}
        </button>
        {kind === "reply" && (
          <button
            type="button"
            onClick={() => setOpen(false)}
            className="text-sm text-gray-600 hover:underline"
          >
            {t("cancel")}
          </button>
        )}
      </div>
      <ComposerStatus errorKey={errorKey} failed={failed} />
    </form>
  );
}
```

- [ ] **Step 7: `components/review/MyReviewComposer.tsx`**

```tsx
"use client";

import { useState, type FormEvent } from "react";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { jsonInit, reviewFetch } from "@/lib/review-client";
import {
  REVIEW_VOTES,
  type PendingAttachment,
  type ReviewEntry,
  type ReviewVote,
} from "@/lib/review-types";
import { AttachControl } from "./AttachControl";
import { ComposerStatus } from "./ComposerStatus";
import { MarkdownEditor } from "./MarkdownEditor";
import { PendingList } from "./PendingList";
import { useEntrySubmit } from "./useEntrySubmit";

export function MyReviewComposer({
  projectId,
  existing,
}: {
  projectId: number;
  existing: { vote: ReviewVote; body_md: string; version: string } | null;
}) {
  const t = useTranslations("Review");
  const [editing, setEditing] = useState(existing === null);
  const [vote, setVote] = useState<ReviewVote | null>(existing?.vote ?? null);
  const [body, setBody] = useState(existing?.body_md ?? "");
  const [pending, setPending] = useState<PendingAttachment[]>([]);
  const [voteMissing, setVoteMissing] = useState(false);
  const { busy, errorKey, failed, submit } = useEntrySubmit();

  if (!editing && existing) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
        <p className="text-sm text-gray-700">
          {t("yourVote", { vote: t(`vote.${existing.vote}`) })}
        </p>
        <button
          type="button"
          onClick={() => setEditing(true)}
          className="cdcf-btn-secondary px-3 py-1.5 text-sm"
        >
          {t("myReview.edit")}
        </button>
      </div>
    );
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!vote) {
      setVoteMissing(true);
      return;
    }
    setVoteMissing(false);
    const ok = await submit(
      () =>
        reviewFetch<ReviewEntry>(
          `/api/review/projects/${projectId}/my-review`,
          jsonInit("PUT", {
            vote,
            body_md: body,
            ...(existing ? { version: existing.version } : {}),
          }),
        ),
      pending,
    );
    if (ok) {
      setPending([]);
      if (existing) setEditing(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="cdcf-card space-y-4">
      <h2 className="cdcf-heading text-xl">{t("myReview.heading")}</h2>
      <fieldset disabled={busy} className="space-y-2">
        <legend className="text-xs font-medium uppercase tracking-wide text-gray-500">
          {t("myReview.voteLabel")}
        </legend>
        <div className="flex flex-wrap gap-2">
          {REVIEW_VOTES.map((v) => (
            <label
              key={v}
              className={clsx(
                "cursor-pointer rounded-md border px-3 py-2 text-sm",
                vote === v
                  ? "border-cdcf-navy bg-cdcf-navy text-white"
                  : "border-gray-300 text-gray-700 hover:border-cdcf-navy",
              )}
            >
              <input
                type="radio"
                name="vote"
                value={v}
                checked={vote === v}
                onChange={() => setVote(v)}
                className="sr-only"
              />
              {t(`vote.${v}`)}
            </label>
          ))}
        </div>
        {voteMissing && (
          <p className="text-sm text-red-700">{t("myReview.voteRequired")}</p>
        )}
      </fieldset>
      <MarkdownEditor
        value={body}
        onChange={setBody}
        label={t("myReview.rationale")}
        rows={8}
        disabled={busy}
      />
      <PendingList
        pending={pending}
        onRemove={(key) => setPending((ps) => ps.filter((p) => p.key !== key))}
      />
      <AttachControl
        onAdd={(p) => setPending((ps) => [...ps, p])}
        disabled={busy}
      />
      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={busy || !body.trim()}
          className={clsx(
            "cdcf-btn-primary px-4 py-2 text-sm",
            (busy || !body.trim()) && "cursor-not-allowed opacity-60",
          )}
        >
          {busy
            ? t("saving")
            : existing
              ? t("myReview.update")
              : t("myReview.submit")}
        </button>
        {existing && (
          <button
            type="button"
            onClick={() => setEditing(false)}
            className="text-sm text-gray-600 hover:underline"
          >
            {t("cancel")}
          </button>
        )}
      </div>
      <ComposerStatus errorKey={errorKey} failed={failed} />
    </form>
  );
}
```

- [ ] **Step 8: `components/review/EntryActions.tsx`**

```tsx
"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "@/src/i18n/navigation";
import { jsonInit, reviewFetch, uploadAttachment } from "@/lib/review-client";
import type { PendingAttachment, ReviewEntry } from "@/lib/review-types";
import { pendingName, type ReviewErrorKey } from "@/lib/review-ui";
import { AttachControl } from "./AttachControl";
import { ComposerStatus } from "./ComposerStatus";
import { MarkdownEditor } from "./MarkdownEditor";

/**
 * Author-only controls on an existing entry: inline edit (replies and
 * discussion posts; reviews are edited through MyReviewComposer) and
 * attaching more files.
 */
export function EntryActions({
  entryId,
  body,
  version,
  allowEdit,
}: {
  entryId: number;
  body: string;
  version: string;
  allowEdit: boolean;
}) {
  const t = useTranslations("Review");
  const router = useRouter();
  const [editing, setEditing] = useState(false);
  const [attaching, setAttaching] = useState(false);
  const [draft, setDraft] = useState(body);
  const [busy, setBusy] = useState(false);
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null);
  const [failed, setFailed] = useState<
    { name: string; errorKey: ReviewErrorKey }[]
  >([]);

  async function save() {
    setBusy(true);
    setErrorKey(null);
    const result = await reviewFetch<ReviewEntry>(
      `/api/review/entries/${entryId}`,
      jsonInit("PATCH", { body_md: draft, version }),
    );
    setBusy(false);
    if (!result.ok) {
      setErrorKey(result.errorKey);
      return;
    }
    setEditing(false);
    router.refresh();
  }

  async function attach(p: PendingAttachment) {
    setBusy(true);
    const result = await uploadAttachment(entryId, p);
    setBusy(false);
    setFailed(
      result.ok ? [] : [{ name: pendingName(p), errorKey: result.errorKey }],
    );
    if (result.ok) router.refresh();
  }

  if (editing) {
    return (
      <div className="space-y-2">
        <MarkdownEditor
          value={draft}
          onChange={setDraft}
          label={t("edit")}
          rows={4}
          disabled={busy}
        />
        <div className="flex gap-3 text-sm">
          <button
            type="button"
            onClick={save}
            disabled={busy || !draft.trim()}
            className="cdcf-btn-primary px-3 py-1.5 text-sm"
          >
            {busy ? t("saving") : t("save")}
          </button>
          <button
            type="button"
            onClick={() => setEditing(false)}
            className="text-gray-600 hover:underline"
          >
            {t("cancel")}
          </button>
        </div>
        <ComposerStatus errorKey={errorKey} failed={[]} />
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex gap-3 text-xs">
        {allowEdit && (
          <button
            type="button"
            onClick={() => setEditing(true)}
            className="text-cdcf-navy hover:underline"
          >
            {t("edit")}
          </button>
        )}
        <button
          type="button"
          onClick={() => setAttaching((a) => !a)}
          className="text-cdcf-navy hover:underline"
        >
          {t("attach.button")}
        </button>
      </div>
      {attaching && <AttachControl onAdd={attach} disabled={busy} />}
      <ComposerStatus errorKey={null} failed={failed} />
    </div>
  );
}
```

- [ ] **Step 9: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint components/review`
Expected: no errors. If `react-hooks` flags anything, fix the component
(never disable the rule).

- [ ] **Step 10: Commit**

```bash
git add components/review
git commit -m "feat(review): Markdown editor, composers and entry actions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 10: Attachment chips and the side panel

**Files:**

- Create: `components/review/AttachmentChips.tsx`, `components/review/AttachmentPanel.tsx`

**Interfaces:**

- Consumes: `withAttachmentParam`, `parseAttachmentParam`, `imageNeighbours`, `formatBytes`, `AttachmentIndex` (Task 6); `MarkdownView`.
- Produces: `AttachmentChips({ attachments })`, `AttachmentPanel({ index })`.

- [ ] **Step 1: `components/review/AttachmentChips.tsx`**

```tsx
"use client";

import { useSearchParams } from "next/navigation";
import { DocumentTextIcon, PhotoIcon } from "@heroicons/react/24/outline";
import { usePathname, useRouter } from "@/src/i18n/navigation";
import type { ReviewAttachment } from "@/lib/review-types";
import { formatBytes, withAttachmentParam } from "@/lib/review-ui";

export function AttachmentChips({
  attachments,
}: {
  attachments: ReviewAttachment[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  if (attachments.length === 0) return null;
  return (
    <ul className="flex flex-wrap gap-2">
      {attachments.map((a) => {
        const Icon = a.kind === "image" ? PhotoIcon : DocumentTextIcon;
        return (
          <li key={a.id}>
            <button
              type="button"
              onClick={() =>
                router.replace(
                  `${pathname}${withAttachmentParam(searchParams.toString(), a.id)}`,
                  { scroll: false },
                )
              }
              className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs text-gray-700 hover:border-cdcf-navy hover:text-cdcf-navy"
            >
              <Icon className="h-4 w-4" aria-hidden="true" />
              {a.name}
              <span className="text-gray-400">{formatBytes(a.size)}</span>
            </button>
          </li>
        );
      })}
    </ul>
  );
}
```

- [ ] **Step 2: `components/review/AttachmentPanel.tsx`**

```tsx
"use client";

import { useCallback, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { XMarkIcon } from "@heroicons/react/24/outline";
import { usePathname, useRouter } from "@/src/i18n/navigation";
import type { ReviewAttachment } from "@/lib/review-types";
import {
  imageNeighbours,
  parseAttachmentParam,
  withAttachmentParam,
  type AttachmentIndex,
} from "@/lib/review-ui";
import { MarkdownView } from "./MarkdownView";

/**
 * Side panel (45% width on desktop, full screen on mobile) for the
 * attachment named by ?att=<id>. The URL is the state, so a link opens
 * the same attachment and Back closes it.
 */
export function AttachmentPanel({ index }: { index: AttachmentIndex }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const id = parseAttachmentParam(searchParams.get("att"));
  const item = id !== null ? index[String(id)] : undefined;

  const go = useCallback(
    (next: number | null) =>
      router.replace(
        `${pathname}${withAttachmentParam(searchParams.toString(), next)}`,
        { scroll: false },
      ),
    [router, pathname, searchParams],
  );

  useEffect(() => {
    if (!item) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") go(null);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [item, go]);

  if (!item) return null;
  // Keyed by id so per-attachment state (text, raw toggle, zoom) resets.
  return (
    <AttachmentView
      key={item.attachment.id}
      attachment={item.attachment}
      siblings={item.siblings}
      go={go}
    />
  );
}

function AttachmentView({
  attachment,
  siblings,
  go,
}: {
  attachment: ReviewAttachment;
  siblings: ReviewAttachment[];
  go: (id: number | null) => void;
}) {
  const t = useTranslations("Review.panel");
  const [text, setText] = useState<string | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [raw, setRaw] = useState(false);
  const [zoomed, setZoomed] = useState(false);
  const src = `/api/review/attachments/${attachment.id}`;

  useEffect(() => {
    if (attachment.kind !== "markdown") return;
    const controller = new AbortController();
    fetch(src, { signal: controller.signal, cache: "no-store" })
      .then((res) =>
        res.ok ? res.text() : Promise.reject(new Error(String(res.status))),
      )
      .then(setText)
      .catch(() => {
        if (!controller.signal.aborted) setLoadFailed(true);
      });
    return () => controller.abort();
  }, [attachment.kind, src]);

  const { prev, next } = imageNeighbours(siblings, attachment.id);

  return (
    <aside
      aria-label={attachment.name}
      className="fixed inset-0 z-40 flex flex-col bg-white shadow-xl md:inset-y-0 md:left-auto md:right-0 md:w-[45vw] md:border-l md:border-gray-200"
    >
      <header className="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3">
        <h2 className="truncate text-sm font-semibold text-gray-900">
          {attachment.name}
        </h2>
        <div className="flex shrink-0 items-center gap-3 text-sm">
          {attachment.kind === "markdown" && (
            <button
              type="button"
              onClick={() => setRaw((r) => !r)}
              className="text-cdcf-navy hover:underline"
            >
              {raw ? t("rendered") : t("raw")}
            </button>
          )}
          <a
            href={`${src}?download=1`}
            className="text-cdcf-navy hover:underline"
          >
            {t("download")}
          </a>
          <button
            type="button"
            onClick={() => go(null)}
            aria-label={t("close")}
            className="rounded p-1 hover:bg-gray-100"
          >
            <XMarkIcon className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
      </header>
      <div className="flex-1 overflow-auto p-4">
        {attachment.kind === "image" ? (
          <div className="flex h-full flex-col">
            <div
              className={clsx(
                "flex-1",
                zoomed ? "overflow-auto" : "flex items-center justify-center",
              )}
            >
              {/* eslint-disable-next-line @next/next/no-img-element -- authenticated same-origin stream; next/image would refetch it server-side without the session cookie */}
              <img
                src={src}
                alt={attachment.name}
                onClick={() => setZoomed((z) => !z)}
                className={clsx(
                  zoomed
                    ? "max-w-none cursor-zoom-out"
                    : "max-h-full max-w-full cursor-zoom-in object-contain",
                )}
              />
            </div>
            {(prev !== null || next !== null) && (
              <div className="mt-3 flex justify-between text-sm">
                <button
                  type="button"
                  disabled={prev === null}
                  onClick={() => prev !== null && go(prev)}
                  className="text-cdcf-navy hover:underline disabled:opacity-40"
                >
                  ← {t("previous")}
                </button>
                <button
                  type="button"
                  disabled={next === null}
                  onClick={() => next !== null && go(next)}
                  className="text-cdcf-navy hover:underline disabled:opacity-40"
                >
                  {t("next")} →
                </button>
              </div>
            )}
          </div>
        ) : loadFailed ? (
          <p className="text-sm text-red-700">{t("loadFailed")}</p>
        ) : text === null ? (
          <p className="text-sm text-gray-500">{t("loading")}</p>
        ) : raw ? (
          <pre className="whitespace-pre-wrap break-words font-mono text-xs">
            {text}
          </pre>
        ) : (
          <MarkdownView source={text} />
        )}
      </div>
    </aside>
  );
}
```

- [ ] **Step 3: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint components/review`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add components/review/AttachmentChips.tsx components/review/AttachmentPanel.tsx
git commit -m "feat(review): attachment chips and side panel viewer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 11: The project workspace page

**Files:**

- Create: `components/review/SubmissionCard.tsx`, `components/review/EntryCard.tsx`,
  `components/review/ReviewCard.tsx`, `components/review/QuestionsList.tsx`
- Create: `app/[lang]/review/[id]/page.tsx`

**Interfaces:**

- Consumes: everything above.
- Produces: `SubmissionCard({ project })`, `EntryCard({ entry, currentUserId, locked, allowEdit, badge? })`,
  `ReviewCard({ review, projectId, currentUserId, locked })`, `QuestionsList({ questions })`.

- [ ] **Step 1: `components/review/SubmissionCard.tsx`**

```tsx
import { useFormatter, useTranslations } from "next-intl";
import type { ReviewProject } from "@/lib/review-types";
import { parseWpGmt } from "@/lib/review-ui";

const external = {
  target: "_blank",
  rel: "noopener noreferrer nofollow",
} as const;

export function SubmissionCard({ project }: { project: ReviewProject }) {
  const t = useTranslations("Review");
  const format = useFormatter();
  const { fields, submitter } = project;
  return (
    <section className="cdcf-card space-y-4">
      <div className="space-y-1">
        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
          {t("submission.heading")}
        </p>
        <h1 className="cdcf-heading text-3xl">{project.title}</h1>
        <p className="text-xs text-gray-500">
          {t("submittedOn", {
            date: format.dateTime(parseWpGmt(project.submitted_at), {
              dateStyle: "long",
            }),
          })}{" "}
          · {t(`stage.${project.stage}`)}
        </p>
      </div>
      {/* Public submission text: shown as plain text, never as HTML. */}
      <p className="whitespace-pre-line text-gray-800">{project.content}</p>
      <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        {fields.category && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t("submission.category")}
            </dt>
            <dd>{fields.category}</dd>
          </div>
        )}
        <div>
          <dt className="text-xs uppercase tracking-wide text-gray-500">
            {t("submission.language")}
          </dt>
          <dd>{project.language.toUpperCase()}</dd>
        </div>
        {fields.url && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t("submission.website")}
            </dt>
            <dd>
              <a
                href={fields.url}
                {...external}
                className="break-all text-cdcf-navy hover:underline"
              >
                {fields.url}
              </a>
            </dd>
          </div>
        )}
        {fields.repo_urls.length > 0 && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t("submission.repositories")}
            </dt>
            <dd className="space-y-1">
              {fields.repo_urls.map((url) => (
                <a
                  key={url}
                  href={url}
                  {...external}
                  className="block break-all text-cdcf-navy hover:underline"
                >
                  {url}
                </a>
              ))}
            </dd>
          </div>
        )}
        {fields.tags.length > 0 && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t("submission.tags")}
            </dt>
            <dd>{fields.tags.join(", ")}</dd>
          </div>
        )}
        <div>
          <dt className="text-xs uppercase tracking-wide text-gray-500">
            {t("submission.submitter")}
          </dt>
          <dd>
            {submitter.name}
            {submitter.email && (
              <span className="text-gray-500"> &lt;{submitter.email}&gt;</span>
            )}
          </dd>
        </div>
      </dl>
    </section>
  );
}
```

- [ ] **Step 2: `components/review/EntryCard.tsx`**

```tsx
import type { ReactNode } from "react";
import { useFormatter, useTranslations } from "next-intl";
import type { ReviewEntry } from "@/lib/review-types";
import { parseWpGmt } from "@/lib/review-ui";
import { AttachmentChips } from "./AttachmentChips";
import { EntryActions } from "./EntryActions";
import { MarkdownView } from "./MarkdownView";

export function EntryCard({
  entry,
  currentUserId,
  locked,
  allowEdit,
  badge,
}: {
  entry: ReviewEntry;
  currentUserId: number;
  locked: boolean;
  allowEdit: boolean;
  badge?: ReactNode;
}) {
  const t = useTranslations("Review");
  const format = useFormatter();
  const isMine = entry.author?.id === currentUserId;
  return (
    <article id={`entry-${entry.id}`} className="space-y-2">
      <header className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span className="font-semibold text-gray-900">
          {entry.author ? entry.author.name : t("questions.submitter")}
        </span>
        {entry.author?.councils.map((c) => (
          <span
            key={c}
            className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600"
          >
            {t(`council.${c}`)}
          </span>
        ))}
        {badge}
        <span className="text-xs text-gray-500">
          {format.dateTime(parseWpGmt(entry.created_at), {
            dateStyle: "medium",
            timeStyle: "short",
          })}
          {entry.edited_at && ` · ${t("edited")}`}
        </span>
      </header>
      <MarkdownView source={entry.body_md} />
      <AttachmentChips attachments={entry.attachments} />
      {isMine && !locked && (
        <EntryActions
          entryId={entry.id}
          body={entry.body_md}
          version={entry.version}
          allowEdit={allowEdit}
        />
      )}
    </article>
  );
}
```

`parseWpGmt` receives `created_at` values like `2026-10-15 10:00:00.000000`;
`new Date('2026-10-15T10:00:00.000000Z')` parses in V8 (sub-millisecond digits
are accepted). Task 6's test covers the seconds form; add this case to
`lib/review-ui.test.ts` in this task:

```ts
it("parses microsecond datetimes from the review tables", () => {
  expect(parseWpGmt("2026-10-15 10:00:00.123456").toISOString()).toBe(
    "2026-10-15T10:00:00.123Z",
  );
});
```

(inside the existing `describe('formatting', …)` block). Run
`npx vitest run lib/review-ui.test.ts` — Expected: PASS.

- [ ] **Step 3: `components/review/ReviewCard.tsx`**

```tsx
import { useTranslations } from "next-intl";
import type { ReviewWithReplies } from "@/lib/review-types";
import { EntryCard } from "./EntryCard";
import { EntryComposer } from "./EntryComposer";

export function ReviewCard({
  review,
  projectId,
  currentUserId,
  locked,
}: {
  review: ReviewWithReplies;
  projectId: number;
  currentUserId: number;
  locked: boolean;
}) {
  const t = useTranslations("Review");
  return (
    <li className="cdcf-card space-y-4">
      <EntryCard
        entry={review}
        currentUserId={currentUserId}
        locked={locked}
        allowEdit={false}
        badge={
          review.vote && (
            <span className="rounded bg-cdcf-navy px-1.5 py-0.5 text-xs font-medium text-white">
              {t(`vote.${review.vote}`)}
            </span>
          )
        }
      />
      {review.replies.length > 0 && (
        <ul className="space-y-4 border-l-2 border-gray-100 pl-4">
          {review.replies.map((reply) => (
            <li key={reply.id}>
              <EntryCard
                entry={reply}
                currentUserId={currentUserId}
                locked={locked}
                allowEdit
              />
            </li>
          ))}
        </ul>
      )}
      {!locked && (
        <div className="pl-4">
          <EntryComposer
            projectId={projectId}
            kind="reply"
            parentId={review.id}
          />
        </div>
      )}
    </li>
  );
}
```

- [ ] **Step 4: `components/review/QuestionsList.tsx`**

```tsx
import { useTranslations } from "next-intl";
import type { QuestionWithAnswers } from "@/lib/review-types";
import { MarkdownView } from "./MarkdownView";

/** Read-only in Phase 2; asking questions arrives with Phase 3. */
export function QuestionsList({
  questions,
}: {
  questions: QuestionWithAnswers[];
}) {
  const t = useTranslations("Review");
  if (questions.length === 0)
    return <p className="text-sm text-gray-500">{t("questions.none")}</p>;
  return (
    <ul className="space-y-4">
      {questions.map((q) => (
        <li key={q.id} className="cdcf-card space-y-3">
          <MarkdownView source={q.body_md} />
          {q.answers.length === 0 ? (
            <p className="text-sm italic text-gray-500">
              {t("questions.awaiting")}
            </p>
          ) : (
            q.answers.map((a) => (
              <div key={a.id} className="border-l-2 border-cdcf-navy pl-3">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                  {t("questions.submitter")}
                </p>
                <p className="whitespace-pre-line text-sm text-gray-800">
                  {a.body_md}
                </p>
              </div>
            ))
          )}
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 5: `app/[lang]/review/[id]/page.tsx`**

```tsx
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { auth } from "@/lib/auth";
import {
  ReviewApiError,
  fetchReviewMe,
  fetchReviewProject,
} from "@/lib/review-api";
import type { ReviewMe, ReviewProject } from "@/lib/review-types";
import { buildAttachmentIndex } from "@/lib/review-ui";
import { Link } from "@/src/i18n/navigation";
import { AttachmentPanel } from "@/components/review/AttachmentPanel";
import { EntryCard } from "@/components/review/EntryCard";
import { EntryComposer } from "@/components/review/EntryComposer";
import { MyReviewComposer } from "@/components/review/MyReviewComposer";
import { QuestionsList } from "@/components/review/QuestionsList";
import { ReviewCard } from "@/components/review/ReviewCard";
import { ReviewNotice } from "@/components/review/ReviewNotice";
import { SubmissionCard } from "@/components/review/SubmissionCard";
import { TallyBar } from "@/components/review/TallyBar";

export default async function ReviewProjectPage({
  params,
}: {
  params: Promise<{ lang: string; id: string }>;
}) {
  const { id: rawId } = await params;
  if (!/^[1-9]\d{0,15}$/.test(rawId)) notFound();
  const session = await auth();
  if (!session?.user) {
    redirect("/api/auth/signin");
  }
  const t = await getTranslations("Review");

  let me: ReviewMe;
  let project: ReviewProject;
  try {
    me = await fetchReviewMe(session);
    if (!me.member) {
      return <ReviewNotice title={t("title")} message={t("notMember")} />;
    }
    project = await fetchReviewProject(session, Number(rawId));
  } catch (err) {
    if (err instanceof ReviewApiError && err.status === 404) notFound();
    if (
      err instanceof ReviewApiError &&
      (err.status === 401 || err.status === 403)
    ) {
      return (
        <ReviewNotice
          title={t("title")}
          message={t(
            err.status === 401 ? "errors.sessionExpired" : "notMember",
          )}
        />
      );
    }
    throw err;
  }

  const locked = project.stage === "decided";
  const myReview =
    project.reviews.find((r) => r.id === project.me.review_id) ?? null;

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-4xl space-y-8">
        <Link href="/review" className="text-sm text-cdcf-navy hover:underline">
          ← {t("backToList")}
        </Link>
        <SubmissionCard project={project} />

        <section className="cdcf-card space-y-2">
          <h2 className="cdcf-heading text-xl">{t("tally.heading")}</h2>
          <TallyBar tally={project.tally} pending={project.voters_pending} />
          {project.plurality && (
            <p className="text-sm text-gray-700">
              {t("plurality", { vote: t(`vote.${project.plurality}`) })}
            </p>
          )}
        </section>

        {locked && (
          <p className="rounded-md bg-gray-100 p-3 text-sm text-gray-700">
            {t("locked")}
          </p>
        )}

        {project.me.can_vote && !locked && (
          <MyReviewComposer
            projectId={project.id}
            existing={
              myReview && myReview.vote
                ? {
                    vote: myReview.vote,
                    body_md: myReview.body_md,
                    version: myReview.version,
                  }
                : null
            }
          />
        )}

        <section className="space-y-4">
          <h2 className="cdcf-heading text-2xl">{t("reviews.heading")}</h2>
          {project.reviews.length === 0 ? (
            <p className="text-sm text-gray-500">{t("reviews.none")}</p>
          ) : (
            <ul className="space-y-4">
              {project.reviews.map((review) => (
                <ReviewCard
                  key={review.id}
                  review={review}
                  projectId={project.id}
                  currentUserId={me.user_id}
                  locked={locked}
                />
              ))}
            </ul>
          )}
        </section>

        <section className="space-y-4">
          <h2 className="cdcf-heading text-2xl">{t("discussion.heading")}</h2>
          {project.discussion.length === 0 ? (
            <p className="text-sm text-gray-500">{t("discussion.none")}</p>
          ) : (
            <ul className="space-y-4">
              {project.discussion.map((entry) => (
                <li key={entry.id} className="cdcf-card">
                  <EntryCard
                    entry={entry}
                    currentUserId={me.user_id}
                    locked={locked}
                    allowEdit
                  />
                </li>
              ))}
            </ul>
          )}
          {!locked && (
            <EntryComposer projectId={project.id} kind="discussion" />
          )}
        </section>

        <section className="space-y-4">
          <h2 className="cdcf-heading text-2xl">{t("questions.heading")}</h2>
          <QuestionsList questions={project.questions} />
        </section>
      </div>
      <AttachmentPanel index={buildAttachmentIndex(project)} />
    </main>
  );
}
```

- [ ] **Step 6: Full verification**

Run (repo root):

```bash
npx tsc --noEmit
npm run lint
npm test
composer test --working-dir=wordpress/themes/cdcf-headless
```

Expected: all pass with no new warnings. Then `npm run build` — Expected:
build succeeds (`/[lang]/review` and `/[lang]/review/[id]` listed as dynamic
routes). If the build needs a reachable WordPress for other pages and none is
available, record that and rely on `tsc` + the CI `build` check.

- [ ] **Step 7: Commit**

```bash
git add components/review "app/[lang]/review/[id]/page.tsx" lib/review-ui.test.ts
git commit -m "feat(review): project review workspace page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
```

---

### Task 12: Documentation, manual check, PR

**Files:**

- Modify: `AGENTS.md`, `docs/superpowers/specs/2026-10-15-project-review-design.md`

- [ ] **Step 1: `AGENTS.md`**

Add rows to the REST table after the `/review/entries/{id}` row:

```markdown
| `POST` | `/review/entries/{id}/attachments` | Attach a file to the caller's own entry: multipart `file`, or JSON `{title, markdown}` for pasted Markdown. `.md` ≤ 512 KB, PNG/JPEG/WebP/GIF ≤ 5 MB, MIME sniffed; max 10 per entry. Locked once decided. |
| `GET` | `/review/attachments/{id}` | Stream an attachment to council/Board members (`?download=1` for `Content-Disposition: attachment`). Sent with `nosniff`, a sandboxing CSP and `no-store`. |
```

In the "Council review (`/review/*`)" section, append:

```markdown
Attachments are stored under `CDCF_REVIEW_STORAGE_DIR` (define it in
`wp-config.php`, outside the web root) or, when unset, under
`wp-content/uploads/cdcf-review/` behind deny-all guard files; the web server
must also deny that path, and **Projects → Review access** shows a warning
while the fallback is in use. Uploads need the web server and PHP to accept
bodies of at least 8 MB (`client_max_body_size`, `upload_max_filesize` 6M,
`post_max_size` 8M); the local Docker stack sets these.

The frontend lives at `/[lang]/review` (list) and `/[lang]/review/[id]`
(workspace), backed by `lib/review-api.ts` (server-only) and the
`app/api/review/*` route handlers. All review Markdown is rendered through
`components/review/MarkdownView.tsx` (no raw HTML, no remote images).
```

- [ ] **Step 2: Spec refinements**

In `docs/superpowers/specs/2026-10-15-project-review-design.md`:

1. Under "### Reviewer" endpoints, change the `/review/me` description to
   `Caller's WordPress user id and membership flags. Used to show the /review link and to mark the caller's own entries.`
2. Under "### Pages", replace the sentence starting "Non-members see a `403`
   page" with: `Non-members see an explanation of who can review (rendered with HTTP 200); anonymous visitors are redirected to sign in.`
3. Under "### Pages" item 6 (Questions to submitter), add: `In Phase 2 this
section is read-only; the composer arrives with Phase 3.`

- [ ] **Step 3: Markdown lint**

Run: `npm run format:md && npm run lint:md`
Expected: `0 issues`.

- [ ] **Step 4: Manual check on the local stack**

With `docker compose up -d --build` (Zitadel included) and two linked test
users (one Technical Council, one Board):

1. Header menu shows **Project review** for both, not for an unlinked user.
2. `/review` lists the pending submission with a tally bar; tabs switch.
3. Technical user: post a review with a vote and a pasted Markdown
   attachment and an image; both chips appear; clicking each opens the side
   panel (Markdown rendered, Raw toggle, image zoom, Escape closes, Back closes).
4. Board user: sees the vote, replies with an attachment; cannot vote.
5. Edit the reply in two tabs: the second save shows "changed since you
   opened it" with Reload.
6. Upload an `.svg` renamed to `.png`: rejected with "file type isn't supported".
7. A 7 MB image is rejected with "file too large" (Next pre-check), and
   `curl` with a 9 MB body to `/wp-json/...` gets nginx 413.
8. `/wp-content/uploads/cdcf-review/<key>` returns 404 through nginx.

If Docker is unavailable, record it in the PR test plan as not run.

- [ ] **Step 5: Commit, push, PR**

```bash
git add AGENTS.md docs/superpowers/specs/2026-10-15-project-review-design.md
git commit -m "docs(review): document attachment endpoints and review UI

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k"
git push -u origin HEAD
gh pr create --title "feat(review): council review UI, Markdown and attachments (phase 2 of 5)" --body "$(cat <<'EOF'
## Summary
- `/[lang]/review` list (stage tabs, paging, tally, your vote, unread) and `/[lang]/review/[id]` workspace: submission, votes, your review with vote, reviews with replies, general discussion, read-only submitter Q&A.
- Markdown everywhere through one renderer (GFM, no raw HTML, links in a new tab without referrer, images as links).
- Attachments: upload a `.md`/image or paste Markdown; chips open a side panel (rendered/raw Markdown, image zoom and prev/next); URL `?att=` state.
- WordPress: `POST /review/entries/{id}/attachments` and `GET /review/attachments/{id}` (sniffed MIME, nosniff + sandbox CSP + no-store), files outside the web root via `CDCF_REVIEW_STORAGE_DIR` with a guarded fallback; `/review/me` returns `user_id`.
- "Project review" link in the account menu for members. Strings in all six languages.

Spec: `docs/superpowers/specs/2026-10-15-project-review-design.md`. Plan: `docs/superpowers/plans/2026-10-16-project-review-phase2.md`.

## Deploy notes
- Theme change: deploy with `-f environment=production`.
- Production web server and PHP on both sites must accept 8 MB request bodies, `CDCF_REVIEW_STORAGE_DIR` should be defined in wp-config.php (outside the web root), and the fallback uploads path should be denied at the web server. Review access shows a warning until the constant is set.

## Test plan
- [ ] `npm test`, `npx tsc --noEmit`, `npm run lint`
- [ ] `composer test --working-dir=wordpress/themes/cdcf-headless`
- [ ] `npm run lint:md`
- [ ] Manual local-stack walkthrough (plan Task 12 Step 4)

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k
EOF
)"
```

Expected: PR URL printed.
