# Council Review Phase 5 — Notification Bell, Inbox and Member Emails — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Council and Board members see every review event in a header bell and an inbox page, and get an email for the key events.

**Architecture:** Events and one notification row per member (minus the actor) are already written by `cdcf_review_record_event()` (Phase 1). This phase adds the read side (three `cdcf/v1/review/inbox*` endpoints, plus marking a project's notifications read when its workspace is opened), a listener on `cdcf_review_event_recorded` that emails members through the existing Redis `cdcf_review_email` job, and the Next.js bell, inbox page and route handlers.

**Tech Stack:** WordPress theme PHP 8.3 (PHPUnit 10, Brain Monkey, `FakeWpdb`), Next.js 16 App Router, next-intl 4, Vitest (happy-dom for components).

**Spec:** `docs/superpowers/specs/2026-10-15-project-review-design.md` — sections "REST endpoints → Reviewer" (inbox rows), "Notification bell", "Pages" (`/[lang]/review/inbox`) and "Notifications".

## Global Constraints

- Async email goes through the Redis queue (`cdcf_review_enqueue_email()` → `cdcf_review_email` job), inline only when the queue plugin is unavailable. Never WP-Cron.
- A queued member email stores only `{"member": {"user_id", "event_id"}}`: the address and the text are worked out when the job runs (the queue keeps job records in MySQL).
- Every new `cdcf/v1` request field declares its `sanitize_callback` in the route's `args` (in `cdcf_review_route_args()`); handlers do not re-sanitize.
- New PHP include files start with the one-line `defined('ABSPATH') || exit;` guard.
- Emails are plain text and carry no review content (no entry bodies, no rationale): event, project title, actor name, outcome label and a link to the workspace, which requires sign-in.
- Email types and recipients follow the spec's Notifications table: `submission_received`, `submitter_answered`, `decision_recorded`, `admin_override` → every member except the actor; `reply_posted` → the parent review's author unless they wrote the reply. `admin_override` events with `data.backfill = true` (the DB v2 migration) are never emailed.
- UI strings live in `messages/*.json` under `Review.inbox` in all six locales (en, it, es, fr, pt, de); member emails in six languages from a PHP string table, in the recipient's WordPress locale (fallback `en`).
- Theme tests: `cd wordpress/themes/cdcf-headless && php8.3 vendor/bin/phpunit` (the suite fails on warnings). Frontend: `npx vitest run`, `npx tsc --noEmit`, `npm run lint`. Docs: `npm run format:md` then `npm run lint:md`.
- Commit trailers on every commit:

  ```text
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k
  ```

## Review Focus

1. **Marking read is scoped to the caller.** `event_ids` that belong to another user's notifications must change nothing for that user — the `UPDATE` always carries `user_id = <caller>` (Task 1 test).
2. **No email to yourself.** A Board member who records a decision, or a reviewer replying in their own review's thread, gets no email for it (Task 2 tests).
3. **Membership can change between queueing and sending.** A queued member email whose recipient is no longer a member, has no valid address, or whose event no longer exists is skipped as a success (no retry storm) (Task 2 test).
4. **The bell never breaks the header.** Expired session, 403 or network failure on `/api/review/inbox/count` leaves the bell showing the last known count, and a failed dropdown load shows a message instead of throwing (Task 4 test).
5. **Notifications for a project whose title is gone** (trashed/deleted post) still render, with a dash for the title (Task 3 helper test).

---

## File Structure

| File                                                                               | Responsibility                                                                                  |
| ---------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `wordpress/themes/cdcf-headless/includes/review/inbox.php` (new)                   | Inbox reads (page, unread total), mark-read, notification presenter                             |
| `wordpress/themes/cdcf-headless/includes/review/events.php`                        | + `cdcf_review_normalize_event()`, `cdcf_review_get_event()`; action now also passes `$data`    |
| `wordpress/themes/cdcf-headless/includes/review/repository.php`                    | + `cdcf_review_reply_parent_author()`                                                           |
| `wordpress/themes/cdcf-headless/includes/review/member-email-strings.php` (new)    | Six-language member email table and renderer                                                    |
| `wordpress/themes/cdcf-headless/includes/review/member-emails.php` (new)           | Event listener, recipients, queue-or-inline send, delivery at send time                         |
| `wordpress/themes/cdcf-headless/includes/review/email-job.php`                     | + `member` payload branch                                                                       |
| `wordpress/themes/cdcf-headless/includes/handlers/review.php`                      | + three inbox handlers, route args, id-list sanitizer; GET project marks its notifications read |
| `wordpress/themes/cdcf-headless/functions.php`                                     | requires, route rows, listener hook                                                             |
| `lib/review-types.ts`, `lib/review-api.ts`                                         | Inbox types and server calls                                                                    |
| `lib/review-inbox.ts` (new)                                                        | Pure helpers: href, message key, actor label choice, relative time                              |
| `app/api/review/inbox/route.ts`, `.../count/route.ts`, `.../read/route.ts` (new)   | Route handlers for the bell and inbox page                                                      |
| `components/review/InboxItemSummary.tsx` (new)                                     | One notification's text + time, shared by bell and page                                         |
| `components/review/NotificationBell.tsx` (new), `components/AuthButton.tsx`        | The bell, mounted next to the user menu for members                                             |
| `app/[lang]/review/inbox/page.tsx`, `components/review/InboxMarkAllRead.tsx` (new) | The inbox page                                                                                  |
| `messages/*.json`                                                                  | `Review.inbox` strings                                                                          |
| `AGENTS.md`                                                                        | REST rows and a paragraph on the bell and member emails                                         |

---

### Task 1: Inbox endpoints and mark-read (WordPress)

**Files:**

- Create: `wordpress/themes/cdcf-headless/includes/review/inbox.php`
- Modify: `wordpress/themes/cdcf-headless/includes/review/events.php` (add `cdcf_review_normalize_event`)
- Modify: `wordpress/themes/cdcf-headless/includes/review/repository.php` (add `cdcf_review_reply_parent_author`)
- Modify: `wordpress/themes/cdcf-headless/includes/handlers/review.php` (handlers, args, `cdcf_review_sanitize_id_list`, GET project marks read, header comment)
- Modify: `wordpress/themes/cdcf-headless/functions.php` (require `inbox.php` after `events.php`; three route rows)
- Modify: `wordpress/themes/cdcf-headless/tests/bootstrap.php` (require `inbox.php` after `events.php`)
- Test: `wordpress/themes/cdcf-headless/tests/ReviewInboxTest.php` (new), `tests/ReviewInboxHandlerTest.php` (new), `tests/ReviewReadHandlerTest.php` (stub `cdcf_review_mark_read`, assert it is called)

**Interfaces:**

- Produces:
  - `cdcf_review_normalize_event(array $row): array{id:int, project_id:int, type:string, actor_user_id:?int, entry_id:?int, data:array, created_at:string}`
  - `cdcf_review_reply_parent_author(int $reply_id): ?int`
  - `cdcf_review_inbox(int $user_id, bool $unread_only, int $page): array{items: list<array>, has_more: bool}`
  - `cdcf_review_unread_total(int $user_id): int`
  - `cdcf_review_mark_read(int $user_id, ?array $event_ids = null, ?int $project_id = null): void`
  - `cdcf_review_present_notification(array $row, int $user_id): array` — JSON shape below
  - REST: `GET /review/inbox?unread=&page=` → `{items, page, has_more, unread}`; `GET /review/inbox/count` → `{unread}`; `POST /review/inbox/read` `{event_ids: int[]}` or `{all: true}` → `{unread}`. All three use `cdcf_rest_review_permission_view`.
  - Notification JSON: `{event_id:int, type:string, project:{id:int,title:string}, actor:{id:int,name:string}|null, entry_id:int|null, data:object, created_at:string, read:bool, to_you:bool}`

- [ ] **Step 1: Write the failing data-layer test** — `tests/ReviewInboxTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/inbox.php — reading and marking notifications.
 */
final class ReviewInboxTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        Functions\when('get_post_field')->alias(static fn(string $f, int $id) => $id === 40 ? 'Liturgy Helper' : '');
        Functions\when('get_userdata')->alias(static function (int $uid) {
            if ($uid !== 8) {
                return false;
            }
            $u = new WP_User($uid);
            $u->display_name = 'Fr. A';
            return $u;
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function row(array $over = []): array
    {
        return $over + [
            'id' => '12', 'project_id' => '40', 'type' => 'discussion_posted', 'actor_user_id' => '8',
            'entry_id' => '5', 'data' => null, 'created_at' => '2026-10-16 09:00:00.000000', 'read_at' => null,
        ];
    }

    public function test_page_is_newest_first_for_the_user_and_reports_more(): void
    {
        $rows = array_map(fn(int $i) => $this->row(['id' => (string) (100 - $i)]), range(0, 20)); // 21 rows
        $this->wpdb->results[] = $rows;

        $out = cdcf_review_inbox(7, false, 2);

        $this->assertCount(20, $out['items']);
        $this->assertTrue($out['has_more']);
        $sql = $this->wpdb->queries[0];
        $this->assertStringContainsString('n.user_id = 7', $sql);
        $this->assertStringContainsString('ORDER BY e.id DESC LIMIT 21 OFFSET 20', $sql);
        $this->assertStringNotContainsString('read_at IS NULL', $sql);
    }

    public function test_unread_filter_and_last_page(): void
    {
        $this->wpdb->results[] = [$this->row()];

        $out = cdcf_review_inbox(7, true, 1);

        $this->assertFalse($out['has_more']);
        $this->assertStringContainsString('n.read_at IS NULL', $this->wpdb->queries[0]);
    }

    public function test_presents_a_notification(): void
    {
        $item = cdcf_review_present_notification($this->row(['data' => '{"from":"reject","to":"cdcf"}', 'type' => 'vote_changed']), 7);

        $this->assertSame([
            'event_id'   => 12,
            'type'       => 'vote_changed',
            'project'    => ['id' => 40, 'title' => 'Liturgy Helper'],
            'actor'      => ['id' => 8, 'name' => 'Fr. A'],
            'entry_id'   => 5,
            'data'       => ['from' => 'reject', 'to' => 'cdcf'],
            'created_at' => '2026-10-16 09:00:00.000000',
            'read'       => false,
            'to_you'     => false,
        ], $item);
    }

    public function test_submitter_events_have_no_actor_and_former_members_are_named_as_such(): void
    {
        $this->assertNull(cdcf_review_present_notification($this->row(['actor_user_id' => null, 'type' => 'submitter_answered']), 7)['actor']);
        $this->assertSame(['id' => 99, 'name' => 'Former member'], cdcf_review_present_notification($this->row(['actor_user_id' => '99']), 7)['actor']);
        $this->assertTrue(cdcf_review_present_notification($this->row(['read_at' => '2026-10-16 10:00:00']), 7)['read']);
    }

    public function test_a_reply_to_the_callers_review_is_marked_to_you(): void
    {
        // get_row: the reply (parent 3), then its parent review (author 7).
        $this->wpdb->results[] = ['id' => '5', 'kind' => 'reply', 'parent_id' => '3', 'author_user_id' => '8'];
        $this->wpdb->results[] = ['id' => '3', 'kind' => 'review', 'parent_id' => null, 'author_user_id' => '7'];

        $this->assertTrue(cdcf_review_present_notification($this->row(['type' => 'reply_posted']), 7)['to_you']);
    }

    public function test_unread_total(): void
    {
        $this->wpdb->results[] = '4';

        $this->assertSame(4, cdcf_review_unread_total(7));
        $this->assertStringContainsString('user_id = 7 AND read_at IS NULL', $this->wpdb->queries[0]);
    }

    public function test_mark_read_is_always_scoped_to_the_caller(): void
    {
        cdcf_review_mark_read(7, [12, 0, -3, 13]);
        cdcf_review_mark_read(7);
        cdcf_review_mark_read(7, null, 40);

        [$some, $all, $project] = $this->wpdb->queries;
        foreach ([$some, $all, $project] as $sql) {
            $this->assertStringContainsString('WHERE user_id = 7 AND read_at IS NULL', $sql);
        }
        $this->assertStringContainsString('AND event_id IN (12,13)', $some);
        $this->assertStringNotContainsString('event_id IN', $all);
        $this->assertStringContainsString('WHERE project_id = 40', $project);
    }

    public function test_mark_read_with_no_valid_ids_does_nothing(): void
    {
        cdcf_review_mark_read(7, [0, -1]);

        $this->assertSame([], $this->wpdb->queries);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd wordpress/themes/cdcf-headless && php8.3 vendor/bin/phpunit --filter ReviewInboxTest`
Expected: FAIL — `Call to undefined function cdcf_review_inbox()` (after adding the `inbox.php` require to `tests/bootstrap.php`, which fails until the file exists; add the require in Step 3).

- [ ] **Step 3: Implement the data layer**

In `includes/review/events.php`, append:

```php
/** @return array{id: int, project_id: int, type: string, actor_user_id: ?int, entry_id: ?int, data: array, created_at: string} */
function cdcf_review_normalize_event(array $row): array {
    $data = !empty($row['data']) ? json_decode((string) $row['data'], true) : [];
    return [
        'id'            => (int) $row['id'],
        'project_id'    => (int) $row['project_id'],
        'type'          => (string) $row['type'],
        'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
        'entry_id'      => $row['entry_id'] !== null ? (int) $row['entry_id'] : null,
        'data'          => is_array($data) ? $data : [],
        'created_at'    => (string) $row['created_at'],
    ];
}
```

In `includes/review/repository.php`, append:

```php
/** The author of the review a reply answers, or null. */
function cdcf_review_reply_parent_author(int $reply_id): ?int {
    $reply  = cdcf_review_get_entry($reply_id);
    $parent = $reply && !empty($reply['parent_id']) ? cdcf_review_get_entry((int) $reply['parent_id']) : null;
    return $parent && $parent['author_user_id'] !== null ? (int) $parent['author_user_id'] : null;
}
```

Create `includes/review/inbox.php`:

```php
<?php
/**
 * Council review — the caller's notifications (the header bell and
 * /review/inbox). cdcf_review_record_event() writes one row per member
 * except the actor; this module reads them and records when they are read.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_INBOX_PAGE_SIZE = 20;

/** @return array{items: list<array>, has_more: bool} Newest first. */
function cdcf_review_inbox(int $user_id, bool $unread_only, int $page): array {
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    $events        = cdcf_review_table('events');
    $limit         = CDCF_REVIEW_INBOX_PAGE_SIZE;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT e.id, e.project_id, e.type, e.actor_user_id, e.entry_id, e.data, e.created_at, n.read_at'
            . " FROM {$notifications} n JOIN {$events} e ON e.id = n.event_id WHERE n.user_id = %d"
            . ($unread_only ? ' AND n.read_at IS NULL' : '')
            . ' ORDER BY e.id DESC LIMIT %d OFFSET %d',
            $user_id,
            $limit + 1,
            (max(1, $page) - 1) * $limit
        ),
        ARRAY_A
    );
    $rows = is_array($rows) ? $rows : [];
    return [
        'items'    => array_map(
            static fn(array $row) => cdcf_review_present_notification($row, $user_id),
            array_slice($rows, 0, $limit)
        ),
        'has_more' => count($rows) > $limit,
    ];
}

function cdcf_review_present_notification(array $row, int $user_id): array {
    $event = cdcf_review_normalize_event($row);
    $actor = $event['actor_user_id'];
    $user  = $actor !== null ? get_userdata($actor) : null;
    return [
        'event_id'   => $event['id'],
        'type'       => $event['type'],
        'project'    => ['id' => $event['project_id'], 'title' => (string) get_post_field('post_title', $event['project_id'])],
        'actor'      => $actor !== null ? ['id' => $actor, 'name' => $user ? (string) $user->display_name : 'Former member'] : null,
        'entry_id'   => $event['entry_id'],
        'data'       => $event['data'],
        'created_at' => $event['created_at'],
        'read'       => ($row['read_at'] ?? null) !== null,
        'to_you'     => $event['type'] === 'reply_posted' && $event['entry_id'] !== null
            && cdcf_review_reply_parent_author($event['entry_id']) === $user_id,
    ];
}

function cdcf_review_unread_total(int $user_id): int {
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    return (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$notifications} WHERE user_id = %d AND read_at IS NULL", $user_id)
    );
}

/**
 * Mark the user's unread notifications read: all of them, the given event
 * ids, or those of one project. Never touches another user's rows.
 *
 * @param list<int>|null $event_ids
 */
function cdcf_review_mark_read(int $user_id, ?array $event_ids = null, ?int $project_id = null): void {
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    $sql = $wpdb->prepare(
        "UPDATE {$notifications} SET read_at = %s WHERE user_id = %d AND read_at IS NULL",
        cdcf_review_now(),
        $user_id
    );
    if ($event_ids !== null) {
        $ids = array_values(array_filter(array_map('intval', $event_ids), static fn(int $id) => $id > 0));
        if (!$ids) {
            return;
        }
        $sql .= ' AND event_id IN (' . cdcf_review_id_list($ids) . ')';
    }
    if ($project_id !== null) {
        $events = cdcf_review_table('events');
        $sql .= $wpdb->prepare(" AND event_id IN (SELECT id FROM {$events} WHERE project_id = %d)", $project_id);
    }
    $wpdb->query($sql);
}
```

Require it in `functions.php` right after `includes/review/events.php`, and in `tests/bootstrap.php` right after the `events.php` require.

- [ ] **Step 4: Run it to verify it passes**

Run: `php8.3 vendor/bin/phpunit --filter ReviewInboxTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Write the failing handler test** — `tests/ReviewInboxHandlerTest.php`:

```php
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
```

Also in `tests/ReviewReadHandlerTest.php`: add to `setUp()` a stub recording calls —
`Functions\when('cdcf_review_mark_read')->alias(function (int $uid, ?array $ids = null, ?int $pid = null): void { $this->markedRead[] = [$uid, $ids, $pid]; });` with a `private array $markedRead = [];` property reset in `setUp()` — and add this test:

```php
    public function test_opening_a_workspace_marks_its_notifications_read(): void
    {
        $this->signIn(7);

        cdcf_rest_review_get_project($this->request(['id' => 40]));

        $this->assertSame([[7, null, 40]], $this->markedRead);
    }
```

(Use the file's existing request helper; if it is named differently, use that one.)

- [ ] **Step 6: Run to verify they fail**

Run: `php8.3 vendor/bin/phpunit --filter 'ReviewInboxHandlerTest|ReviewReadHandlerTest'`
Expected: FAIL — undefined `cdcf_rest_review_inbox`, and `markedRead` empty.

- [ ] **Step 7: Implement the handlers**

In `includes/handlers/review.php`:

1. Header comment: add the three lines

   ```text
    *   GET   /cdcf/v1/review/inbox?unread=&page=     the caller's notifications (paged)
    *   GET   /cdcf/v1/review/inbox/count             unread total for the bell
    *   POST  /cdcf/v1/review/inbox/read              mark event_ids, or all, read
   ```

2. In `cdcf_rest_review_get_project()`, right after `$uid = get_current_user_id();`:

   ```php
       // Opening the workspace is reading its activity.
       cdcf_review_mark_read($uid, null, $pid);
   ```

3. Append the handlers and the sanitizer:

```php
/** @return list<int> Positive integer ids; anything else is dropped. */
function cdcf_review_sanitize_id_list($value): array {
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_filter(array_map('intval', $value), static fn(int $id) => $id > 0));
}

function cdcf_rest_review_inbox(WP_REST_Request $request) {
    $uid  = get_current_user_id();
    $page = max(1, (int) $request['page']);
    $out  = cdcf_review_inbox($uid, (bool) $request['unread'], $page);
    return rest_ensure_response([
        'items'    => $out['items'],
        'page'     => $page,
        'has_more' => $out['has_more'],
        'unread'   => cdcf_review_unread_total($uid),
    ]);
}

function cdcf_rest_review_inbox_count(WP_REST_Request $request) {
    unset($request);
    return rest_ensure_response(['unread' => cdcf_review_unread_total(get_current_user_id())]);
}

function cdcf_rest_review_inbox_read(WP_REST_Request $request) {
    $uid = get_current_user_id();
    if ($request['all']) {
        cdcf_review_mark_read($uid);
    } else {
        $ids = (array) $request['event_ids'];
        if (!$ids) {
            return new WP_Error('invalid_input', 'Pass event_ids or all.', ['status' => 400]);
        }
        cdcf_review_mark_read($uid, $ids);
    }
    return rest_ensure_response(['unread' => cdcf_review_unread_total($uid)]);
}
```

1. In `cdcf_review_route_args()`, add (reuse the `/review/projects` `page` definition — copy it verbatim):

```php
        '/review/inbox' => [
            'unread' => [
                'required'          => false,
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'page'   => [
                'required'          => false,
                'type'              => 'integer',
                'minimum'           => 1,
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ],
        '/review/inbox/count' => [],
        '/review/inbox/read'  => [
            'event_ids' => [
                'required'          => false,
                'type'              => 'array',
                'items'             => ['type' => 'integer'],
                'maxItems'          => 200,
                'default'           => [],
                'sanitize_callback' => 'cdcf_review_sanitize_id_list',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'all'       => [
                'required'          => false,
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
        ],
```

1. In `functions.php`, add to the `$routes` table:

```php
        '/review/inbox'                           => ['GET', 'cdcf_rest_review_inbox', 'cdcf_rest_review_permission_view'],
        '/review/inbox/count'                     => ['GET', 'cdcf_rest_review_inbox_count', 'cdcf_rest_review_permission_view'],
        '/review/inbox/read'                      => ['POST', 'cdcf_rest_review_inbox_read', 'cdcf_rest_review_permission_view'],
```

- [ ] **Step 8: Run the whole theme suite**

Run: `php8.3 vendor/bin/phpunit`
Expected: OK, no warnings. If an existing test fails because `cdcf_rest_review_get_project` now calls `cdcf_review_mark_read` with no `$wpdb`, stub `cdcf_review_mark_read` in that test's `setUp()`.

- [ ] **Step 9: Commit**

```bash
git add wordpress/themes/cdcf-headless
git commit -m "feat(review): inbox endpoints and mark-read for the notification bell"
```

---

### Task 2: Member emails (WordPress)

**Files:**

- Modify: `wordpress/themes/cdcf-headless/includes/review/events.php` (action passes `$data`; add `cdcf_review_get_event`)
- Create: `wordpress/themes/cdcf-headless/includes/review/member-email-strings.php`
- Create: `wordpress/themes/cdcf-headless/includes/review/member-emails.php`
- Modify: `wordpress/themes/cdcf-headless/includes/review/email-job.php` (`member` branch)
- Modify: `wordpress/themes/cdcf-headless/functions.php` (require both new files after `mailer.php`; `add_action('cdcf_review_event_recorded', 'cdcf_review_email_members_on_event', 10, 6);`)
- Modify: `wordpress/themes/cdcf-headless/tests/bootstrap.php` (require both new files after `mailer.php`)
- Test: `tests/ReviewEventsTest.php` (expectation gains `[]`), `tests/ReviewMemberEmailsTest.php` (new), `tests/ReviewMemberEmailStringsTest.php` (new), `tests/ReviewMailerTest.php` (member job)

**Interfaces:**

- Consumes: `cdcf_review_normalize_event()`, `cdcf_review_reply_parent_author()` (Task 1); `cdcf_review_enqueue_email()`, `CDCF_Review_Email_Job`, `cdcf_review_outcome_labels()`, `cdcf_review_member_user_ids()`, `cdcf_review_can()` (existing).
- Produces:
  - action `cdcf_review_event_recorded` now passes 6 args: `($event_id, $project_id, $type, $actor_user_id, $entry_id, $data)`
  - `cdcf_review_get_event(int $event_id): ?array` (normalized)
  - `cdcf_review_email_members_on_event($event_id, $project_id, $type, $actor_user_id, $entry_id = null, $data = []): void`
  - `cdcf_review_member_email_recipients(string $type, ?int $actor, ?int $entry_id): list<int>`
  - `cdcf_review_send_member_email(int $user_id, int $event_id): 'queued'|'sent'|'failed'|'skipped'`
  - `cdcf_review_deliver_member_email(int $user_id, int $event_id): 'sent'|'failed'|'skipped'`
  - `cdcf_review_user_language(int $user_id): string`
  - `cdcf_review_workspace_url(int $project_id, string $lang, ?int $entry_id = null): string`
  - `cdcf_review_member_email_strings(): array`, `cdcf_review_render_member_email(string $type, string $lang, array $vars): ?array{subject, body}`

- [ ] **Step 1: Write the failing string-table test** — `tests/ReviewMemberEmailStringsTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReviewMemberEmailStringsTest extends TestCase
{
    private const TYPES = ['submission_received', 'submitter_answered', 'decision_recorded', 'admin_override', 'reply_posted'];

    public function test_every_language_has_every_template_with_its_placeholders(): void
    {
        $all = cdcf_review_member_email_strings();
        $this->assertSame(['en', 'it', 'es', 'fr', 'pt', 'de'], array_keys($all));
        foreach ($all as $lang => $templates) {
            $this->assertSame(self::TYPES, array_keys($templates), $lang);
            foreach ($templates as $type => $t) {
                $this->assertStringContainsString('{title}', $t['subject'], "$lang $type subject");
                $this->assertStringContainsString('{link}', $t['body'], "$lang $type body");
                if (in_array($type, ['decision_recorded', 'admin_override', 'reply_posted'], true)) {
                    $this->assertStringContainsString('{actor}', $t['body'], "$lang $type actor");
                }
                if (in_array($type, ['decision_recorded', 'admin_override'], true)) {
                    $this->assertStringContainsString('{outcome}', $t['body'], "$lang $type outcome");
                }
            }
        }
    }

    public function test_renders_with_the_outcome_label_and_footer(): void
    {
        $mail = cdcf_review_render_member_email('decision_recorded', 'it', [
            'title' => 'Liturgy Helper', 'actor' => 'Fr. A', 'outcome' => 'community', 'link' => 'https://x/it/review/40',
        ]);

        $this->assertSame('Decisione registrata: Liturgy Helper', $mail['subject']);
        $this->assertStringContainsString('Fr. A ha registrato la decisione su "Liturgy Helper": Accettato come progetto della comunità.', $mail['body']);
        $this->assertStringContainsString('https://x/it/review/40', $mail['body']);
        $this->assertStringContainsString('Consiglio di amministrazione', $mail['body']);
    }

    public function test_unknown_language_falls_back_to_english_and_unknown_type_is_null(): void
    {
        $this->assertSame('New project to review: T', cdcf_review_render_member_email('submission_received', 'xx', ['title' => 'T'])['subject']);
        $this->assertNull(cdcf_review_render_member_email('review_posted', 'en', []));
    }

    public function test_someone_label_per_language(): void
    {
        $this->assertSame('Someone', cdcf_review_member_email_someone('en'));
        $this->assertSame('Jemand', cdcf_review_member_email_someone('de'));
        $this->assertSame('Someone', cdcf_review_member_email_someone('xx'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php8.3 vendor/bin/phpunit --filter ReviewMemberEmailStringsTest`
Expected: FAIL — undefined function (add the requires to `tests/bootstrap.php` with the file creation in Step 3).

- [ ] **Step 3: Create `includes/review/member-email-strings.php`** (verbatim):

```php
<?php
/**
 * Council review — emails to council and Board members, in the member's
 * language. Plain text, no review content: the link leads to the
 * workspace, which needs sign-in. Placeholders: {title}, {actor},
 * {outcome}, {link}.
 */

defined('ABSPATH') || exit;

/** @return array<string, array<string, array{subject: string, body: string}>> */
function cdcf_review_member_email_strings(): array {
    $footer = [
        'en' => "\n\nYou receive this email because you are a member of a council or the Board of the Catholic Digital Commons Foundation.",
        'it' => "\n\nRicevi questa email perché fai parte di un consiglio o del Consiglio di amministrazione della Catholic Digital Commons Foundation.",
        'es' => "\n\nRecibes este correo porque eres miembro de un consejo o de la Junta Directiva de la Catholic Digital Commons Foundation.",
        'fr' => "\n\nVous recevez cet e-mail parce que vous êtes membre d'un conseil ou du Conseil d'administration de la Catholic Digital Commons Foundation.",
        'pt' => "\n\nRecebe este email porque é membro de um conselho ou da Direção da Catholic Digital Commons Foundation.",
        'de' => "\n\nSie erhalten diese E-Mail, weil Sie Mitglied eines Rates oder des Vorstands der Catholic Digital Commons Foundation sind.",
    ];
    $strings = [
        'en' => [
            'submission_received' => ['New project to review: {title}', "A new project, \"{title}\", has been submitted for council review.\n\nOpen it in the review workspace:\n\n{link}"],
            'submitter_answered'  => ['The submitter answered: {title}', "The submitter of \"{title}\" has answered the review committee's question.\n\nRead the answer in the review workspace:\n\n{link}"],
            'decision_recorded'   => ['Decision recorded: {title}', "{actor} recorded the decision on \"{title}\": {outcome}.\n\nThe review is now closed. See the decision in the review workspace:\n\n{link}"],
            'admin_override'      => ['Published by an administrator: {title}', "{actor} published \"{title}\" in WordPress while it was under review, so the review is closed. Recorded outcome: {outcome}.\n\n{link}"],
            'reply_posted'        => ['New reply to your review: {title}', "{actor} replied to your review of \"{title}\".\n\nRead the reply in the review workspace:\n\n{link}"],
        ],
        'it' => [
            'submission_received' => ['Nuovo progetto da esaminare: {title}', "È stato proposto un nuovo progetto, \"{title}\", per la revisione dei consigli.\n\nAprilo nell'area di revisione:\n\n{link}"],
            'submitter_answered'  => ['Il proponente ha risposto: {title}', "Il proponente di \"{title}\" ha risposto alla domanda del comitato di revisione.\n\nLeggi la risposta nell'area di revisione:\n\n{link}"],
            'decision_recorded'   => ['Decisione registrata: {title}', "{actor} ha registrato la decisione su \"{title}\": {outcome}.\n\nLa revisione è ora chiusa. Vedi la decisione nell'area di revisione:\n\n{link}"],
            'admin_override'      => ['Pubblicato da un amministratore: {title}', "{actor} ha pubblicato \"{title}\" in WordPress mentre era in revisione, quindi la revisione è chiusa. Esito registrato: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nuova risposta alla tua revisione: {title}', "{actor} ha risposto alla tua revisione di \"{title}\".\n\nLeggi la risposta nell'area di revisione:\n\n{link}"],
        ],
        'es' => [
            'submission_received' => ['Nuevo proyecto para revisar: {title}', "Se ha presentado un nuevo proyecto, \"{title}\", para la revisión de los consejos.\n\nÁbrelo en el espacio de revisión:\n\n{link}"],
            'submitter_answered'  => ['El proponente ha respondido: {title}', "El proponente de \"{title}\" ha respondido a la pregunta del comité de revisión.\n\nLee la respuesta en el espacio de revisión:\n\n{link}"],
            'decision_recorded'   => ['Decisión registrada: {title}', "{actor} registró la decisión sobre \"{title}\": {outcome}.\n\nLa revisión está cerrada. Consulta la decisión en el espacio de revisión:\n\n{link}"],
            'admin_override'      => ['Publicado por un administrador: {title}', "{actor} publicó \"{title}\" en WordPress mientras estaba en revisión, por lo que la revisión está cerrada. Resultado registrado: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nueva respuesta a tu revisión: {title}', "{actor} respondió a tu revisión de \"{title}\".\n\nLee la respuesta en el espacio de revisión:\n\n{link}"],
        ],
        'fr' => [
            'submission_received' => ['Nouveau projet à évaluer : {title}', "Un nouveau projet, « {title} », a été proposé pour l'évaluation des conseils.\n\nOuvrez-le dans l'espace d'évaluation :\n\n{link}"],
            'submitter_answered'  => ['Le proposant a répondu : {title}', "Le proposant de « {title} » a répondu à la question du comité d'évaluation.\n\nLisez la réponse dans l'espace d'évaluation :\n\n{link}"],
            'decision_recorded'   => ['Décision enregistrée : {title}', "{actor} a enregistré la décision sur « {title} » : {outcome}.\n\nL'évaluation est désormais close. Consultez la décision dans l'espace d'évaluation :\n\n{link}"],
            'admin_override'      => ['Publié par un administrateur : {title}', "{actor} a publié « {title} » dans WordPress pendant son évaluation ; l'évaluation est donc close. Résultat enregistré : {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nouvelle réponse à votre évaluation : {title}', "{actor} a répondu à votre évaluation de « {title} ».\n\nLisez la réponse dans l'espace d'évaluation :\n\n{link}"],
        ],
        'pt' => [
            'submission_received' => ['Novo projeto para rever: {title}', "Foi proposto um novo projeto, \"{title}\", para a revisão dos conselhos.\n\nAbra-o no espaço de revisão:\n\n{link}"],
            'submitter_answered'  => ['O proponente respondeu: {title}', "O proponente de \"{title}\" respondeu à pergunta do comité de revisão.\n\nLeia a resposta no espaço de revisão:\n\n{link}"],
            'decision_recorded'   => ['Decisão registada: {title}', "{actor} registou a decisão sobre \"{title}\": {outcome}.\n\nA revisão está agora encerrada. Consulte a decisão no espaço de revisão:\n\n{link}"],
            'admin_override'      => ['Publicado por um administrador: {title}', "{actor} publicou \"{title}\" no WordPress enquanto estava em revisão, pelo que a revisão está encerrada. Resultado registado: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nova resposta à sua revisão: {title}', "{actor} respondeu à sua revisão de \"{title}\".\n\nLeia a resposta no espaço de revisão:\n\n{link}"],
        ],
        'de' => [
            'submission_received' => ['Neues Projekt zur Prüfung: {title}', "Ein neues Projekt, „{title}“, wurde zur Prüfung durch die Räte eingereicht.\n\nÖffnen Sie es im Prüfbereich:\n\n{link}"],
            'submitter_answered'  => ['Die einreichende Person hat geantwortet: {title}', "Die einreichende Person von „{title}“ hat die Frage des Prüfungsausschusses beantwortet.\n\nLesen Sie die Antwort im Prüfbereich:\n\n{link}"],
            'decision_recorded'   => ['Entscheidung erfasst: {title}', "{actor} hat die Entscheidung zu „{title}“ erfasst: {outcome}.\n\nDie Prüfung ist damit abgeschlossen. Die Entscheidung finden Sie im Prüfbereich:\n\n{link}"],
            'admin_override'      => ['In WordPress veröffentlicht: {title}', "{actor} hat „{title}“ während der Prüfung in WordPress veröffentlicht; die Prüfung ist damit abgeschlossen. Erfasstes Ergebnis: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Neue Antwort auf Ihre Bewertung: {title}', "{actor} hat auf Ihre Bewertung von „{title}“ geantwortet.\n\nLesen Sie die Antwort im Prüfbereich:\n\n{link}"],
        ],
    ];
    $out = [];
    foreach ($strings as $lang => $templates) {
        foreach ($templates as $type => [$subject, $body]) {
            $out[$lang][$type] = ['subject' => $subject, 'body' => $body . $footer[$lang]];
        }
    }
    return $out;
}

/** Stands in for an actor whose account no longer exists. */
function cdcf_review_member_email_someone(string $lang): string {
    $labels = ['en' => 'Someone', 'it' => 'Qualcuno', 'es' => 'Alguien', 'fr' => "Quelqu'un", 'pt' => 'Alguém', 'de' => 'Jemand'];
    return $labels[$lang] ?? $labels['en'];
}

/** @return array{subject: string, body: string}|null Null for an event type that is not emailed. */
function cdcf_review_render_member_email(string $type, string $lang, array $vars): ?array {
    $all = cdcf_review_member_email_strings();
    $raw = $all[$lang][$type] ?? $all['en'][$type] ?? null;
    if ($raw === null) {
        return null;
    }
    $labels  = cdcf_review_outcome_labels()[$lang] ?? cdcf_review_outcome_labels()['en'];
    $outcome = (string) ($vars['outcome'] ?? '');
    $map     = ['{outcome}' => $labels[$outcome] ?? $outcome];
    foreach (['title', 'actor', 'link'] as $key) {
        $map['{' . $key . '}'] = (string) ($vars[$key] ?? '');
    }
    return ['subject' => strtr($raw['subject'], $map), 'body' => strtr($raw['body'], $map)];
}
```

Requires: in `functions.php` and `tests/bootstrap.php`, after `mailer.php`, add `member-email-strings.php` then `member-emails.php` (create an empty guarded `member-emails.php` now if needed so the require resolves; it is filled in Step 7).

- [ ] **Step 4: Run it to verify it passes**

Run: `php8.3 vendor/bin/phpunit --filter ReviewMemberEmailStringsTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Update the events action and its test**

In `includes/review/events.php`, change the action call to pass `$data`:

```php
    do_action('cdcf_review_event_recorded', $event_id, $project_id, $type, $actor_user_id, $entry_id, $data);
```

Update the module comment's "(Phase 5)" mentions to the present tense ("the email layer, member-emails.php, reacts …"). Append:

```php
/** @return array{id: int, project_id: int, type: string, actor_user_id: ?int, entry_id: ?int, data: array, created_at: string}|null */
function cdcf_review_get_event(int $event_id): ?array {
    global $wpdb;
    $events = cdcf_review_table('events');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$events} WHERE id = %d", $event_id), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_event($row) : null;
}
```

In `tests/ReviewEventsTest.php`, change the expectation to `->with(1, 40, 'reply_posted', 8, 5, [])` and add:

```php
    public function test_get_event_decodes_its_data(): void
    {
        $this->wpdb->results[] = ['id' => '3', 'project_id' => '40', 'type' => 'admin_override', 'actor_user_id' => null, 'entry_id' => null, 'data' => '{"backfill":true}', 'created_at' => 'x'];

        $this->assertSame(
            ['id' => 3, 'project_id' => 40, 'type' => 'admin_override', 'actor_user_id' => null, 'entry_id' => null, 'data' => ['backfill' => true], 'created_at' => 'x'],
            cdcf_review_get_event(3)
        );
        $this->assertNull(cdcf_review_get_event(4));
    }
```

- [ ] **Step 6: Write the failing member-email test** — `tests/ReviewMemberEmailsTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewMemberEmailsTest extends TestCase
{
    private array $mails = [];
    private array $sent = [];
    private array $users = [];
    private ?array $event = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->mails = $this->sent = [];
        $this->users = [
            7 => ['email' => 'tech@example.org', 'name' => 'Dr. T', 'locale' => 'it_IT'],
            8 => ['email' => 'board@example.org', 'name' => 'Fr. A', 'locale' => 'en_US'],
            9 => ['email' => 'eccl@example.org', 'name' => 'Sr. E', 'locale' => 'xx'],
        ];
        $this->event = ['id' => 3, 'project_id' => 40, 'type' => 'decision_recorded', 'actor_user_id' => 8, 'entry_id' => null, 'data' => ['decision' => 'cdcf'], 'created_at' => 'x'];
        Functions\when('cdcf_review_member_user_ids')->justReturn([7, 8, 9]);
        Functions\when('cdcf_review_can')->alias(fn(int $uid) => isset($this->users[$uid]));
        Functions\when('cdcf_review_get_event')->alias(fn(int $id) => $id === 3 ? $this->event : null);
        Functions\when('get_userdata')->alias(function (int $uid) {
            if (!isset($this->users[$uid])) {
                return false;
            }
            $u = new WP_User($uid);
            $u->display_name = $this->users[$uid]['name'];
            $u->user_email = $this->users[$uid]['email'];
            return $u;
        });
        Functions\when('get_user_locale')->alias(fn(int $uid) => $this->users[$uid]['locale'] ?? 'en_US');
        Functions\when('is_email')->alias(static fn($e) => is_string($e) && str_contains($e, '@'));
        Functions\when('get_post_field')->justReturn('Liturgy Helper');
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = compact('to', 'subject', 'body');
            return true;
        });
        Functions\when('cdcf_review_enqueue_email')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_key_events_email_every_member_but_the_actor(): void
    {
        $this->assertSame([7, 9], cdcf_review_member_email_recipients('decision_recorded', 8, null));
        $this->assertSame([7, 8, 9], cdcf_review_member_email_recipients('submission_received', null, null));
        $this->assertSame([], cdcf_review_member_email_recipients('review_posted', 7, 5));
    }

    public function test_a_reply_emails_the_review_author_unless_they_wrote_it(): void
    {
        Functions\when('cdcf_review_reply_parent_author')->justReturn(7);

        $this->assertSame([7], cdcf_review_member_email_recipients('reply_posted', 8, 5));
        $this->assertSame([], cdcf_review_member_email_recipients('reply_posted', 7, 5));
    }

    public function test_a_reply_to_a_former_members_review_emails_nobody(): void
    {
        Functions\when('cdcf_review_reply_parent_author')->justReturn(55);

        $this->assertSame([], cdcf_review_member_email_recipients('reply_posted', 8, 5));
    }

    public function test_listener_sends_each_recipient_in_their_language(): void
    {
        cdcf_review_email_members_on_event(3, 40, 'decision_recorded', 8, null, ['decision' => 'cdcf']);

        $this->assertSame(['tech@example.org', 'eccl@example.org'], array_column($this->mails, 'to'));
        $this->assertSame('Decisione registrata: Liturgy Helper', $this->mails[0]['subject']);
        $this->assertStringContainsString('Fr. A ha registrato la decisione', $this->mails[0]['body']);
        $this->assertStringContainsString('http://localhost:3000/it/review/40', $this->mails[0]['body']);
        $this->assertSame('Decision recorded: Liturgy Helper', $this->mails[1]['subject']);
        $this->assertStringContainsString('http://localhost:3000/review/40', $this->mails[1]['body']);
    }

    public function test_backfilled_overrides_are_never_emailed(): void
    {
        cdcf_review_email_members_on_event(3, 40, 'admin_override', null, null, ['decision' => 'cdcf', 'backfill' => true]);

        $this->assertSame([], $this->mails);
    }

    public function test_queued_job_holds_only_ids(): void
    {
        $payloads = [];
        Functions\when('cdcf_review_enqueue_email')->alias(function (array $p) use (&$payloads): bool {
            $payloads[] = $p;
            return true;
        });

        $this->assertSame('queued', cdcf_review_send_member_email(7, 3));
        $this->assertSame([['member' => ['user_id' => 7, 'event_id' => 3]]], $payloads);
        $this->assertSame([], $this->mails);
    }

    public function test_reply_link_points_at_the_entry(): void
    {
        $this->event = ['id' => 3, 'project_id' => 40, 'type' => 'reply_posted', 'actor_user_id' => 8, 'entry_id' => 5, 'data' => [], 'created_at' => 'x'];

        $this->assertSame('sent', cdcf_review_deliver_member_email(7, 3));
        $this->assertStringContainsString('http://localhost:3000/it/review/40#entry-5', $this->mails[0]['body']);
    }

    public function test_send_time_checks_skip_without_failing(): void
    {
        $this->assertSame('skipped', cdcf_review_deliver_member_email(7, 999), 'event gone');
        $this->assertSame('skipped', cdcf_review_deliver_member_email(55, 3), 'no longer a member');
        $this->users[9]['email'] = 'not-an-address';
        $this->assertSame('skipped', cdcf_review_deliver_member_email(9, 3), 'no valid address');
        $this->event['type'] = 'review_posted';
        $this->assertSame('skipped', cdcf_review_deliver_member_email(7, 3), 'type not emailed');
        $this->assertSame([], $this->mails);
    }

    public function test_a_deleted_actor_is_named_generically(): void
    {
        $this->event['actor_user_id'] = 55;

        cdcf_review_deliver_member_email(8, 3);

        $this->assertStringContainsString('Someone recorded the decision', $this->mails[0]['body']);
    }

    public function test_mail_failure_is_failed_and_logs_no_address(): void
    {
        Functions\when('wp_mail')->justReturn(false);
        $logs = [];
        Functions\when('error_log')->alias(function (string $m) use (&$logs): bool {
            $logs[] = $m;
            return true;
        });

        $this->assertSame('failed', cdcf_review_deliver_member_email(7, 3));
        $this->assertNotEmpty($logs);
        $this->assertStringNotContainsString('tech@example.org', implode("\n", $logs));
    }

    public function test_user_language(): void
    {
        $this->assertSame('it', cdcf_review_user_language(7));
        $this->assertSame('en', cdcf_review_user_language(9));
    }
}
```

If `error_log` cannot be stubbed with `Functions\when` in this suite (it is an internal function), follow `ReviewMailerTest`'s existing approach to capturing logs instead.

- [ ] **Step 7: Run it to verify it fails**

Run: `php8.3 vendor/bin/phpunit --filter ReviewMemberEmailsTest`
Expected: FAIL — undefined `cdcf_review_member_email_recipients`.

- [ ] **Step 8: Implement `includes/review/member-emails.php`**

```php
<?php
/**
 * Council review — email to members on key events (spec: Notifications).
 * The bell shows every event; email goes only to the recipients below,
 * one message each, through the Redis queue when it is available. A
 * queued job holds only the user and event ids: the address and the text
 * are worked out when it runs, after re-checking membership.
 */

defined('ABSPATH') || exit;

/** Emailed to every member except the actor. */
const CDCF_REVIEW_MEMBER_EMAIL_ALL = ['submission_received', 'submitter_answered', 'decision_recorded', 'admin_override'];

const CDCF_REVIEW_LANGUAGES = ['en', 'it', 'es', 'fr', 'pt', 'de'];

/** cdcf_review_event_recorded listener. */
function cdcf_review_email_members_on_event($event_id, $project_id, $type, $actor_user_id, $entry_id = null, $data = []): void {
    unset($project_id);
    if (is_array($data) && !empty($data['backfill'])) {
        // The DB v2 migration's record of earlier publishes: bell only.
        return;
    }
    $recipients = cdcf_review_member_email_recipients(
        (string) $type,
        $actor_user_id === null ? null : (int) $actor_user_id,
        $entry_id === null ? null : (int) $entry_id
    );
    foreach ($recipients as $uid) {
        cdcf_review_send_member_email($uid, (int) $event_id);
    }
}

/** @return list<int> */
function cdcf_review_member_email_recipients(string $type, ?int $actor, ?int $entry_id): array {
    if (in_array($type, CDCF_REVIEW_MEMBER_EMAIL_ALL, true)) {
        return array_values(array_filter(cdcf_review_member_user_ids(), static fn(int $uid) => $uid !== $actor));
    }
    if ($type === 'reply_posted' && $entry_id !== null) {
        $author = cdcf_review_reply_parent_author($entry_id);
        return $author !== null && $author !== $actor && cdcf_review_can($author, 'view') ? [$author] : [];
    }
    return [];
}

/** @return 'queued'|'sent'|'failed'|'skipped' */
function cdcf_review_send_member_email(int $user_id, int $event_id): string {
    if (cdcf_review_enqueue_email(['member' => ['user_id' => $user_id, 'event_id' => $event_id]])) {
        return 'queued';
    }
    return cdcf_review_deliver_member_email($user_id, $event_id);
}

/**
 * Build and send one member email now. 'skipped' (not a failure, so the
 * queue does not retry) when the event is gone, its type is not emailed,
 * or the user is no longer a member or has no valid address.
 *
 * @return 'sent'|'failed'|'skipped'
 */
function cdcf_review_deliver_member_email(int $user_id, int $event_id): string {
    $event = cdcf_review_get_event($event_id);
    $user  = get_userdata($user_id);
    if (!$event || !$user || !is_email((string) $user->user_email) || !cdcf_review_can($user_id, 'view')) {
        return 'skipped';
    }
    $lang  = cdcf_review_user_language($user_id);
    $actor = $event['actor_user_id'] !== null ? get_userdata($event['actor_user_id']) : null;
    $mail  = cdcf_review_render_member_email($event['type'], $lang, [
        // Raw title: get_the_title() would HTML-escape it for a text email.
        'title'   => (string) get_post_field('post_title', $event['project_id']),
        'actor'   => $actor ? (string) $actor->display_name : cdcf_review_member_email_someone($lang),
        'outcome' => (string) ($event['data']['decision'] ?? ''),
        'link'    => cdcf_review_workspace_url(
            $event['project_id'],
            $lang,
            $event['type'] === 'reply_posted' ? $event['entry_id'] : null
        ),
    ]);
    if ($mail === null) {
        return 'skipped';
    }
    if (!wp_mail((string) $user->user_email, $mail['subject'], $mail['body'])) {
        // Never log the address.
        error_log('[cdcf-review] member email could not be sent: ' . $event['type']);
        return 'failed';
    }
    return 'sent';
}

function cdcf_review_user_language(int $user_id): string {
    $lang = substr((string) get_user_locale($user_id), 0, 2);
    return in_array($lang, CDCF_REVIEW_LANGUAGES, true) ? $lang : 'en';
}

/** The workspace on the frontend; English has no locale prefix. */
function cdcf_review_workspace_url(int $project_id, string $lang, ?int $entry_id = null): string {
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    return rtrim($frontend, '/') . ($lang === 'en' ? '' : '/' . $lang) . '/review/' . $project_id
        . ($entry_id ? '#entry-' . $entry_id : '');
}
```

In `functions.php`, next to the other review hooks:

```php
add_action('cdcf_review_event_recorded', 'cdcf_review_email_members_on_event', 10, 6);
```

If the bootstrap defines `CDCF_FRONTEND_URL`, build expected URLs in the tests from it the way `SubmissionStatusHandlerTest` does (`defined('CDCF_FRONTEND_URL') ? rtrim((string) CDCF_FRONTEND_URL, '/') : 'http://localhost:3000'`).

- [ ] **Step 9: Add the `member` branch to the email job**

In `includes/review/email-job.php`, `execute()` becomes:

```php
    public function execute()
    {
        $member    = $this->get_payload_value('member');
        $submitter = $this->get_payload_value('submitter');
        if (is_array($member)) {
            // A member email: built now, so the stored job holds only ids.
            $sent = cdcf_review_deliver_member_email(
                (int) ($member['user_id'] ?? 0),
                (int) ($member['event_id'] ?? 0)
            ) !== 'failed';
        } elseif (is_array($submitter)) {
            // … existing submitter branch, unchanged …
        } else {
            // … existing to/subject/body branch, unchanged …
        }
        // … existing failure throw and success return, unchanged …
    }
```

Add to `tests/ReviewMailerTest.php`:

```php
    public function test_member_job_delivers_by_id_and_retries_only_on_failure(): void
    {
        $calls = [];
        Functions\when('cdcf_review_deliver_member_email')->alias(function (int $uid, int $eid) use (&$calls): string {
            $calls[] = [$uid, $eid];
            return count($calls) === 1 ? 'skipped' : 'failed';
        });
        $job = new CDCF_Review_Email_Job(['member' => ['user_id' => 7, 'event_id' => 3]]);

        $this->assertTrue($job->execute()['success'], 'skipped counts as done');
        $this->expectException(RuntimeException::class);
        $job->execute();
    }
```

- [ ] **Step 10: Run the whole theme suite**

Run: `php8.3 vendor/bin/phpunit`
Expected: OK, no warnings.

- [ ] **Step 11: Commit**

```bash
git add wordpress/themes/cdcf-headless
git commit -m "feat(review): email members on key review events through the queue"
```

---

### Task 3: Inbox data layer and route handlers (Next.js)

**Files:**

- Modify: `lib/review-types.ts`, `lib/review-api.ts`, `lib/review-api.test.ts`
- Create: `lib/review-inbox.ts`, `lib/review-inbox.test.ts`
- Create: `app/api/review/inbox/route.ts`, `app/api/review/inbox/route.test.ts`
- Create: `app/api/review/inbox/count/route.ts`, `app/api/review/inbox/count/route.test.ts`
- Create: `app/api/review/inbox/read/route.ts`, `app/api/review/inbox/read/route.test.ts`

**Interfaces:**

- Consumes: the REST shapes from Task 1.
- Produces:
  - types `ReviewEventType`, `InboxItem`, `InboxPage`, `InboxCount`, `InboxReadPayload`, const `INBOX_READ_MAX = 200`
  - `fetchInbox(session, { unread?: boolean; page?: number }): Promise<InboxPage>`, `fetchInboxCount(session): Promise<InboxCount>`, `markInboxRead(session, payload: InboxReadPayload): Promise<InboxCount>`
  - `inboxHref(item)`, `inboxMessageKey(item)`, `inboxActorKey(item)`, `inboxTitle(item)`, `relativeTime(date, now, locale)`
  - Routes: `GET /api/review/inbox?unread=1&page=N`, `GET /api/review/inbox/count`, `POST /api/review/inbox/read`

- [ ] **Step 1: Types** — append to `lib/review-types.ts`:

```ts
export type ReviewEventType =
  | "submission_received"
  | "review_posted"
  | "review_edited"
  | "vote_changed"
  | "reply_posted"
  | "discussion_posted"
  | "attachment_added"
  | "question_sent"
  | "submitter_answered"
  | "decision_recorded"
  | "admin_override";

export interface InboxItem {
  event_id: number;
  type: ReviewEventType;
  project: { id: number; title: string };
  /** null for the submitter's own actions and migrated records */
  actor: { id: number; name: string } | null;
  entry_id: number | null;
  data: Record<string, unknown>;
  /** UTC, as WordPress stores it: 'Y-m-d H:i:s.u' */
  created_at: string;
  read: boolean;
  /** a reply to the viewer's own review */
  to_you: boolean;
}

export interface InboxPage {
  items: InboxItem[];
  page: number;
  has_more: boolean;
  unread: number;
}

export interface InboxCount {
  unread: number;
}

export type InboxReadPayload = { event_ids: number[] } | { all: true };

export const INBOX_READ_MAX = 200;
```

- [ ] **Step 2: Write the failing helper test** — `lib/review-inbox.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import {
  inboxActorKey,
  inboxHref,
  inboxMessageKey,
  inboxTitle,
  relativeTime,
} from "./review-inbox";
import type { InboxItem } from "./review-types";

const item = (over: Partial<InboxItem> = {}): InboxItem => ({
  event_id: 12,
  type: "discussion_posted",
  project: { id: 40, title: "Liturgy Helper" },
  actor: { id: 8, name: "Fr. A" },
  entry_id: 5,
  data: {},
  created_at: "2026-10-16 09:00:00.000000",
  read: false,
  to_you: false,
  ...over,
});

describe("inboxHref", () => {
  it("links to the entry when there is one", () => {
    expect(inboxHref(item())).toBe("/review/40#entry-5");
    expect(inboxHref(item({ entry_id: null }))).toBe("/review/40");
  });
});

describe("inboxMessageKey", () => {
  it("uses the event type", () => {
    expect(inboxMessageKey(item())).toBe("discussion_posted");
  });
  it("distinguishes a reply to your review", () => {
    expect(inboxMessageKey(item({ type: "reply_posted", to_you: true }))).toBe(
      "reply_posted_to_you",
    );
    expect(inboxMessageKey(item({ type: "reply_posted" }))).toBe(
      "reply_posted",
    );
  });
  it("distinguishes a migrated override", () => {
    expect(
      inboxMessageKey(
        item({ type: "admin_override", actor: null, data: { backfill: true } }),
      ),
    ).toBe("admin_override_backfill");
  });
});

describe("inboxActorKey", () => {
  it("is null when the actor has a name", () => {
    expect(inboxActorKey(item())).toBeNull();
  });
  it("names the submitter for submitter events, someone otherwise", () => {
    expect(
      inboxActorKey(item({ type: "submitter_answered", actor: null })),
    ).toBe("submitter");
    expect(inboxActorKey(item({ type: "admin_override", actor: null }))).toBe(
      "someone",
    );
  });
});

describe("inboxTitle", () => {
  it("falls back to a dash when the project has no title any more", () => {
    expect(inboxTitle(item({ project: { id: 40, title: "" } }))).toBe("—");
    expect(inboxTitle(item())).toBe("Liturgy Helper");
  });
});

describe("relativeTime", () => {
  const now = new Date("2026-10-16T10:00:00Z");
  it("formats past times in the largest whole unit", () => {
    expect(relativeTime(new Date("2026-10-16T09:55:00Z"), now, "en")).toBe(
      "5 min. ago",
    );
    expect(relativeTime(new Date("2026-10-16T07:00:00Z"), now, "en")).toBe(
      "3 hr. ago",
    );
    expect(relativeTime(new Date("2026-10-14T10:00:00Z"), now, "en")).toBe(
      "2 days ago",
    );
  });
  it("says now for under a minute", () => {
    expect(relativeTime(new Date("2026-10-16T09:59:30Z"), now, "en")).toBe(
      "now",
    );
  });
});
```

(If the runtime's ICU prints a different short form for minutes/hours, adjust only those expected strings to the `Intl.RelativeTimeFormat('en', { numeric: 'auto', style: 'short' })` output — the test pins the unit choice, not ICU's spelling.)

- [ ] **Step 3: Run it to verify it fails**

Run: `npx vitest run lib/review-inbox.test.ts`
Expected: FAIL — cannot resolve `./review-inbox`.

- [ ] **Step 4: Implement `lib/review-inbox.ts`**

```ts
/**
 * Pure helpers for the notification bell and the inbox page.
 */
import type { InboxItem } from "@/lib/review-types";

/** The workspace, at the entry when the event has one. */
export function inboxHref(item: InboxItem): string {
  return `/review/${item.project.id}${item.entry_id ? `#entry-${item.entry_id}` : ""}`;
}

/** The message key under Review.inbox.event. */
export function inboxMessageKey(item: InboxItem): string {
  if (item.type === "reply_posted" && item.to_you) return "reply_posted_to_you";
  if (item.type === "admin_override" && item.data.backfill === true)
    return "admin_override_backfill";
  return item.type;
}

/** The Review.inbox key that names an actor without an account name. */
export function inboxActorKey(item: InboxItem): "submitter" | "someone" | null {
  if (item.actor) return null;
  return item.type === "submitter_answered" ||
    item.type === "submission_received"
    ? "submitter"
    : "someone";
}

export function inboxTitle(item: InboxItem): string {
  return item.project.title || "—";
}

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ["year", 31536000],
  ["month", 2592000],
  ["week", 604800],
  ["day", 86400],
  ["hour", 3600],
  ["minute", 60],
];

export function relativeTime(date: Date, now: Date, locale: string): string {
  const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
  const rtf = new Intl.RelativeTimeFormat(locale, {
    numeric: "auto",
    style: "short",
  });
  for (const [unit, size] of UNITS) {
    if (Math.abs(seconds) >= size)
      return rtf.format(Math.round(seconds / size), unit);
  }
  return rtf.format(0, "second");
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `npx vitest run lib/review-inbox.test.ts`
Expected: PASS.

- [ ] **Step 6: Write failing API tests** — add to `lib/review-api.test.ts` (import `fetchInbox`, `fetchInboxCount`, `markInboxRead`):

```ts
describe("inbox", () => {
  it("fetches a page with the unread filter", async () => {
    const page = { items: [], page: 2, has_more: false, unread: 0 };
    fetchMock.mockResolvedValue(jsonResponse(200, page));

    expect(await fetchInbox(session, { unread: true, page: 2 })).toEqual(page);
    expect(fetchMock).toHaveBeenCalledWith(
      "https://wp.example.org/wp-json/cdcf/v1/review/inbox?unread=1&page=2",
      expect.objectContaining({
        method: "GET",
        headers: { Authorization: "Bearer token-abc" },
      }),
    );
  });

  it("omits default query parameters", async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, { items: [], page: 1, has_more: false, unread: 0 }),
    );
    await fetchInbox(session);
    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/inbox",
    );
  });

  it("fetches the unread count", async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { unread: 4 }));
    expect(await fetchInboxCount(session)).toEqual({ unread: 4 });
    expect(fetchMock.mock.calls[0][0]).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/inbox/count",
    );
  });

  it("posts what to mark read", async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { unread: 0 }));
    expect(await markInboxRead(session, { all: true })).toEqual({ unread: 0 });
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/inbox/read",
    );
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body)).toEqual({ all: true });
  });
});
```

Run: `npx vitest run lib/review-api.test.ts` — Expected: FAIL (no such exports).

- [ ] **Step 7: Implement the API calls** — append to `lib/review-api.ts` (add the new types to its type import):

```ts
export function fetchInbox(
  session: Session | null,
  options: { unread?: boolean; page?: number } = {},
): Promise<InboxPage> {
  const params = new URLSearchParams();
  if (options.unread) params.set("unread", "1");
  if (options.page && options.page > 1)
    params.set("page", String(options.page));
  const qs = params.toString();
  return wpRequest<InboxPage>(
    session,
    `/cdcf/v1/review/inbox${qs ? `?${qs}` : ""}`,
  );
}

export function fetchInboxCount(session: Session | null): Promise<InboxCount> {
  return wpRequest<InboxCount>(session, "/cdcf/v1/review/inbox/count");
}

export function markInboxRead(
  session: Session | null,
  payload: InboxReadPayload,
): Promise<InboxCount> {
  return wpRequest<InboxCount>(session, "/cdcf/v1/review/inbox/read", {
    method: "POST",
    json: payload,
  });
}
```

Run: `npx vitest run lib/review-api.test.ts` — Expected: PASS.

- [ ] **Step 8: Write the route handlers' failing tests**

`app/api/review/inbox/route.test.ts` (mirror the decision route test's mocking):

```ts
import { beforeEach, describe, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";

vi.mock("@/lib/auth-utils", () => ({ getAccessToken: vi.fn() }));
const auth = vi.hoisted(() => vi.fn());
vi.mock("@/lib/auth", () => ({ auth }));
const fetchInbox = vi.hoisted(() => vi.fn());
vi.mock("@/lib/review-api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/review-api")>()),
  fetchInbox,
}));

import { GET } from "./route";
import { ReviewApiError } from "@/lib/review-api";

const session = { user: { email: "m@example.org" } };
const get = (qs = "") =>
  GET(new NextRequest(`http://localhost/api/review/inbox${qs}`));

beforeEach(() => {
  auth.mockReset();
  auth.mockResolvedValue(session);
  fetchInbox.mockReset();
});

describe("GET /api/review/inbox", () => {
  it("passes the filter and page through", async () => {
    const page = { items: [], page: 3, has_more: false, unread: 1 };
    fetchInbox.mockResolvedValue(page);
    const res = await get("?unread=1&page=3");
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(page);
    expect(fetchInbox).toHaveBeenCalledWith(session, { unread: true, page: 3 });
  });

  it("treats a junk page as page 1", async () => {
    fetchInbox.mockResolvedValue({
      items: [],
      page: 1,
      has_more: false,
      unread: 0,
    });
    await get("?page=abc");
    expect(fetchInbox).toHaveBeenCalledWith(session, {
      unread: false,
      page: 1,
    });
  });

  it("needs a session", async () => {
    auth.mockResolvedValue(null);
    expect((await get()).status).toBe(401);
  });

  it("maps WordPress errors", async () => {
    fetchInbox.mockRejectedValue(
      new ReviewApiError("no", 403, "rest_forbidden"),
    );
    const res = await get();
    expect(res.status).toBe(403);
    expect(await res.json()).toEqual({
      error: "rest_forbidden",
      message: "no",
    });
  });
});
```

`app/api/review/inbox/count/route.test.ts`: same shape for `fetchInboxCount` — returns `{ unread: 2 }` with 200; 401 without a session; maps a `ReviewApiError(…, 403, 'rest_forbidden')` to 403.

`app/api/review/inbox/read/route.test.ts`:

```ts
import { beforeEach, describe, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";

vi.mock("@/lib/auth-utils", () => ({ getAccessToken: vi.fn() }));
const auth = vi.hoisted(() => vi.fn());
vi.mock("@/lib/auth", () => ({ auth }));
const markInboxRead = vi.hoisted(() => vi.fn());
vi.mock("@/lib/review-api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/review-api")>()),
  markInboxRead,
}));

import { POST } from "./route";

const session = { user: { email: "m@example.org" } };
const post = (body: unknown) =>
  POST(
    new NextRequest("http://localhost/api/review/inbox/read", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }),
  );

beforeEach(() => {
  auth.mockReset();
  auth.mockResolvedValue(session);
  markInboxRead.mockReset();
  markInboxRead.mockResolvedValue({ unread: 0 });
});

describe("POST /api/review/inbox/read", () => {
  it("marks all", async () => {
    const res = await post({ all: true });
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ unread: 0 });
    expect(markInboxRead).toHaveBeenCalledWith(session, { all: true });
  });

  it("marks ids", async () => {
    await post({ event_ids: [4, 5] });
    expect(markInboxRead).toHaveBeenCalledWith(session, { event_ids: [4, 5] });
  });

  it.each([
    [{}],
    [{ event_ids: [] }],
    [{ event_ids: [1, "x"] }],
    [{ event_ids: [0] }],
    [{ event_ids: [1.5] }],
    [{ event_ids: Array.from({ length: 201 }, (_, i) => i + 1) }],
    [{ all: "yes" }],
  ])("rejects %j", async (body) => {
    const res = await post(body);
    expect(res.status).toBe(400);
    expect(markInboxRead).not.toHaveBeenCalled();
  });

  it("needs a session", async () => {
    auth.mockResolvedValue(null);
    expect((await post({ all: true })).status).toBe(401);
  });
});
```

Run: `npx vitest run app/api/review/inbox` — Expected: FAIL (routes missing).

- [ ] **Step 9: Implement the route handlers**

`app/api/review/inbox/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { fetchInbox } from "@/lib/review-api";
import { reviewErrorResponse, unauthorized } from "@/lib/review-route";

export async function GET(request: NextRequest) {
  const session = await auth();
  if (!session?.user) return unauthorized();
  const { searchParams } = request.nextUrl;
  const page = Math.max(
    1,
    Number.parseInt(searchParams.get("page") ?? "1", 10) || 1,
  );
  try {
    return NextResponse.json(
      await fetchInbox(session, {
        unread: searchParams.get("unread") === "1",
        page,
      }),
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/inbox/count/route.ts`:

```ts
import { NextResponse } from "next/server";
import { auth } from "@/lib/auth";
import { fetchInboxCount } from "@/lib/review-api";
import { reviewErrorResponse, unauthorized } from "@/lib/review-route";

export async function GET() {
  const session = await auth();
  if (!session?.user) return unauthorized();
  try {
    return NextResponse.json(await fetchInboxCount(session));
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/review/inbox/read/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { markInboxRead } from "@/lib/review-api";
import {
  badRequest,
  readLimitedJsonObject,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";
import { INBOX_READ_MAX, type InboxReadPayload } from "@/lib/review-types";

function toPayload(body: Record<string, unknown>): InboxReadPayload | null {
  if (body.all === true) return { all: true };
  const ids = body.event_ids;
  if (
    Array.isArray(ids) &&
    ids.length > 0 &&
    ids.length <= INBOX_READ_MAX &&
    ids.every((id) => Number.isInteger(id) && (id as number) > 0)
  ) {
    return { event_ids: ids as number[] };
  }
  return null;
}

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user) return unauthorized();
  const read = await readLimitedJsonObject(request, 16 * 1024);
  if (!read.ok) return badRequest(read.error);
  const payload = toPayload(read.value);
  if (!payload) return badRequest("invalid_field", "event_ids");
  try {
    return NextResponse.json(await markInboxRead(session, payload));
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

- [ ] **Step 10: Run the frontend checks**

Run: `npx vitest run && npx tsc --noEmit && npm run lint`
Expected: all pass.

- [ ] **Step 11: Commit**

```bash
git add lib app/api/review/inbox
git commit -m "feat(review): inbox data layer and route handlers"
```

---

### Task 4: Notification bell

**Files:**

- Modify: `messages/en.json`, `messages/it.json`, `messages/es.json`, `messages/fr.json`, `messages/pt.json`, `messages/de.json` (add `Review.inbox`)
- Create: `components/review/InboxItemSummary.tsx`
- Create: `components/review/NotificationBell.tsx`, `components/review/NotificationBell.test.tsx`
- Modify: `components/AuthButton.tsx`

**Interfaces:**

- Consumes: Task 3's types, helpers and routes; `reviewFetch`, `jsonInit` (`lib/review-client.ts`); `parseWpGmt` (`lib/review-ui.ts`); `useRouter`, `Link` (`@/src/i18n/navigation`).
- Produces: `<InboxItemSummary item now />` (server- and client-safe, no hooks other than next-intl's), `<NotificationBell />`, `BELL_POLL_MS = 60_000`, `BELL_ITEMS = 10`, the `Review.inbox` message keys used by Task 5.

- [ ] **Step 1: Add the strings** — insert a `"inbox"` object inside `"Review"` (after `"errors"`) in each file. Keys and values:

`en`:

```json
"inbox": {
  "bell": "Notifications",
  "bellUnread": "Notifications, {count} unread",
  "title": "Notifications",
  "intro": "Activity on the submissions under review.",
  "all": "All",
  "unread": "Unread",
  "empty": "No notifications.",
  "markAllRead": "Mark all read",
  "viewAll": "View all",
  "loading": "Loading…",
  "loadFailed": "Could not load notifications.",
  "submitter": "The submitter",
  "someone": "Someone",
  "unreadMarker": "Unread",
  "event": {
    "submission_received": "New submission: {title}",
    "review_posted": "{actor} posted a review · {title}",
    "review_edited": "{actor} edited their review · {title}",
    "vote_changed": "{actor} changed their vote · {title}",
    "reply_posted": "{actor} replied to a review · {title}",
    "reply_posted_to_you": "{actor} replied to your review · {title}",
    "discussion_posted": "{actor} posted in the discussion · {title}",
    "attachment_added": "{actor} added an attachment · {title}",
    "question_sent": "{actor} asked the submitter a question · {title}",
    "submitter_answered": "{actor} answered · {title}",
    "decision_recorded": "{actor} recorded the decision · {title}",
    "admin_override": "{actor} published it in WordPress · {title}",
    "admin_override_backfill": "Published in WordPress before review · {title}"
  }
}
```

`it`: bell "Notifiche", bellUnread "Notifiche, {count} da leggere", title "Notifiche", intro "Attività sulle proposte in revisione.", all "Tutte", unread "Da leggere", empty "Nessuna notifica.", markAllRead "Segna tutte come lette", viewAll "Vedi tutte", loading "Caricamento…", loadFailed "Impossibile caricare le notifiche.", submitter "Il proponente", someone "Qualcuno", unreadMarker "Da leggere"; event: submission_received "Nuova proposta: {title}", review_posted "{actor} ha pubblicato una revisione · {title}", review_edited "{actor} ha modificato la sua revisione · {title}", vote_changed "{actor} ha cambiato il suo voto · {title}", reply_posted "{actor} ha risposto a una revisione · {title}", reply_posted_to_you "{actor} ha risposto alla tua revisione · {title}", discussion_posted "{actor} ha scritto nella discussione · {title}", attachment_added "{actor} ha aggiunto un allegato · {title}", question_sent "{actor} ha fatto una domanda al proponente · {title}", submitter_answered "{actor} ha risposto · {title}", decision_recorded "{actor} ha registrato la decisione · {title}", admin_override "{actor} l'ha pubblicato in WordPress · {title}", admin_override_backfill "Pubblicato in WordPress prima della revisione · {title}".

`es`: bell "Notificaciones", bellUnread "Notificaciones, {count} sin leer", title "Notificaciones", intro "Actividad en las propuestas en revisión.", all "Todas", unread "Sin leer", empty "No hay notificaciones.", markAllRead "Marcar todas como leídas", viewAll "Ver todas", loading "Cargando…", loadFailed "No se pudieron cargar las notificaciones.", submitter "El proponente", someone "Alguien", unreadMarker "Sin leer"; event: submission_received "Nueva propuesta: {title}", review_posted "{actor} publicó una revisión · {title}", review_edited "{actor} editó su revisión · {title}", vote_changed "{actor} cambió su voto · {title}", reply_posted "{actor} respondió a una revisión · {title}", reply_posted_to_you "{actor} respondió a tu revisión · {title}", discussion_posted "{actor} escribió en la discusión · {title}", attachment_added "{actor} añadió un archivo adjunto · {title}", question_sent "{actor} hizo una pregunta al proponente · {title}", submitter_answered "{actor} respondió · {title}", decision_recorded "{actor} registró la decisión · {title}", admin_override "{actor} lo publicó en WordPress · {title}", admin_override_backfill "Publicado en WordPress antes de la revisión · {title}".

`fr`: bell "Notifications", bellUnread "Notifications, {count} non lues", title "Notifications", intro "Activité sur les propositions en cours d'évaluation.", all "Toutes", unread "Non lues", empty "Aucune notification.", markAllRead "Tout marquer comme lu", viewAll "Tout voir", loading "Chargement…", loadFailed "Impossible de charger les notifications.", submitter "Le proposant", someone "Quelqu'un", unreadMarker "Non lue"; event: submission_received "Nouvelle proposition : {title}", review_posted "{actor} a publié une évaluation · {title}", review_edited "{actor} a modifié son évaluation · {title}", vote_changed "{actor} a changé son vote · {title}", reply_posted "{actor} a répondu à une évaluation · {title}", reply_posted_to_you "{actor} a répondu à votre évaluation · {title}", discussion_posted "{actor} a écrit dans la discussion · {title}", attachment_added "{actor} a ajouté une pièce jointe · {title}", question_sent "{actor} a posé une question au proposant · {title}", submitter_answered "{actor} a répondu · {title}", decision_recorded "{actor} a enregistré la décision · {title}", admin_override "{actor} l'a publié dans WordPress · {title}", admin_override_backfill "Publié dans WordPress avant l'évaluation · {title}".

`pt`: bell "Notificações", bellUnread "Notificações, {count} por ler", title "Notificações", intro "Atividade nas propostas em revisão.", all "Todas", unread "Por ler", empty "Sem notificações.", markAllRead "Marcar todas como lidas", viewAll "Ver todas", loading "A carregar…", loadFailed "Não foi possível carregar as notificações.", submitter "O proponente", someone "Alguém", unreadMarker "Por ler"; event: submission_received "Nova proposta: {title}", review_posted "{actor} publicou uma revisão · {title}", review_edited "{actor} editou a sua revisão · {title}", vote_changed "{actor} mudou o seu voto · {title}", reply_posted "{actor} respondeu a uma revisão · {title}", reply_posted_to_you "{actor} respondeu à sua revisão · {title}", discussion_posted "{actor} escreveu na discussão · {title}", attachment_added "{actor} adicionou um anexo · {title}", question_sent "{actor} fez uma pergunta ao proponente · {title}", submitter_answered "{actor} respondeu · {title}", decision_recorded "{actor} registou a decisão · {title}", admin_override "{actor} publicou-o no WordPress · {title}", admin_override_backfill "Publicado no WordPress antes da revisão · {title}".

`de`: bell "Benachrichtigungen", bellUnread "Benachrichtigungen, {count} ungelesen", title "Benachrichtigungen", intro "Aktivität bei den Einreichungen in Prüfung.", all "Alle", unread "Ungelesen", empty "Keine Benachrichtigungen.", markAllRead "Alle als gelesen markieren", viewAll "Alle anzeigen", loading "Wird geladen…", loadFailed "Die Benachrichtigungen konnten nicht geladen werden.", submitter "Die einreichende Person", someone "Jemand", unreadMarker "Ungelesen"; event: submission_received "Neue Einreichung: {title}", review_posted "{actor} hat eine Bewertung veröffentlicht · {title}", review_edited "{actor} hat die eigene Bewertung bearbeitet · {title}", vote_changed "{actor} hat die eigene Stimme geändert · {title}", reply_posted "{actor} hat auf eine Bewertung geantwortet · {title}", reply_posted_to_you "{actor} hat auf Ihre Bewertung geantwortet · {title}", discussion_posted "{actor} hat in der Diskussion geschrieben · {title}", attachment_added "{actor} hat einen Anhang hinzugefügt · {title}", question_sent "{actor} hat der einreichenden Person eine Frage gestellt · {title}", submitter_answered "{actor} hat geantwortet · {title}", decision_recorded "{actor} hat die Entscheidung erfasst · {title}", admin_override "{actor} hat es in WordPress veröffentlicht · {title}", admin_override_backfill "Vor der Prüfung in WordPress veröffentlicht · {title}".

Note: `submitter_answered` reads "{actor} answered" with `{actor}` = the `submitter` label ("The submitter answered"); `submission_received` has no `{actor}`.

If the repo has a test that keeps all six message files' key sets equal, run it; otherwise compare the key sets with a one-off `node -e` check before committing.

- [ ] **Step 2: Implement `components/review/InboxItemSummary.tsx`**

```tsx
import { useLocale, useTranslations } from "next-intl";
import {
  inboxActorKey,
  inboxMessageKey,
  inboxTitle,
  relativeTime,
} from "@/lib/review-inbox";
import { parseWpGmt } from "@/lib/review-ui";
import type { InboxItem } from "@/lib/review-types";

/**
 * One notification's sentence and age. No 'use client': renders in the
 * inbox page (server) and inside the bell (client) alike.
 */
export function InboxItemSummary({
  item,
  now,
}: {
  item: InboxItem;
  now: Date;
}) {
  const t = useTranslations("Review.inbox");
  const locale = useLocale();
  const actorKey = inboxActorKey(item);
  const created = parseWpGmt(item.created_at);
  return (
    <span className="flex flex-col gap-0.5">
      <span
        className={item.read ? "text-gray-600" : "font-medium text-gray-900"}
      >
        {t(`event.${inboxMessageKey(item)}` as "event.review_posted", {
          actor: actorKey ? t(actorKey) : (item.actor?.name ?? ""),
          title: inboxTitle(item),
        })}
      </span>
      <time dateTime={created.toISOString()} className="text-xs text-gray-500">
        {relativeTime(created, now, locale)}
      </time>
    </span>
  );
}
```

- [ ] **Step 3: Write the failing bell test** — `components/review/NotificationBell.test.tsx`:

```tsx
// @vitest-environment happy-dom
import { act } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const push = vi.fn();
vi.mock("@/src/i18n/navigation", () => ({
  useRouter: () => ({ push }),
  Link: ({
    href,
    children,
    ...rest
  }: {
    href: string;
    children: React.ReactNode;
  }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import { BELL_POLL_MS, NotificationBell } from "./NotificationBell";
import { buttonByText, click, renderDom } from "@/components/testing/renderDom";
import type { InboxItem } from "@/lib/review-types";

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });

const item = (over: Partial<InboxItem> = {}): InboxItem => ({
  event_id: 12,
  type: "reply_posted",
  project: { id: 40, title: "Liturgy Helper" },
  actor: { id: 8, name: "Fr. A" },
  entry_id: 5,
  data: {},
  created_at: "2026-10-16 09:00:00.000000",
  read: false,
  to_you: true,
  ...over,
});

let unmount = () => {};
let fetchMock: ReturnType<typeof vi.fn>;
beforeEach(() => {
  push.mockReset();
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => {
  unmount();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

async function flush() {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
}

async function mount() {
  const r = renderDom(<NotificationBell />);
  unmount = r.unmount;
  await flush();
  return r.container;
}

const bell = (c: HTMLElement) =>
  c.querySelector("button[aria-haspopup]") as HTMLButtonElement;

describe("NotificationBell", () => {
  it("shows the unread count from the count route", async () => {
    fetchMock.mockResolvedValue(json({ unread: 3 }));
    const c = await mount();
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/review/inbox/count",
      expect.anything(),
    );
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications, 3 unread");
    expect(c.textContent).toContain("3");
  });

  it("keeps the last count when the count route fails", async () => {
    vi.useFakeTimers();
    fetchMock.mockResolvedValueOnce(json({ unread: 2 }));
    fetchMock.mockResolvedValueOnce(json({ error: "unauthorized" }, 401));
    const c = await mount();
    await act(async () => {
      vi.advanceTimersByTime(BELL_POLL_MS);
    });
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications, 2 unread");
  });

  it("opens the latest items and follows one, marking it read", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({ items: [item()], page: 1, has_more: false, unread: 1 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    expect(c.textContent).toContain(
      "Fr. A replied to your review · Liturgy Helper",
    );

    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    await act(async () =>
      click(buttonByText(c, "Fr. A replied to your review · Liturgy Helper")),
    );
    await flush();
    const [url, init] = fetchMock.mock.calls.at(-1)!;
    expect(url).toBe("/api/review/inbox/read");
    expect(JSON.parse(init.body)).toEqual({ event_ids: [12] });
    expect(push).toHaveBeenCalledWith("/review/40#entry-5");
  });

  it("marks all read", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(
      json({ items: [item()], page: 1, has_more: false, unread: 1 }),
    );
    await act(async () => click(bell(c)));
    await flush();
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    await act(async () => click(buttonByText(c, "Mark all read")));
    await flush();
    expect(JSON.parse(fetchMock.mock.calls.at(-1)![1].body)).toEqual({
      all: true,
    });
    expect(bell(c).getAttribute("aria-label")).toBe("Notifications");
  });

  it("says so when the list cannot load", async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }));
    const c = await mount();
    fetchMock.mockResolvedValueOnce(json({ error: "x" }, 500));
    await act(async () => click(bell(c)));
    await flush();
    expect(c.textContent).toContain("Could not load notifications.");
  });

  it("does not poll while the tab is hidden", async () => {
    vi.useFakeTimers();
    fetchMock.mockResolvedValue(json({ unread: 0 }));
    await mount();
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      get: () => "hidden",
    });
    await act(async () => {
      document.dispatchEvent(new Event("visibilitychange"));
      vi.advanceTimersByTime(BELL_POLL_MS * 3);
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      get: () => "visible",
    });
  });
});
```

Adapt helper names to `components/testing/renderDom.tsx` (`click`, `buttonByText` exist there); if `buttonByText` matches exact text, the item button's text must be exactly the sentence — keep the age in a sibling element outside that button's text or match with `includes`. Prefer making the test helper usage fit the component, not loosening the assertions.

Run: `npx vitest run components/review/NotificationBell.test.tsx` — Expected: FAIL (module missing).

- [ ] **Step 4: Implement `components/review/NotificationBell.tsx`**

```tsx
"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { BellIcon } from "@heroicons/react/24/outline";
import { Link, useRouter } from "@/src/i18n/navigation";
import { jsonInit, reviewFetch } from "@/lib/review-client";
import { inboxHref } from "@/lib/review-inbox";
import type { InboxCount, InboxItem, InboxPage } from "@/lib/review-types";
import { InboxItemSummary } from "./InboxItemSummary";

export const BELL_POLL_MS = 60_000;
export const BELL_ITEMS = 10;

/**
 * Header bell for council and Board members. Polls the unread count every
 * minute while the tab is visible; the dropdown shows the latest
 * notifications. Every failure keeps the last known state.
 */
export function NotificationBell() {
  const t = useTranslations("Review.inbox");
  const router = useRouter();
  const [unread, setUnread] = useState(0);
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<InboxItem[] | null>(null);
  const [failed, setFailed] = useState(false);
  const root = useRef<HTMLDivElement>(null);

  const refreshCount = useCallback(async () => {
    const result = await reviewFetch<InboxCount>("/api/review/inbox/count");
    if (result.ok) setUnread(result.data.unread);
  }, []);

  useEffect(() => {
    let timer: ReturnType<typeof setInterval> | null = null;
    const stop = () => {
      if (timer) clearInterval(timer);
      timer = null;
    };
    const sync = () => {
      if (document.visibilityState !== "visible") return stop();
      if (timer) return;
      void refreshCount();
      timer = setInterval(() => void refreshCount(), BELL_POLL_MS);
    };
    sync();
    document.addEventListener("visibilitychange", sync);
    return () => {
      stop();
      document.removeEventListener("visibilitychange", sync);
    };
  }, [refreshCount]);

  useEffect(() => {
    if (!open) return;
    const onPointer = (e: MouseEvent) => {
      if (root.current && !root.current.contains(e.target as Node))
        setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onPointer);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onPointer);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const toggle = async () => {
    if (open) return setOpen(false);
    setOpen(true);
    setFailed(false);
    const result = await reviewFetch<InboxPage>("/api/review/inbox");
    if (result.ok) {
      setItems(result.data.items.slice(0, BELL_ITEMS));
      setUnread(result.data.unread);
    } else {
      setFailed(true);
    }
  };

  const follow = async (item: InboxItem) => {
    setOpen(false);
    if (!item.read) {
      const result = await reviewFetch<InboxCount>(
        "/api/review/inbox/read",
        jsonInit("POST", { event_ids: [item.event_id] }),
      );
      if (result.ok) setUnread(result.data.unread);
    }
    router.push(inboxHref(item));
  };

  const markAll = async () => {
    const result = await reviewFetch<InboxCount>(
      "/api/review/inbox/read",
      jsonInit("POST", { all: true }),
    );
    if (!result.ok) return;
    setUnread(result.data.unread);
    setItems((prev) => prev?.map((i) => ({ ...i, read: true })) ?? prev);
  };

  const now = new Date();
  return (
    <div ref={root} className="relative">
      <button
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={unread > 0 ? t("bellUnread", { count: unread }) : t("bell")}
        onClick={() => void toggle()}
        className="relative inline-flex items-center rounded-md p-2 text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
      >
        <BellIcon className="h-5 w-5" aria-hidden="true" />
        {unread > 0 && (
          <span
            aria-hidden="true"
            className="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] font-semibold leading-4 text-white"
          >
            {unread > 99 ? "99+" : unread}
          </span>
        )}
      </button>
      {open && (
        <div className="absolute right-0 top-full z-50 mt-1 w-80 max-w-[calc(100vw-2rem)] rounded-md border border-gray-200 bg-white shadow-lg">
          <div className="flex items-center justify-between border-b border-gray-100 px-4 py-2">
            <span className="text-sm font-semibold text-gray-900">
              {t("title")}
            </span>
            <button
              type="button"
              onClick={() => void markAll()}
              disabled={unread === 0}
              className="text-xs text-cdcf-navy hover:underline disabled:text-gray-400 disabled:no-underline"
            >
              {t("markAllRead")}
            </button>
          </div>
          {failed ? (
            <p className="px-4 py-3 text-sm text-red-700">{t("loadFailed")}</p>
          ) : items === null ? (
            <p className="px-4 py-3 text-sm text-gray-500">{t("loading")}</p>
          ) : items.length === 0 ? (
            <p className="px-4 py-3 text-sm text-gray-500">{t("empty")}</p>
          ) : (
            <ul className="max-h-96 divide-y divide-gray-100 overflow-y-auto">
              {items.map((item) => (
                <li key={item.event_id}>
                  <button
                    type="button"
                    onClick={() => void follow(item)}
                    className="flex w-full items-start gap-2 px-4 py-2 text-left text-sm hover:bg-gray-50"
                  >
                    <span
                      aria-hidden="true"
                      className={
                        item.read
                          ? "mt-1.5 h-2 w-2 shrink-0"
                          : "mt-1.5 h-2 w-2 shrink-0 rounded-full bg-cdcf-navy"
                      }
                    />
                    <InboxItemSummary item={item} now={now} />
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="border-t border-gray-100 px-4 py-2 text-right">
            <Link
              href="/review/inbox"
              onClick={() => setOpen(false)}
              className="text-sm text-cdcf-navy hover:underline"
            >
              {t("viewAll")}
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
```

(The item button's accessible text includes the age; adjust the test's lookup to match the sentence by substring if `buttonByText` compares whole text. Keep `cdcf-navy` only if it is a defined colour token — it is used in `AuthButton.tsx`.)

- [ ] **Step 5: Mount it in `components/AuthButton.tsx`**

Import `{ NotificationBell } from '@/components/review/NotificationBell'`. In the authenticated return, wrap the existing `<div className="relative">…</div>` so the bell sits to its left for members only:

```tsx
return (
  <div className="flex items-center gap-1">
    {hasReviewAccess && <NotificationBell />}
    <div className="relative">
      {/* … existing trigger button and menu, unchanged … */}
    </div>
  </div>
);
```

Update the component's doc comment: "…plus, for council and Board members, the notification bell."

- [ ] **Step 6: Run the checks**

Run: `npx vitest run && npx tsc --noEmit && npm run lint`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add messages components
git commit -m "feat(review): notification bell in the header for council members"
```

---

### Task 5: Inbox page and documentation

**Files:**

- Create: `app/[lang]/review/inbox/page.tsx`
- Create: `components/review/InboxMarkAllRead.tsx`
- Modify: `app/[lang]/review/page.tsx` (a "Notifications" link in the header)
- Modify: `AGENTS.md` (REST rows; bell/emails paragraph)

**Interfaces:**

- Consumes: `fetchReviewMe`, `fetchInbox`, `ReviewApiError` (Task 3 / existing), `InboxItemSummary`, `inboxHref`, `Review.inbox` strings (Task 4), `ReviewNotice`, `SignInAgain` (existing).
- Produces: route `/[lang]/review/inbox?unread=1&page=N`.

- [ ] **Step 1: `components/review/InboxMarkAllRead.tsx`**

```tsx
"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "@/src/i18n/navigation";
import { jsonInit, reviewFetch } from "@/lib/review-client";
import type { InboxCount } from "@/lib/review-types";

export function InboxMarkAllRead({ disabled }: { disabled: boolean }) {
  const t = useTranslations("Review.inbox");
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const markAll = async () => {
    setBusy(true);
    const result = await reviewFetch<InboxCount>(
      "/api/review/inbox/read",
      jsonInit("POST", { all: true }),
    );
    setBusy(false);
    if (result.ok) router.refresh();
  };
  return (
    <button
      type="button"
      onClick={() => void markAll()}
      disabled={disabled || busy}
      className="text-sm text-cdcf-navy hover:underline disabled:text-gray-400 disabled:no-underline"
    >
      {t("markAllRead")}
    </button>
  );
}
```

- [ ] **Step 2: `app/[lang]/review/inbox/page.tsx`**

Follow `app/[lang]/review/page.tsx`: sign-in redirect, data loading outside the component body (no JSX in try/catch), `ReviewNotice` for non-members and expired sessions.

```tsx
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import clsx from "clsx";
import type { Session } from "next-auth";
import { auth } from "@/lib/auth";
import { ReviewApiError, fetchInbox, fetchReviewMe } from "@/lib/review-api";
import { inboxHref } from "@/lib/review-inbox";
import type { InboxPage } from "@/lib/review-types";
import { Link } from "@/src/i18n/navigation";
import { InboxItemSummary } from "@/components/review/InboxItemSummary";
import { InboxMarkAllRead } from "@/components/review/InboxMarkAllRead";
import { ReviewNotice } from "@/components/review/ReviewNotice";
import { SignInAgain } from "@/components/review/SignInAgain";

export default async function ReviewInboxPage({
  searchParams,
}: {
  params: Promise<{ lang: string }>;
  searchParams: Promise<{ unread?: string; page?: string }>;
}) {
  const session = await auth();
  if (!session?.user) {
    redirect("/api/auth/signin");
  }
  const t = await getTranslations("Review");
  const query = await searchParams;
  const unread = query.unread === "1";
  const page = Math.max(1, Number.parseInt(query.page ?? "1", 10) || 1);

  const loaded = await loadInbox(session, unread, page);
  if (loaded.kind === "notice") {
    return (
      <ReviewNotice
        title={t("inbox.title")}
        message={t(loaded.message)}
        action={
          loaded.message === "errors.sessionExpired" ? (
            <SignInAgain />
          ) : undefined
        }
      />
    );
  }
  const { inbox } = loaded;
  const href = (u: boolean, p = 1) => {
    const params = new URLSearchParams();
    if (u) params.set("unread", "1");
    if (p > 1) params.set("page", String(p));
    const qs = params.toString();
    return `/review/inbox${qs ? `?${qs}` : ""}`;
  };
  const now = new Date();

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-3xl space-y-6">
        <header className="space-y-2">
          <Link
            href="/review"
            className="text-sm text-cdcf-navy hover:underline"
          >
            ← {t("backToList")}
          </Link>
          <h1 className="cdcf-heading text-3xl">{t("inbox.title")}</h1>
          <p className="text-gray-600">{t("inbox.intro")}</p>
        </header>
        <div className="flex items-center justify-between border-b border-gray-200">
          <nav className="flex gap-2" aria-label={t("inbox.title")}>
            {[false, true].map((u) => (
              <Link
                key={String(u)}
                href={href(u)}
                aria-current={u === unread ? "page" : undefined}
                className={clsx(
                  "-mb-px border-b-2 px-3 py-2 text-sm font-medium",
                  u === unread
                    ? "border-cdcf-navy text-cdcf-navy"
                    : "border-transparent text-gray-500 hover:text-gray-800",
                )}
              >
                {u ? t("inbox.unread") : t("inbox.all")}
              </Link>
            ))}
          </nav>
          <InboxMarkAllRead disabled={inbox.unread === 0} />
        </div>
        {inbox.items.length === 0 ? (
          <p className="text-gray-500">{t("inbox.empty")}</p>
        ) : (
          <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
            {inbox.items.map((item) => (
              <li key={item.event_id}>
                <Link
                  href={inboxHref(item)}
                  className="flex items-start gap-3 px-4 py-3 text-sm hover:bg-gray-50"
                >
                  <span
                    className={
                      item.read
                        ? "mt-1.5 h-2 w-2 shrink-0"
                        : "mt-1.5 h-2 w-2 shrink-0 rounded-full bg-cdcf-navy"
                    }
                  >
                    {!item.read && (
                      <span className="sr-only">{t("inbox.unreadMarker")}</span>
                    )}
                  </span>
                  <InboxItemSummary item={item} now={now} />
                </Link>
              </li>
            ))}
          </ul>
        )}
        {(page > 1 || inbox.has_more) && (
          <nav className="flex justify-between text-sm">
            {page > 1 ? (
              <Link
                href={href(unread, page - 1)}
                className="text-cdcf-navy hover:underline"
              >
                ← {t("newer")}
              </Link>
            ) : (
              <span />
            )}
            {inbox.has_more && (
              <Link
                href={href(unread, page + 1)}
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

type InboxLoad =
  | { kind: "ok"; inbox: InboxPage }
  | { kind: "notice"; message: "notMember" | "errors.sessionExpired" };

// Data loading stays outside the component body so no JSX is built inside
// try/catch (react-hooks/error-boundaries).
async function loadInbox(
  session: Session,
  unread: boolean,
  page: number,
): Promise<InboxLoad> {
  try {
    const me = await fetchReviewMe(session);
    if (!me.member) return { kind: "notice", message: "notMember" };
    return { kind: "ok", inbox: await fetchInbox(session, { unread, page }) };
  } catch (err) {
    if (
      err instanceof ReviewApiError &&
      (err.status === 401 || err.status === 403)
    ) {
      return {
        kind: "notice",
        message: err.status === 401 ? "errors.sessionExpired" : "notMember",
      };
    }
    throw err;
  }
}
```

Check `ReviewNotice`'s and `SignInAgain`'s actual props in `components/review/` and match them. Opening an item's workspace marks that project's notifications read (Task 1), so the list's links need no client code.

- [ ] **Step 3: Link from the review list** — in `app/[lang]/review/page.tsx`'s `<header>`, after the intro paragraph:

```tsx
<Link href="/review/inbox" className="text-sm text-cdcf-navy hover:underline">
  {t("inbox.title")}
</Link>
```

- [ ] **Step 4: Documentation** — in `AGENTS.md`:

1. Add three rows to the REST table after `/review/projects/{id}/question` (keep the table's column alignment; `npm run format:md` re-pads it):

   | `GET` | `/review/inbox` | The caller's notifications, newest first, 20 per page (`unread=1` filter, `page`), with project title, actor name, `to_you` for replies to the caller's review, and the `unread` total. Council/Board members only. |
   | `GET` | `/review/inbox/count` | `{ unread }` for the header bell. |
   | `POST` | `/review/inbox/read` | Mark notifications read: `{ event_ids: [] }` (at most 200) or `{ all: true }`. Opening a project's workspace (`GET /review/projects/{id}`) also marks that project's notifications read. |

2. In the "Council review" section, after the paragraph about review emails going through the Redis queue, add (with a blank line before and after):

   ```markdown
   Members see every event in the header bell (`components/review/NotificationBell.tsx`,
   which polls `/api/review/inbox/count` every minute while the tab is visible) and on
   `/[lang]/review/inbox`. Email goes out only for key events
   (`includes/review/member-emails.php`, listening on `cdcf_review_event_recorded`): a new
   submission, a submitter's answer, a decision and an administrator override to every
   member except the one who acted, and a reply to the author of the review it answers.
   Member emails are in the member's WordPress locale, carry no review content, and are
   queued holding only the user and event ids; the migration's backfilled overrides are
   never emailed.
   ```

Run: `npm run format:md && npm run lint:md` — Expected: clean.

- [ ] **Step 5: Run every check**

Run: `npx vitest run && npx tsc --noEmit && npm run lint && (cd wordpress/themes/cdcf-headless && php8.3 vendor/bin/phpunit)`
Expected: all pass. `npm run build` may fail in a worktree whose `node_modules` is a symlink (Turbopack); `npx next build --webpack` is the fallback check.

- [ ] **Step 6: Commit**

```bash
git add app components AGENTS.md
git commit -m "feat(review): notifications inbox page; document the bell and member emails"
```
