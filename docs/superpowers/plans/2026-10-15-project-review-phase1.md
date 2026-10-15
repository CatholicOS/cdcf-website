# Council Project Review — Phase 1 (data layer, membership, core endpoints) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give council and Board members a WordPress-side review backend: storage
tables, membership derived from the About page, and REST endpoints to list
submissions, read one, write a review with a vote, reply, and discuss.

**Architecture:** Four custom tables (`dbDelta`, versioned option) hold entries,
attachments (schema only, used in Phase 2), events and per-user notifications.
Membership is computed from the English About page's council relationship
fields against the caller's `author_team_member` Polylang group, cached per
user behind a generation counter. Thin REST handlers in
`includes/handlers/review.php` sit on small repository/event/membership
modules under `includes/review/`.

**Tech Stack:** PHP 8.3, WordPress REST API, ACF, Polylang, `$wpdb`/`dbDelta`;
PHPUnit 10 + Brain Monkey + Mockery (Patchwork lets tests stub `cdcf_*`
functions).

**Spec:** `docs/superpowers/specs/2026-10-15-project-review-design.md`

This is the first of five plans (spec § Delivery). Phases 2–5 (UI and
attachments, submitter status, decision and conversion, bell and emails) get
their own plans once this phase merges, so they can build on the interfaces
actually shipped here.

## Global Constraints

- All routes live under the `cdcf/v1` namespace, path prefix `/review/`.
- Every route declares `sanitize_callback` per field in its `args` block;
  handlers do not re-sanitize (AGENTS.md § Sanitization convention).
- New PHP include files start with the one-line `defined('ABSPATH') || exit;`
  guard (not a multi-line `if`), so Codecov patch coverage isn't lost.
- Stages: `in_review` · `awaiting_submitter` · `decided`. Votes: `cdcf` ·
  `community` · `reject`. Entry kinds: `review` · `reply` · `discussion` ·
  `question` · `answer`.
- Permissions: `view`/`discuss` = any of technical, ecclesial, board; `vote` =
  technical or ecclesial; `decide` = board. Administrators who are not members
  get no access.
- Entry bodies are Markdown, at most 50,000 characters, never empty after trim.
- When stage is `decided`, every write endpoint returns `403 review_locked`.
- Status codes: `401` anonymous, `403` missing permission / `review_locked` /
  not the author, `404` unknown project or entry, `409` stale `edited_at` or
  duplicate review, `400` invalid input.
- Timestamps are UTC `DATETIME(6)` strings (`Y-m-d H:i:s.u`) so
  optimistic-concurrency checks don't collide within one second.
- Commit messages end with
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Double-submitted first review** (double click, two tabs): the second
   insert hits the `one_review` unique key — the reviewer expects a `409`
   telling them to reload, not a `500`. Test in Task 5.
2. **Council roster changes mid-review**: removing someone from a council on
   the About page, or re-linking a user's bio, must take effect at once, not
   after the cache TTL. Tests in Task 2 (generation bump on About save and on
   `author_team_member` user-meta writes).
3. **Project trashed while under review**: reviewers expect "not found", not a
   workspace for a trashed post. Test in Task 4.
4. **Whitespace-only or oversized bodies**: an empty review or a 60k-character
   paste must be rejected with a clear `400`. Tests in Task 5.
5. **Reply aimed at the wrong parent** (a reply, a discussion post, or a review
   in another project): must be `400 invalid_parent`, never a silently
   misplaced reply. Test in Task 5.

---

## File structure

| File                                                                                                          | Responsibility                                                                                                                              |
| ------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `wordpress/themes/cdcf-headless/includes/review/schema.php` (create)                                          | Constants, table names, `CREATE TABLE` SQL, upgrade routine, one-time stage backfill, UTC clock                                             |
| `wordpress/themes/cdcf-headless/includes/review/membership.php` (create)                                      | Council id lookup, per-user membership with cache, permission check, member user ids, cache invalidation hooks, roster for the admin screen |
| `wordpress/themes/cdcf-headless/includes/review/repository.php` (create)                                      | Entry CRUD, compare-and-set update, tallies, plurality, per-user votes                                                                      |
| `wordpress/themes/cdcf-headless/includes/review/events.php` (create)                                          | Event recording with notification fan-out, unread counts                                                                                    |
| `wordpress/themes/cdcf-headless/includes/handlers/review.php` (create)                                        | Permission callbacks and REST handlers                                                                                                      |
| `wordpress/themes/cdcf-headless/includes/admin/review-access.php` (create)                                    | Projects → Review access admin screen                                                                                                       |
| `wordpress/themes/cdcf-headless/functions.php` (modify)                                                       | Requires, hooks, route registration                                                                                                         |
| `wordpress/themes/cdcf-headless/includes/handlers/submit-project.php` (modify)                                | New submissions enter `in_review` and record `submission_received`                                                                          |
| `wordpress/themes/cdcf-headless/tests/FakeWpdb.php` (create)                                                  | Recording `$wpdb` test double                                                                                                               |
| `wordpress/themes/cdcf-headless/tests/bootstrap.php` (modify)                                                 | `ARRAY_A`, new requires, `FakeWpdb`                                                                                                         |
| `wordpress/themes/cdcf-headless/tests/Review*Test.php` (create)                                               | One test file per module                                                                                                                    |
| `wordpress/themes/cdcf-headless/tests/SubmissionHandlerTestBase.php`, `SubmitProjectHandlerTest.php` (modify) | Stub/assert the new submit-project behaviour                                                                                                |
| `AGENTS.md` (modify)                                                                                          | REST table rows and a short section for the review endpoints                                                                                |
| `docs/superpowers/specs/2026-10-15-project-review-design.md` (modify)                                         | Two refinements discovered while planning (Task 8)                                                                                          |

All commands below run from `wordpress/themes/cdcf-headless/` unless noted.
Run the suite with `composer test` (or `vendor/bin/phpunit --filter <Name>`).

---

### Task 1: Schema, upgrade routine, backfill, and the `$wpdb` test double

**Files:**

- Create: `includes/review/schema.php`
- Create: `tests/FakeWpdb.php`
- Modify: `tests/bootstrap.php`
- Test: `tests/ReviewSchemaTest.php`

**Interfaces:**

- Produces:
  - constants `CDCF_REVIEW_DB_VERSION = '1'`, `CDCF_REVIEW_STAGE_META = '_cdcf_review_stage'`,
    `CDCF_REVIEW_STAGES`, `CDCF_REVIEW_VOTES`, `CDCF_REVIEW_BODY_MAX = 50000`
  - `cdcf_review_table(string $name): string` — `$wpdb->prefix . 'cdcf_review_' . $name`
  - `cdcf_review_schema_sql(string $charset_collate): array` — four `CREATE TABLE` strings, order entries, attachments, events, notifications
  - `cdcf_review_maybe_upgrade(): void`
  - `cdcf_review_backfill_stage(): int`
  - `cdcf_review_now(): string` — UTC `Y-m-d H:i:s.u`
  - test double `FakeWpdb` (see code)

- [ ] **Step 1: Create the `$wpdb` test double**

`tests/FakeWpdb.php`:

```php
<?php

declare(strict_types=1);

/**
 * Recording stand-in for $wpdb. Every SQL string passed to query/get_*
 * lands in $queries; every insert lands in $inserts. Read methods pop
 * their return value from the FIFO $results queue (falling back to an
 * empty result), so a test scripts the database by pushing values in
 * the order the code under test will read them.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    /** @var list<array{table: string, data: array<string, mixed>}> */
    public array $inserts = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<mixed> */
    public array $results = [];
    public bool $failInsert = false;
    private int $nextId = 1;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $query = str_replace(['%d', '%s'], ['%d', "'%s'"], $query);
        return vsprintf($query, array_map(
            static fn($a) => is_string($a) ? addslashes($a) : $a,
            $args
        ));
    }

    public function insert(string $table, array $data, mixed $format = null): int|false
    {
        if ($this->failInsert) {
            $this->last_error = 'Duplicate entry';
            return false;
        }
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = $this->nextId++;
        return 1;
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;
        return array_shift($this->results) ?? 1;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $this->queries[] = $sql;
        return array_shift($this->results) ?? [];
    }

    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        $this->queries[] = $sql;
        return array_shift($this->results);
    }

    public function get_var(string $sql): mixed
    {
        $this->queries[] = $sql;
        return array_shift($this->results);
    }
}
```

- [ ] **Step 2: Register the double and `ARRAY_A` in the bootstrap**

In `tests/bootstrap.php`, after the `DAY_IN_SECONDS` line add:

```php
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
```

and at the very end of the file (after the other base-class requires) add:

```php
require_once __DIR__ . '/FakeWpdb.php';
```

Also add, directly after the `require_once __DIR__ . '/../includes/handlers/my-team-member.php';` line:

```php
require_once __DIR__ . '/../includes/review/schema.php';
```

- [ ] **Step 3: Write the failing tests**

`tests/ReviewSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/schema.php — table SQL, the versioned
 * upgrade routine and the one-time stage backfill.
 */
final class ReviewSchemaTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_table_names_use_wpdb_prefix(): void
    {
        $this->assertSame('wp_cdcf_review_entries', cdcf_review_table('entries'));
    }

    public function test_schema_sql_defines_four_tables_in_order(): void
    {
        $sql = cdcf_review_schema_sql('DEFAULT CHARACTER SET utf8mb4');

        $this->assertCount(4, $sql);
        foreach (['entries', 'attachments', 'events', 'notifications'] as $i => $table) {
            $this->assertStringContainsString("CREATE TABLE wp_cdcf_review_{$table} (", $sql[$i]);
            $this->assertStringEndsWith('DEFAULT CHARACTER SET utf8mb4;', $sql[$i]);
        }
    }

    public function test_entries_table_enforces_one_review_per_reviewer(): void
    {
        $sql = cdcf_review_schema_sql('')[0];

        $this->assertStringContainsString('review_slot bigint(20) unsigned DEFAULT NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY one_review (project_id,review_slot)', $sql);
        $this->assertStringContainsString('created_at datetime(6) NOT NULL', $sql);
    }

    public function test_notifications_primary_key_is_event_and_user(): void
    {
        $sql = cdcf_review_schema_sql('')[3];

        $this->assertStringContainsString('PRIMARY KEY  (event_id,user_id)', $sql);
        $this->assertStringContainsString('KEY user_unread (user_id,read_at)', $sql);
    }

    public function test_maybe_upgrade_is_a_noop_when_version_is_current(): void
    {
        Functions\when('get_option')->justReturn(CDCF_REVIEW_DB_VERSION);
        Functions\expect('dbDelta')->never();
        Functions\expect('update_option')->never();

        cdcf_review_maybe_upgrade();

        $this->addToAssertionCount(1);
    }

    public function test_maybe_upgrade_runs_dbdelta_backfills_and_stores_version(): void
    {
        Functions\when('get_option')->justReturn(false);
        $delta = [];
        Functions\when('dbDelta')->alias(function (string $sql) use (&$delta): array {
            $delta[] = $sql;
            return [];
        });
        Functions\when('get_posts')->justReturn([11, 12]);
        $meta = [];
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use (&$meta): bool {
            $meta[] = [$id, $key, $value];
            return true;
        });
        Functions\expect('update_option')->once()->with('cdcf_review_db_version', CDCF_REVIEW_DB_VERSION);

        cdcf_review_maybe_upgrade();

        $this->assertCount(4, $delta);
        $this->assertSame(
            [[11, '_cdcf_review_stage', 'in_review'], [12, '_cdcf_review_stage', 'in_review']],
            $meta
        );
    }

    public function test_backfill_targets_pending_public_submissions_without_a_stage(): void
    {
        $args = null;
        Functions\when('get_posts')->alias(function (array $a) use (&$args): array {
            $args = $a;
            return [];
        });

        $this->assertSame(0, cdcf_review_backfill_stage());
        $this->assertSame('project', $args['post_type']);
        $this->assertSame('pending', $args['post_status']);
        $this->assertSame('ids', $args['fields']);
        $this->assertSame(
            [
                ['key' => '_submission_submitter_email', 'compare' => 'EXISTS'],
                ['key' => '_cdcf_review_stage', 'compare' => 'NOT EXISTS'],
            ],
            $args['meta_query']
        );
    }

    public function test_now_is_utc_with_microseconds(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/',
            cdcf_review_now()
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ReviewSchemaTest`
Expected: FAIL — `require_once(.../includes/review/schema.php): Failed to open stream`.

- [ ] **Step 5: Implement `includes/review/schema.php`**

```php
<?php
/**
 * Council review of submitted projects — storage schema.
 *
 * Deliberations live in custom tables (not comments, not a CPT) so no
 * core, WPGraphQL or Polylang surface can expose them, including after
 * the project is published. See
 * docs/superpowers/specs/2026-10-15-project-review-design.md.
 *
 *   cdcf_review_entries        reviews, replies, discussion, submitter Q&A
 *   cdcf_review_attachments    files attached to entries (used from Phase 2)
 *   cdcf_review_events         activity log behind the bell and emails
 *   cdcf_review_notifications  one row per (event, recipient) with read state
 *
 * One review per reviewer per project is enforced by the unique key on
 * (project_id, review_slot): review_slot carries the author id on
 * `review` rows and NULL on every other kind (MySQL allows many NULLs).
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_DB_VERSION  = '1';
const CDCF_REVIEW_STAGE_META  = '_cdcf_review_stage';
const CDCF_REVIEW_STAGES      = ['in_review', 'awaiting_submitter', 'decided'];
const CDCF_REVIEW_VOTES       = ['cdcf', 'community', 'reject'];
const CDCF_REVIEW_BODY_MAX    = 50000;

function cdcf_review_table(string $name): string {
    global $wpdb;
    return $wpdb->prefix . 'cdcf_review_' . $name;
}

/**
 * dbDelta-compatible CREATE TABLE statements (two spaces after
 * PRIMARY KEY, one column per line, no backticks).
 *
 * @return list<string>
 */
function cdcf_review_schema_sql(string $charset_collate): array {
    $entries       = cdcf_review_table('entries');
    $attachments   = cdcf_review_table('attachments');
    $events        = cdcf_review_table('events');
    $notifications = cdcf_review_table('notifications');

    return [
        "CREATE TABLE {$entries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  author_user_id bigint(20) unsigned DEFAULT NULL,
  review_slot bigint(20) unsigned DEFAULT NULL,
  body_md longtext NOT NULL,
  vote varchar(20) DEFAULT NULL,
  created_at datetime(6) NOT NULL,
  edited_at datetime(6) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id),
  UNIQUE KEY one_review (project_id,review_slot)
) {$charset_collate};",
        "CREATE TABLE {$attachments} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  kind varchar(10) NOT NULL,
  mime varchar(50) NOT NULL,
  original_name varchar(255) NOT NULL,
  storage_key char(64) NOT NULL,
  size int(10) unsigned NOT NULL,
  created_at datetime(6) NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id)
) {$charset_collate};",
        "CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  type varchar(30) NOT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  entry_id bigint(20) unsigned DEFAULT NULL,
  data text,
  created_at datetime(6) NOT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id)
) {$charset_collate};",
        "CREATE TABLE {$notifications} (
  event_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  read_at datetime(6) DEFAULT NULL,
  PRIMARY KEY  (event_id,user_id),
  KEY user_unread (user_id,read_at)
) {$charset_collate};",
    ];
}

/**
 * Create or upgrade the tables when the stored schema version differs,
 * then backfill the review stage onto submissions that predate this
 * feature. Hooked on admin_init and rest_api_init; the version option
 * is autoloaded, so the common path costs no query.
 */
function cdcf_review_maybe_upgrade(): void {
    if (get_option('cdcf_review_db_version') === CDCF_REVIEW_DB_VERSION) {
        return;
    }
    global $wpdb;
    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    foreach (cdcf_review_schema_sql($wpdb->get_charset_collate()) as $sql) {
        dbDelta($sql);
    }
    cdcf_review_backfill_stage();
    update_option('cdcf_review_db_version', CDCF_REVIEW_DB_VERSION);
}

/**
 * Put every pending public submission that has no review stage yet into
 * `in_review`. No email is sent; the submitter gets a status link with
 * the first committee email (Phase 3).
 */
function cdcf_review_backfill_stage(): int {
    $ids = get_posts([
        'post_type'      => 'project',
        'post_status'    => 'pending',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            ['key' => '_submission_submitter_email', 'compare' => 'EXISTS'],
            ['key' => CDCF_REVIEW_STAGE_META, 'compare' => 'NOT EXISTS'],
        ],
    ]);
    foreach ($ids as $id) {
        update_post_meta((int) $id, CDCF_REVIEW_STAGE_META, 'in_review');
    }
    return count($ids);
}

function cdcf_review_now(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ReviewSchemaTest`
Expected: PASS (8 tests).

- [ ] **Step 7: Run the whole suite**

Run: `composer test`
Expected: all tests pass (the bootstrap change must not break existing suites).

- [ ] **Step 8: Commit**

```bash
git add includes/review/schema.php tests/FakeWpdb.php tests/bootstrap.php tests/ReviewSchemaTest.php
git commit -m "feat(review): storage schema, upgrade routine and stage backfill

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Membership, permission check, cache invalidation, roster

**Files:**

- Create: `includes/review/membership.php`
- Modify: `tests/bootstrap.php` (require the new file after `schema.php`)
- Test: `tests/ReviewMembershipTest.php`

**Interfaces:**

- Consumes (existing, `includes/handlers/my-team-member.php`):
  `cdcf_my_team_member_resolve_link(int $user_id): int`,
  `cdcf_my_team_member_collect_group(int $team_member_id): array<string,int>`,
  `cdcf_my_team_member_get_english_about_page_id(): int`
- Produces:
  - `CDCF_REVIEW_COUNCIL_FIELDS = ['technical' => 'technical_council', 'ecclesial' => 'ecclesial_council', 'board' => 'team_members']`
  - `cdcf_review_council_ids(): array{technical: list<int>, ecclesial: list<int>, board: list<int>}`
  - `cdcf_review_membership(int $user_id): array{technical: bool, ecclesial: bool, board: bool}`
  - `cdcf_review_can(int $user_id, string $permission): bool` — `view` · `discuss` · `vote` · `decide`
  - `cdcf_review_member_user_ids(string $permission = 'view'): list<int>`
  - `cdcf_review_bump_membership_generation(): void`
  - hook callbacks `cdcf_review_flush_on_about_save($post_id): void`,
    `cdcf_review_flush_on_user_meta($meta_id, $user_id, $meta_key): void`
  - `cdcf_review_roster(): list<array{council: string, team_member_id: int, name: string, user_id: int}>`

Membership checks the **English** About page, which is where the existing
Board check (`cdcf_my_team_member_is_board_member`) reads council fields; it
matches any id in the caller's Polylang group, so a link set on a translated
bio still counts.

- [ ] **Step 1: Write the failing tests**

`tests/ReviewMembershipTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/membership.php.
 *
 * Fixture: English About page 5 lists team_member 700 on the technical
 * council, 701 on the ecclesial council and 702 on the Board. User 7 is
 * linked to 710, the Italian sibling of 700. User 8 is linked to 702.
 * User 9 is linked to 799, which is on no council.
 */
final class ReviewMembershipTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        Functions\when('get_option')->alias(fn(string $k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('cdcf_my_team_member_get_english_about_page_id')->justReturn(5);
        Functions\when('get_field')->alias(static function (string $field, $post_id) {
            return match ($field) {
                'technical_council' => ['700'],
                'ecclesial_council' => ['701'],
                'team_members'      => ['702'],
                default             => null,
            };
        });
        Functions\when('cdcf_my_team_member_resolve_link')->alias(
            static fn(int $uid): int => [7 => 710, 8 => 702, 9 => 799][$uid] ?? 0
        );
        Functions\when('cdcf_my_team_member_collect_group')->alias(
            static fn(int $tm): array => match ($tm) {
                710, 700 => ['en' => 700, 'it' => 710],
                702      => ['en' => 702],
                default  => ['en' => $tm],
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_council_ids_read_from_english_about_page(): void
    {
        $this->assertSame(
            ['technical' => [700], 'ecclesial' => [701], 'board' => [702]],
            cdcf_review_council_ids()
        );
    }

    public function test_council_ids_empty_without_about_page(): void
    {
        Functions\when('cdcf_my_team_member_get_english_about_page_id')->justReturn(0);

        $this->assertSame(
            ['technical' => [], 'ecclesial' => [], 'board' => []],
            cdcf_review_council_ids()
        );
    }

    public function test_link_to_translated_bio_counts_for_its_council(): void
    {
        $this->assertSame(
            ['technical' => true, 'ecclesial' => false, 'board' => false],
            cdcf_review_membership(7)
        );
    }

    public function test_unlinked_user_and_non_member_get_no_flags(): void
    {
        $none = ['technical' => false, 'ecclesial' => false, 'board' => false];
        $this->assertSame($none, cdcf_review_membership(42));
        $this->assertSame($none, cdcf_review_membership(9));
        $this->assertSame($none, cdcf_review_membership(0));
    }

    public function test_cached_membership_skips_lookup(): void
    {
        Functions\when('get_transient')->justReturn(['technical' => false, 'ecclesial' => true, 'board' => false]);
        Functions\expect('cdcf_my_team_member_resolve_link')->never();

        $this->assertTrue(cdcf_review_membership(7)['ecclesial']);
    }

    public function test_cache_key_includes_generation(): void
    {
        $keys = [];
        Functions\when('set_transient')->alias(function (string $k) use (&$keys) {
            $keys[] = $k;
            return true;
        });

        cdcf_review_membership(7);
        cdcf_review_bump_membership_generation();
        cdcf_review_membership(7);

        $this->assertSame(['cdcf_review_membership_0_7', 'cdcf_review_membership_1_7'], $keys);
    }

    public function test_permission_matrix(): void
    {
        // 7 technical, 8 board, 9 nobody.
        $this->assertTrue(cdcf_review_can(7, 'view'));
        $this->assertTrue(cdcf_review_can(7, 'discuss'));
        $this->assertTrue(cdcf_review_can(7, 'vote'));
        $this->assertFalse(cdcf_review_can(7, 'decide'));

        $this->assertTrue(cdcf_review_can(8, 'view'));
        $this->assertFalse(cdcf_review_can(8, 'vote'));
        $this->assertTrue(cdcf_review_can(8, 'decide'));

        $this->assertFalse(cdcf_review_can(9, 'view'));
        $this->assertFalse(cdcf_review_can(7, 'unknown'));
    }

    public function test_member_user_ids_filters_by_permission(): void
    {
        Functions\when('get_users')->justReturn(['7', '8', '9']);

        $this->assertSame([7, 8], cdcf_review_member_user_ids());
        $this->assertSame([7], cdcf_review_member_user_ids('vote'));
        $this->assertSame([8], cdcf_review_member_user_ids('decide'));
    }

    public function test_saving_the_about_page_bumps_generation(): void
    {
        Functions\when('get_page_template_slug')->justReturn('templates/about.php');

        cdcf_review_flush_on_about_save(5);

        $this->assertSame(1, $this->options['cdcf_review_membership_gen']);
    }

    public function test_saving_other_pages_or_acf_non_posts_does_not_bump(): void
    {
        Functions\when('get_page_template_slug')->justReturn('templates/home.php');

        cdcf_review_flush_on_about_save(6);
        cdcf_review_flush_on_about_save('user_7');
        cdcf_review_flush_on_about_save('options');

        $this->assertArrayNotHasKey('cdcf_review_membership_gen', $this->options);
    }

    public function test_author_team_member_meta_write_bumps_generation(): void
    {
        cdcf_review_flush_on_user_meta(1, 7, 'author_team_member');
        cdcf_review_flush_on_user_meta(2, 7, 'nickname');

        $this->assertSame(1, $this->options['cdcf_review_membership_gen']);
    }

    public function test_roster_marks_linked_and_unlinked_members(): void
    {
        Functions\when('get_users')->justReturn(['7', '8']);
        Functions\when('get_the_title')->alias(static fn(int $id): string => "Member {$id}");

        $this->assertSame(
            [
                ['council' => 'technical', 'team_member_id' => 700, 'name' => 'Member 700', 'user_id' => 7],
                ['council' => 'ecclesial', 'team_member_id' => 701, 'name' => 'Member 701', 'user_id' => 0],
                ['council' => 'board', 'team_member_id' => 702, 'name' => 'Member 702', 'user_id' => 8],
            ],
            cdcf_review_roster()
        );
    }
}
```

- [ ] **Step 2: Add the require and run tests to verify they fail**

In `tests/bootstrap.php`, after the `schema.php` require add:

```php
require_once __DIR__ . '/../includes/review/membership.php';
```

Run: `vendor/bin/phpunit --filter ReviewMembershipTest`
Expected: FAIL — `Failed to open stream` for `membership.php`.

- [ ] **Step 3: Implement `includes/review/membership.php`**

```php
<?php
/**
 * Council review — who may review.
 *
 * Membership is derived, not granted: a user is on a council when their
 * `author_team_member` bio (any language) is listed in the English About
 * page's council relationship field. Adding someone to a council on the
 * About page grants access; removing them revokes it.
 *
 * Results are cached per user in a transient whose key embeds a
 * generation counter. Saving an About page or changing any user's
 * `author_team_member` link bumps the counter, so roster changes apply
 * immediately; the one-hour TTL only bounds stale-cache garbage.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_COUNCIL_FIELDS = [
    'technical' => 'technical_council',
    'ecclesial' => 'ecclesial_council',
    'board'     => 'team_members',
];

/**
 * @return array{technical: list<int>, ecclesial: list<int>, board: list<int>}
 */
function cdcf_review_council_ids(): array {
    $out = ['technical' => [], 'ecclesial' => [], 'board' => []];
    $about_id = cdcf_my_team_member_get_english_about_page_id();
    if ($about_id <= 0 || !function_exists('get_field')) {
        return $out;
    }
    foreach (CDCF_REVIEW_COUNCIL_FIELDS as $council => $field) {
        $ids = get_field($field, $about_id, false);
        $out[$council] = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }
    return $out;
}

function cdcf_review_membership_cache_key(int $user_id): string {
    return 'cdcf_review_membership_' . (int) get_option('cdcf_review_membership_gen', 0) . '_' . $user_id;
}

/**
 * @return array{technical: bool, ecclesial: bool, board: bool}
 */
function cdcf_review_membership(int $user_id): array {
    $flags = ['technical' => false, 'ecclesial' => false, 'board' => false];
    if ($user_id <= 0) {
        return $flags;
    }
    $key    = cdcf_review_membership_cache_key($user_id);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    $link = cdcf_my_team_member_resolve_link($user_id);
    if ($link > 0) {
        $group      = cdcf_my_team_member_collect_group($link);
        $candidates = $group ? array_values($group) : [$link];
        foreach (cdcf_review_council_ids() as $council => $ids) {
            $flags[$council] = (bool) array_intersect($candidates, $ids);
        }
    }
    set_transient($key, $flags, HOUR_IN_SECONDS);
    return $flags;
}

function cdcf_review_can(int $user_id, string $permission): bool {
    $m = cdcf_review_membership($user_id);
    return match ($permission) {
        'view', 'discuss' => $m['technical'] || $m['ecclesial'] || $m['board'],
        'vote'            => $m['technical'] || $m['ecclesial'],
        'decide'          => $m['board'],
        default           => false,
    };
}

/**
 * Every WP user holding $permission. Only users with an
 * `author_team_member` link can be members, so that meta key bounds the
 * scan to a handful of accounts.
 *
 * @return list<int>
 */
function cdcf_review_member_user_ids(string $permission = 'view'): array {
    $out = [];
    foreach (get_users(['meta_key' => 'author_team_member', 'fields' => 'ID']) as $uid) {
        $uid = (int) $uid;
        if (cdcf_review_can($uid, $permission)) {
            $out[] = $uid;
        }
    }
    return $out;
}

function cdcf_review_bump_membership_generation(): void {
    update_option(
        'cdcf_review_membership_gen',
        (int) get_option('cdcf_review_membership_gen', 0) + 1,
        false
    );
}

/** acf/save_post callback. ACF also passes 'user_5', 'options', etc. */
function cdcf_review_flush_on_about_save($post_id): void {
    if (!is_int($post_id) && !ctype_digit((string) $post_id)) {
        return;
    }
    if (get_page_template_slug((int) $post_id) === 'templates/about.php') {
        cdcf_review_bump_membership_generation();
    }
}

/** added_/updated_/deleted_user_meta callback. */
function cdcf_review_flush_on_user_meta($meta_id, $user_id, $meta_key): void {
    if ($meta_key === 'author_team_member') {
        cdcf_review_bump_membership_generation();
    }
}

/**
 * Every council and Board seat with the WP user linked to it (0 when
 * none), for the Review access admin screen.
 *
 * @return list<array{council: string, team_member_id: int, name: string, user_id: int}>
 */
function cdcf_review_roster(): array {
    $user_by_team_member = [];
    foreach (get_users(['meta_key' => 'author_team_member', 'fields' => 'ID']) as $uid) {
        $uid  = (int) $uid;
        $link = cdcf_my_team_member_resolve_link($uid);
        if ($link <= 0) {
            continue;
        }
        foreach (cdcf_my_team_member_collect_group($link) ?: [$link] as $team_member_id) {
            $user_by_team_member[(int) $team_member_id] = $uid;
        }
    }
    $rows = [];
    foreach (cdcf_review_council_ids() as $council => $ids) {
        foreach ($ids as $team_member_id) {
            $rows[] = [
                'council'        => $council,
                'team_member_id' => $team_member_id,
                'name'           => get_the_title($team_member_id),
                'user_id'        => $user_by_team_member[$team_member_id] ?? 0,
            ];
        }
    }
    return $rows;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ReviewMembershipTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/review/membership.php tests/bootstrap.php tests/ReviewMembershipTest.php
git commit -m "feat(review): derive council membership from the About page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Entry repository and events

**Files:**

- Create: `includes/review/repository.php`
- Create: `includes/review/events.php`
- Modify: `tests/bootstrap.php` (require both after `membership.php`)
- Test: `tests/ReviewRepositoryTest.php`, `tests/ReviewEventsTest.php`

**Interfaces:**

- Consumes: `cdcf_review_table()`, `cdcf_review_now()` (Task 1),
  `cdcf_review_member_user_ids()` (Task 2)
- Produces (repository):
  - `cdcf_review_insert_entry(array $row): int` — keys `project_id`, `kind`, `body_md`, optional `parent_id`, `author_user_id`, `vote`; returns new id or 0
  - `cdcf_review_get_entry(int $id): ?array`
  - `cdcf_review_find_review(int $project_id, int $user_id): ?array`
  - `cdcf_review_project_entries(int $project_id): list<array>` — oldest first
  - `cdcf_review_update_entry(int $id, array $fields, string $expected_edited_at): bool` — only `body_md`/`vote`; `''` means "never edited"
  - `cdcf_review_empty_tally(): array{cdcf: int, community: int, reject: int}`
  - `cdcf_review_tallies(array $project_ids): array<int, array{cdcf: int, community: int, reject: int}>`
  - `cdcf_review_plurality(array $tally): ?string` — null on no votes or a tie
  - `cdcf_review_votes_by_user(int $user_id, array $project_ids): array<int, string>`
  - Entry rows are arrays with int `id`, `project_id`, `parent_id|null`, `author_user_id|null`, `review_slot|null` and string `kind`, `body_md`, `vote|null`, `created_at`, `edited_at|null`.
- Produces (events):
  - `cdcf_review_record_event(int $project_id, string $type, ?int $actor_user_id, ?int $entry_id = null, array $data = []): int`
    — fires action `cdcf_review_event_recorded` with `($event_id, $project_id, $type, $actor_user_id, $entry_id)` for Phase 5 emails
  - `cdcf_review_unread_counts(int $user_id, array $project_ids): array<int, int>`

- [ ] **Step 1: Write the failing repository tests**

`tests/ReviewRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/repository.php against the recording
 * FakeWpdb double.
 */
final class ReviewRepositoryTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '3', 'project_id' => '40', 'kind' => 'review', 'parent_id' => null,
            'author_user_id' => '7', 'review_slot' => '7', 'body_md' => 'Good fit.',
            'vote' => 'cdcf', 'created_at' => '2026-10-15 10:00:00.000000', 'edited_at' => null,
        ], $overrides);
    }

    public function test_insert_review_sets_review_slot_and_timestamp(): void
    {
        $id = cdcf_review_insert_entry([
            'project_id' => 40, 'kind' => 'review', 'author_user_id' => 7,
            'body_md' => 'Good fit.', 'vote' => 'cdcf',
        ]);

        $this->assertSame(1, $id);
        $insert = $this->wpdb->inserts[0];
        $this->assertSame('wp_cdcf_review_entries', $insert['table']);
        $this->assertSame(7, $insert['data']['review_slot']);
        $this->assertNull($insert['data']['parent_id']);
        $this->assertSame('cdcf', $insert['data']['vote']);
        $this->assertMatchesRegularExpression('/\.\d{6}$/', $insert['data']['created_at']);
    }

    public function test_insert_non_review_has_null_slot_and_vote(): void
    {
        cdcf_review_insert_entry([
            'project_id' => 40, 'kind' => 'reply', 'parent_id' => 3,
            'author_user_id' => 8, 'body_md' => 'Agreed.', 'vote' => 'reject',
        ]);

        $data = $this->wpdb->inserts[0]['data'];
        $this->assertNull($data['review_slot']);
        $this->assertNull($data['vote']);
        $this->assertSame(3, $data['parent_id']);
    }

    public function test_insert_returns_zero_on_failure(): void
    {
        $this->wpdb->failInsert = true;

        $this->assertSame(0, cdcf_review_insert_entry([
            'project_id' => 40, 'kind' => 'review', 'author_user_id' => 7, 'body_md' => 'x', 'vote' => 'cdcf',
        ]));
    }

    public function test_get_entry_normalizes_ints(): void
    {
        $this->wpdb->results[] = $this->row();

        $entry = cdcf_review_get_entry(3);

        $this->assertSame(3, $entry['id']);
        $this->assertSame(40, $entry['project_id']);
        $this->assertSame(7, $entry['author_user_id']);
        $this->assertNull($entry['parent_id']);
        $this->assertStringContainsString('WHERE id = 3', $this->wpdb->queries[0]);
    }

    public function test_get_entry_returns_null_when_missing(): void
    {
        $this->assertNull(cdcf_review_get_entry(99));
    }

    public function test_find_review_queries_by_project_and_author(): void
    {
        $this->wpdb->results[] = $this->row();

        $this->assertSame(3, cdcf_review_find_review(40, 7)['id']);
        $this->assertStringContainsString("project_id = 40 AND kind = 'review' AND author_user_id = 7", $this->wpdb->queries[0]);
    }

    public function test_project_entries_are_ordered_oldest_first(): void
    {
        $this->wpdb->results[] = [$this->row(), $this->row(['id' => '4', 'kind' => 'reply', 'parent_id' => '3'])];

        $entries = cdcf_review_project_entries(40);

        $this->assertCount(2, $entries);
        $this->assertSame(3, $entries[1]['parent_id']);
        $this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $this->wpdb->queries[0]);
    }

    public function test_update_never_edited_entry_matches_null_edited_at(): void
    {
        $this->wpdb->results[] = 1;

        $this->assertTrue(cdcf_review_update_entry(3, ['body_md' => 'New', 'vote' => 'reject'], ''));
        $sql = $this->wpdb->queries[0];
        $this->assertStringContainsString("body_md = 'New'", $sql);
        $this->assertStringContainsString("vote = 'reject'", $sql);
        $this->assertStringContainsString('WHERE id = 3 AND edited_at IS NULL', $sql);
    }

    public function test_update_with_stale_edited_at_returns_false(): void
    {
        $this->wpdb->results[] = 0;

        $this->assertFalse(cdcf_review_update_entry(3, ['body_md' => 'New'], '2026-10-15 10:00:00.000001'));
        $this->assertStringContainsString("edited_at = '2026-10-15 10:00:00.000001'", $this->wpdb->queries[0]);
    }

    public function test_update_ignores_columns_outside_allowlist(): void
    {
        $this->assertFalse(cdcf_review_update_entry(3, ['author_user_id' => 1], ''));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_tallies_group_votes_per_project(): void
    {
        $this->wpdb->results[] = [
            ['project_id' => '40', 'vote' => 'cdcf', 'n' => '2'],
            ['project_id' => '40', 'vote' => 'reject', 'n' => '1'],
            ['project_id' => '41', 'vote' => 'community', 'n' => '1'],
        ];

        $tallies = cdcf_review_tallies([40, 41]);

        $this->assertSame(['cdcf' => 2, 'community' => 0, 'reject' => 1], $tallies[40]);
        $this->assertSame(['cdcf' => 0, 'community' => 1, 'reject' => 0], $tallies[41]);
        $this->assertStringContainsString('project_id IN (40,41)', $this->wpdb->queries[0]);
    }

    public function test_tallies_of_no_projects_skip_the_query(): void
    {
        $this->assertSame([], cdcf_review_tallies([]));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_plurality(): void
    {
        $this->assertSame('cdcf', cdcf_review_plurality(['cdcf' => 2, 'community' => 1, 'reject' => 0]));
        $this->assertNull(cdcf_review_plurality(['cdcf' => 1, 'community' => 1, 'reject' => 0]));
        $this->assertNull(cdcf_review_plurality(cdcf_review_empty_tally()));
    }

    public function test_votes_by_user(): void
    {
        $this->wpdb->results[] = [['project_id' => '40', 'vote' => 'community']];

        $this->assertSame([40 => 'community'], cdcf_review_votes_by_user(7, [40, 41]));
        $this->assertStringContainsString('author_user_id = 7', $this->wpdb->queries[0]);
    }
}
```

- [ ] **Step 2: Write the failing events tests**

`tests/ReviewEventsTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/events.php — event log + notification fan-out.
 */
final class ReviewEventsTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('cdcf_review_member_user_ids')->justReturn([7, 8, 9]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_records_event_and_notifies_everyone_but_the_actor(): void
    {
        Actions\expectDone('cdcf_review_event_recorded')->once()->with(1, 40, 'reply_posted', 8, 5);

        $id = cdcf_review_record_event(40, 'reply_posted', 8, 5);

        $this->assertSame(1, $id);
        $event = $this->wpdb->inserts[0];
        $this->assertSame('wp_cdcf_review_events', $event['table']);
        $this->assertSame(40, $event['data']['project_id']);
        $this->assertSame('reply_posted', $event['data']['type']);
        $this->assertSame(8, $event['data']['actor_user_id']);
        $this->assertSame(5, $event['data']['entry_id']);
        $this->assertNull($event['data']['data']);

        $recipients = array_map(
            static fn(array $i) => $i['data']['user_id'],
            array_slice($this->wpdb->inserts, 1)
        );
        $this->assertSame([7, 9], $recipients);
    }

    public function test_submitter_events_notify_all_members_and_encode_data(): void
    {
        cdcf_review_record_event(40, 'vote_changed', null, null, ['from' => 'reject', 'to' => 'cdcf']);

        $this->assertSame('{"from":"reject","to":"cdcf"}', $this->wpdb->inserts[0]['data']['data']);
        $this->assertCount(4, $this->wpdb->inserts);
    }

    public function test_failed_event_insert_returns_zero_and_notifies_nobody(): void
    {
        $this->wpdb->failInsert = true;
        Actions\expectDone('cdcf_review_event_recorded')->never();

        $this->assertSame(0, cdcf_review_record_event(40, 'review_posted', 7, 3));
    }

    public function test_unread_counts_per_project(): void
    {
        $this->wpdb->results[] = [['project_id' => '40', 'n' => '3']];

        $this->assertSame([40 => 3], cdcf_review_unread_counts(7, [40, 41]));
        $sql = $this->wpdb->queries[0];
        $this->assertStringContainsString('n.user_id = 7', $sql);
        $this->assertStringContainsString('n.read_at IS NULL', $sql);
        $this->assertStringContainsString('e.project_id IN (40,41)', $sql);
    }

    public function test_unread_counts_of_no_projects_skip_the_query(): void
    {
        $this->assertSame([], cdcf_review_unread_counts(7, []));
        $this->assertSame([], $this->wpdb->queries);
    }
}
```

- [ ] **Step 3: Add the requires and run tests to verify they fail**

In `tests/bootstrap.php`, after the `membership.php` require add:

```php
require_once __DIR__ . '/../includes/review/repository.php';
require_once __DIR__ . '/../includes/review/events.php';
```

Run: `vendor/bin/phpunit --filter 'ReviewRepositoryTest|ReviewEventsTest'`
Expected: FAIL — `Failed to open stream` for `repository.php`.

- [ ] **Step 4: Implement `includes/review/repository.php`**

```php
<?php
/**
 * Council review — entry storage.
 *
 * Every SQL statement goes through $wpdb->prepare() except IN () lists,
 * which are built from absint()-ed ids.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_INT_COLUMNS = ['id', 'project_id', 'parent_id', 'author_user_id', 'review_slot'];

function cdcf_review_normalize_row(array $row): array {
    foreach (CDCF_REVIEW_INT_COLUMNS as $col) {
        if (array_key_exists($col, $row) && $row[$col] !== null) {
            $row[$col] = (int) $row[$col];
        }
    }
    return $row;
}

function cdcf_review_id_list(array $ids): string {
    return implode(',', array_map('absint', $ids));
}

function cdcf_review_insert_entry(array $row): int {
    global $wpdb;
    $is_review = $row['kind'] === 'review';
    $author    = isset($row['author_user_id']) ? (int) $row['author_user_id'] : null;
    $ok = $wpdb->insert(cdcf_review_table('entries'), [
        'project_id'     => (int) $row['project_id'],
        'kind'           => (string) $row['kind'],
        'parent_id'      => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
        'author_user_id' => $author,
        'review_slot'    => $is_review ? $author : null,
        'body_md'        => (string) $row['body_md'],
        'vote'           => $is_review ? (string) $row['vote'] : null,
        'created_at'     => cdcf_review_now(),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}

function cdcf_review_get_entry(int $id): ?array {
    global $wpdb;
    $table = cdcf_review_table('entries');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_row($row) : null;
}

function cdcf_review_find_review(int $project_id, int $user_id): ?array {
    global $wpdb;
    $table = cdcf_review_table('entries');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE project_id = %d AND kind = 'review' AND author_user_id = %d LIMIT 1",
        $project_id,
        $user_id
    ), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_row($row) : null;
}

/** @return list<array> */
function cdcf_review_project_entries(int $project_id): array {
    global $wpdb;
    $table = cdcf_review_table('entries');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE project_id = %d ORDER BY created_at ASC, id ASC",
        $project_id
    ), ARRAY_A);
    return array_map('cdcf_review_normalize_row', is_array($rows) ? $rows : []);
}

/**
 * Compare-and-set update. Succeeds only when the row's edited_at still
 * equals $expected_edited_at ('' = never edited), so two people editing
 * from stale copies can't silently overwrite each other.
 */
function cdcf_review_update_entry(int $id, array $fields, string $expected_edited_at): bool {
    global $wpdb;
    $fields = array_intersect_key($fields, array_flip(['body_md', 'vote']));
    if (!$fields) {
        return false;
    }
    $sets = [];
    $args = [];
    foreach ($fields as $col => $value) {
        $sets[] = "{$col} = %s";
        $args[] = (string) $value;
    }
    $sets[] = 'edited_at = %s';
    $args[] = cdcf_review_now();
    $args[] = $id;
    $table = cdcf_review_table('entries');
    $sql   = "UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id = %d AND ';
    if ($expected_edited_at === '') {
        $sql .= 'edited_at IS NULL';
    } else {
        $sql .= 'edited_at = %s';
        $args[] = $expected_edited_at;
    }
    return 1 === (int) $wpdb->query($wpdb->prepare($sql, $args));
}

/** @return array{cdcf: int, community: int, reject: int} */
function cdcf_review_empty_tally(): array {
    return array_fill_keys(CDCF_REVIEW_VOTES, 0);
}

/** @return array<int, array{cdcf: int, community: int, reject: int}> */
function cdcf_review_tallies(array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    global $wpdb;
    $table = cdcf_review_table('entries');
    $rows = $wpdb->get_results(
        "SELECT project_id, vote, COUNT(*) AS n FROM {$table} WHERE kind = 'review' AND project_id IN ("
        . cdcf_review_id_list($project_ids) . ') GROUP BY project_id, vote',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $pid = (int) $row['project_id'];
        $out[$pid] ??= cdcf_review_empty_tally();
        if (isset($out[$pid][$row['vote']])) {
            $out[$pid][$row['vote']] = (int) $row['n'];
        }
    }
    return $out;
}

/** The single most-voted option; null when there are no votes or a tie. */
function cdcf_review_plurality(array $tally): ?string {
    $max = max($tally);
    if ($max === 0) {
        return null;
    }
    $leaders = array_keys($tally, $max, true);
    return count($leaders) === 1 ? (string) $leaders[0] : null;
}

/** @return array<int, string> project id => the user's vote */
function cdcf_review_votes_by_user(int $user_id, array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    global $wpdb;
    $table = cdcf_review_table('entries');
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT project_id, vote FROM {$table} WHERE kind = 'review' AND author_user_id = %d", $user_id)
        . ' AND project_id IN (' . cdcf_review_id_list($project_ids) . ')',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['project_id']] = (string) $row['vote'];
    }
    return $out;
}
```

- [ ] **Step 5: Implement `includes/review/events.php`**

```php
<?php
/**
 * Council review — activity log and notification fan-out.
 *
 * Every event lands in cdcf_review_events and gets one
 * cdcf_review_notifications row per member except the actor (the bell,
 * Phase 5). The cdcf_review_event_recorded action lets the email layer
 * (Phase 5) react without this module knowing about email.
 */

defined('ABSPATH') || exit;

function cdcf_review_record_event(
    int $project_id,
    string $type,
    ?int $actor_user_id,
    ?int $entry_id = null,
    array $data = []
): int {
    global $wpdb;
    $ok = $wpdb->insert(cdcf_review_table('events'), [
        'project_id'    => $project_id,
        'type'          => $type,
        'actor_user_id' => $actor_user_id,
        'entry_id'      => $entry_id,
        'data'          => $data ? wp_json_encode($data) : null,
        'created_at'    => cdcf_review_now(),
    ]);
    if (!$ok) {
        return 0;
    }
    $event_id = (int) $wpdb->insert_id;
    foreach (cdcf_review_member_user_ids() as $user_id) {
        if ($user_id === $actor_user_id) {
            continue;
        }
        $wpdb->insert(cdcf_review_table('notifications'), [
            'event_id' => $event_id,
            'user_id'  => $user_id,
        ]);
    }
    do_action('cdcf_review_event_recorded', $event_id, $project_id, $type, $actor_user_id, $entry_id);
    return $event_id;
}

/** @return array<int, int> project id => unread notification count */
function cdcf_review_unread_counts(int $user_id, array $project_ids): array {
    if (!$project_ids) {
        return [];
    }
    global $wpdb;
    $notifications = cdcf_review_table('notifications');
    $events        = cdcf_review_table('events');
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT e.project_id, COUNT(*) AS n FROM {$notifications} n JOIN {$events} e ON e.id = n.event_id"
            . ' WHERE n.user_id = %d AND n.read_at IS NULL',
            $user_id
        )
        . ' AND e.project_id IN (' . cdcf_review_id_list($project_ids) . ') GROUP BY e.project_id',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['project_id']] = (int) $row['n'];
    }
    return $out;
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'ReviewRepositoryTest|ReviewEventsTest'`
Expected: PASS (19 tests).

- [ ] **Step 7: Commit**

```bash
git add includes/review/repository.php includes/review/events.php tests/bootstrap.php tests/ReviewRepositoryTest.php tests/ReviewEventsTest.php
git commit -m "feat(review): entry repository, tallies and event fan-out

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Read endpoints — permissions, `/review/me`, list, detail

**Files:**

- Create: `includes/handlers/review.php`
- Modify: `tests/bootstrap.php` (require after `events.php`)
- Test: `tests/ReviewReadHandlerTest.php`

**Interfaces:**

- Consumes: Tasks 1–3 functions.
- Produces:
  - permission callbacks `cdcf_rest_review_permission_signed_in`, `cdcf_rest_review_permission_view`,
    `cdcf_rest_review_permission_vote`, `cdcf_rest_review_permission_discuss` (all `(WP_REST_Request): true|WP_Error`)
  - `cdcf_review_check_permission(string $permission): true|WP_Error`
  - `cdcf_review_get_reviewable_project(int $project_id): object|WP_Error` (404 `review_not_found`)
  - `cdcf_review_assert_open(int $project_id): true|WP_Error` (403 `review_locked`)
  - `cdcf_review_present_entry(array $row): array` — keys `id, kind, parent_id, body_md, vote, author{id,name,councils}|null, created_at, edited_at, attachments[]`
  - `cdcf_review_submission_fields(int $project_id): array{url: string, category: string, repo_urls: list<string>, tags: list<string>}`
  - `cdcf_review_post_language(int $project_id): string`
  - handlers `cdcf_rest_review_me`, `cdcf_rest_review_list_projects`, `cdcf_rest_review_get_project`

- [ ] **Step 1: Write the failing tests**

`tests/ReviewReadHandlerTest.php`:

```php
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
```

- [ ] **Step 2: Add the require and run tests to verify they fail**

In `tests/bootstrap.php`, after the `events.php` require add:

```php
require_once __DIR__ . '/../includes/handlers/review.php';
```

Run: `vendor/bin/phpunit --filter ReviewReadHandlerTest`
Expected: FAIL — `Failed to open stream` for `handlers/review.php`.

- [ ] **Step 3: Implement the read half of `includes/handlers/review.php`**

```php
<?php
/**
 * REST handlers for council review of submitted projects.
 *
 *   GET   /cdcf/v1/review/me                      membership flags
 *   GET   /cdcf/v1/review/projects?stage=         list
 *   GET   /cdcf/v1/review/projects/{id}           one project's workspace
 *   PUT   /cdcf/v1/review/projects/{id}/my-review create/update own review + vote
 *   POST  /cdcf/v1/review/projects/{id}/entries   reply or discussion post
 *   PATCH /cdcf/v1/review/entries/{id}            edit own reply/discussion post
 *
 * Callers authenticate with a Zitadel bearer token (zitadel-bearer.php);
 * membership comes from includes/review/membership.php. Request fields
 * are sanitized by the args blocks in functions.php.
 */

defined('ABSPATH') || exit;

/** @return true|WP_Error */
function cdcf_review_check_permission(string $permission) {
    if (!is_user_logged_in()) {
        return new WP_Error('rest_not_logged_in', 'Authentication required.', ['status' => 401]);
    }
    if ($permission !== 'signed_in' && !cdcf_review_can(get_current_user_id(), $permission)) {
        return new WP_Error('rest_forbidden', 'Your account is not permitted to do this in project review.', ['status' => 403]);
    }
    return true;
}

function cdcf_rest_review_permission_signed_in(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('signed_in');
}

function cdcf_rest_review_permission_view(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('view');
}

function cdcf_rest_review_permission_vote(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('vote');
}

function cdcf_rest_review_permission_discuss(WP_REST_Request $request) {
    unset($request);
    return cdcf_review_check_permission('discuss');
}

/** @return object|WP_Error The post, or 404 when it is not under review. */
function cdcf_review_get_reviewable_project(int $project_id) {
    $post = $project_id > 0 ? get_post($project_id) : null;
    $ok = $post
        && in_array($post->post_type, ['project', 'community_project'], true)
        && $post->post_status !== 'trash'
        && in_array((string) get_post_meta($project_id, CDCF_REVIEW_STAGE_META, true), CDCF_REVIEW_STAGES, true);
    if (!$ok) {
        return new WP_Error('review_not_found', 'No project under review with that id.', ['status' => 404]);
    }
    return $post;
}

/** @return true|WP_Error */
function cdcf_review_assert_open(int $project_id) {
    if (get_post_meta($project_id, CDCF_REVIEW_STAGE_META, true) === 'decided') {
        return new WP_Error('review_locked', 'This review has been decided and can no longer be changed.', ['status' => 403]);
    }
    return true;
}

function cdcf_review_post_language(int $project_id): string {
    $lang = function_exists('pll_get_post_language') ? pll_get_post_language($project_id, 'slug') : '';
    return is_string($lang) && $lang !== '' ? $lang : 'en';
}

/** @return array{url: string, category: string, repo_urls: list<string>, tags: list<string>} */
function cdcf_review_submission_fields(int $project_id): array {
    $category = get_field('project_category', $project_id);
    if (is_array($category)) {
        $category = reset($category);
    }
    $repo_urls = json_decode((string) get_post_meta($project_id, '_submission_repo_urls', true), true);
    if (!is_array($repo_urls) || !$repo_urls) {
        $single    = (string) get_field('project_repo_url', $project_id);
        $repo_urls = $single !== '' ? [$single] : [];
    }
    $tags = wp_get_object_terms($project_id, 'project_tag', ['fields' => 'names']);
    return [
        'url'       => (string) get_field('project_url', $project_id),
        'category'  => is_string($category) ? $category : '',
        'repo_urls' => array_values(array_map('strval', $repo_urls)),
        'tags'      => is_array($tags) ? array_values($tags) : [],
    ];
}

function cdcf_review_present_entry(array $row): array {
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
        'attachments' => [],
    ];
}

function cdcf_rest_review_me(WP_REST_Request $request) {
    unset($request);
    $uid = get_current_user_id();
    $m   = cdcf_review_membership($uid);
    $can = [];
    foreach (['view', 'discuss', 'vote', 'decide'] as $permission) {
        $can[$permission] = cdcf_review_can($uid, $permission);
    }
    return rest_ensure_response([
        'member'   => $can['view'],
        'councils' => array_keys(array_filter($m)),
        'can'      => $can,
    ]);
}

function cdcf_rest_review_list_projects(WP_REST_Request $request) {
    $query = [
        'post_type'      => ['project', 'community_project'],
        'post_status'    => 'any',
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_key'       => CDCF_REVIEW_STAGE_META,
    ];
    $stage = (string) $request['stage'];
    if ($stage !== '') {
        $query['meta_value'] = $stage;
    }
    $posts = get_posts($query);
    $ids   = array_map(static fn($p): int => (int) $p->ID, $posts);
    $uid   = get_current_user_id();

    $tallies = cdcf_review_tallies($ids);
    $mine    = cdcf_review_votes_by_user($uid, $ids);
    $unread  = cdcf_review_unread_counts($uid, $ids);
    $voters  = count(cdcf_review_member_user_ids('vote'));

    $items = [];
    foreach ($posts as $post) {
        $pid   = (int) $post->ID;
        $tally = $tallies[$pid] ?? cdcf_review_empty_tally();
        $items[] = [
            'id'             => $pid,
            'title'          => (string) $post->post_title,
            'category'       => cdcf_review_submission_fields($pid)['category'],
            'language'       => cdcf_review_post_language($pid),
            'submitted_at'   => (string) $post->post_date_gmt,
            'stage'          => (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true),
            'tally'          => $tally,
            'voters_pending' => max(0, $voters - array_sum($tally)),
            'my_vote'        => $mine[$pid] ?? null,
            'unread'         => $unread[$pid] ?? 0,
        ];
    }
    return rest_ensure_response(['projects' => $items]);
}

function cdcf_rest_review_get_project(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $post = cdcf_review_get_reviewable_project($pid);
    if (is_wp_error($post)) {
        return $post;
    }
    $uid = get_current_user_id();

    $reviews = $questions = $discussion = $replies = $answers = [];
    foreach (cdcf_review_project_entries($pid) as $row) {
        $entry = cdcf_review_present_entry($row);
        switch ($row['kind']) {
            case 'review':
                $reviews[$entry['id']] = $entry + ['replies' => []];
                break;
            case 'question':
                $questions[$entry['id']] = $entry + ['answers' => []];
                break;
            case 'reply':
                $replies[] = $entry;
                break;
            case 'answer':
                $answers[] = $entry;
                break;
            case 'discussion':
                $discussion[] = $entry;
                break;
        }
    }
    foreach ($replies as $reply) {
        if (isset($reviews[$reply['parent_id']])) {
            $reviews[$reply['parent_id']]['replies'][] = $reply;
        }
    }
    foreach ($answers as $answer) {
        if (isset($questions[$answer['parent_id']])) {
            $questions[$answer['parent_id']]['answers'][] = $answer;
        }
    }

    $tally      = cdcf_review_tallies([$pid])[$pid] ?? cdcf_review_empty_tally();
    $can_decide = cdcf_review_can($uid, 'decide');
    $mine       = cdcf_review_find_review($pid, $uid);

    return rest_ensure_response([
        'id'             => $pid,
        'title'          => (string) $post->post_title,
        'content'        => (string) $post->post_content,
        'post_type'      => (string) $post->post_type,
        'post_status'    => (string) $post->post_status,
        'stage'          => (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true),
        'language'       => cdcf_review_post_language($pid),
        'submitted_at'   => (string) $post->post_date_gmt,
        'fields'         => cdcf_review_submission_fields($pid),
        'submitter'      => [
            'name'  => (string) get_post_meta($pid, '_submission_submitter_name', true),
            'email' => $can_decide ? (string) get_post_meta($pid, '_submission_submitter_email', true) : null,
        ],
        'tally'          => $tally,
        'plurality'      => cdcf_review_plurality($tally),
        'voters_pending' => max(0, count(cdcf_review_member_user_ids('vote')) - array_sum($tally)),
        'reviews'        => array_values($reviews),
        'discussion'     => $discussion,
        'questions'      => array_values($questions),
        'me'             => [
            'can_vote'   => cdcf_review_can($uid, 'vote'),
            'can_decide' => $can_decide,
            'review_id'  => $mine['id'] ?? null,
        ],
    ]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ReviewReadHandlerTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/handlers/review.php tests/bootstrap.php tests/ReviewReadHandlerTest.php
git commit -m "feat(review): read endpoints for council review

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Write endpoints — my review, replies and discussion, edits

**Files:**

- Modify: `includes/handlers/review.php` (append)
- Test: `tests/ReviewWriteHandlerTest.php`

**Interfaces:**

- Consumes: Task 4 helpers; repository and events from Task 3.
- Produces:
  - `cdcf_review_sanitize_markdown($value): string` — used as `sanitize_callback` for `body_md`
  - `cdcf_review_validate_body(string $body): true|WP_Error` (`empty_body`, `body_too_long`, both 400)
  - `cdcf_review_entry_response(int $id, int $status): WP_REST_Response|WP_Error`
  - handlers `cdcf_rest_review_put_my_review`, `cdcf_rest_review_post_entry`, `cdcf_rest_review_patch_entry`
  - events recorded: `review_posted`, `review_edited`, `vote_changed` (data `{from, to}`), `reply_posted`, `discussion_posted`

- [ ] **Step 1: Write the failing tests**

`tests/ReviewWriteHandlerTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the write side of includes/handlers/review.php.
 */
final class ReviewWriteHandlerTest extends TestCase
{
    /** @var list<array{0: int, 1: string, 2: ?int, 3: ?int, 4: array}> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->events = [];
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_post')->alias(static function (int $id) {
            $p = new stdClass();
            $p->ID = $id;
            $p->post_type = 'project';
            $p->post_status = 'pending';
            return $p;
        });
        Functions\when('get_post_meta')->justReturn('in_review');
        Functions\when('cdcf_review_membership')->justReturn(['technical' => true, 'ecclesial' => false, 'board' => false]);
        Functions\when('get_userdata')->justReturn(false);
        Functions\when('cdcf_review_record_event')->alias(
            function (int $pid, string $type, ?int $actor, ?int $entry = null, array $data = []): int {
                $this->events[] = [$pid, $type, $actor, $entry, $data];
                return 1;
            }
        );
        Functions\when('cdcf_review_get_entry')->alias(fn(int $id) => $this->entry(['id' => $id]));
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

    private function entry(array $o): array
    {
        return array_merge([
            'id' => 1, 'project_id' => 40, 'kind' => 'review', 'parent_id' => null,
            'author_user_id' => 7, 'review_slot' => 7, 'body_md' => 'Old body', 'vote' => 'reject',
            'created_at' => '2026-10-15 10:00:00.000000', 'edited_at' => null,
        ], $o);
    }

    // ─── body rules ──────────────────────────────────────────────────

    public function test_sanitize_markdown_strips_nul_and_invalid_utf8_and_trims(): void
    {
        Functions\when('wp_check_invalid_utf8')->alias(static fn(string $s): string => mb_check_encoding($s, 'UTF-8') ? $s : '');

        $this->assertSame("# Title\n\nText", cdcf_review_sanitize_markdown("  # Title\n\nT\0ext  "));
        $this->assertSame('', cdcf_review_sanitize_markdown("\xC3\x28"));
        $this->assertSame('', cdcf_review_sanitize_markdown(['not', 'a', 'string']));
    }

    public function test_whitespace_only_and_oversized_bodies_are_rejected(): void
    {
        $this->assertSame('empty_body', cdcf_review_validate_body('')->get_error_code());
        $this->assertSame('body_too_long', cdcf_review_validate_body(str_repeat('é', CDCF_REVIEW_BODY_MAX + 1))->get_error_code());
        $this->assertTrue(cdcf_review_validate_body(str_repeat('é', CDCF_REVIEW_BODY_MAX)));
    }

    // ─── PUT my-review ───────────────────────────────────────────────

    public function test_first_review_is_created_with_201_and_event(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn(null);
        $inserted = null;
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 5;
        });

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'Fits.']));

        $this->assertSame(201, $res->get_status());
        $this->assertSame(5, $res->get_data()['id']);
        $this->assertSame(
            ['project_id' => 40, 'kind' => 'review', 'author_user_id' => 7, 'body_md' => 'Fits.', 'vote' => 'cdcf'],
            $inserted
        );
        $this->assertSame([[40, 'review_posted', 7, 5, []]], $this->events);
    }

    public function test_duplicate_first_review_race_returns_409(): void
    {
        $calls = 0;
        Functions\when('cdcf_review_find_review')->alias(function () use (&$calls) {
            return $calls++ === 0 ? null : $this->entry([]);
        });
        Functions\when('cdcf_review_insert_entry')->justReturn(0);

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'Fits.']));

        $this->assertSame('review_exists', $res->get_error_code());
        $this->assertSame(409, $res->get_error_data()['status']);
        $this->assertSame([], $this->events);
    }

    public function test_insert_failure_without_race_returns_500(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn(null);
        Functions\when('cdcf_review_insert_entry')->justReturn(0);

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'Fits.']));

        $this->assertSame(500, $res->get_error_data()['status']);
    }

    public function test_changing_vote_and_body_records_both_events(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn($this->entry([]));
        $update = null;
        Functions\when('cdcf_review_update_entry')->alias(function (int $id, array $f, string $exp) use (&$update): bool {
            $update = [$id, $f, $exp];
            return true;
        });

        $res = cdcf_rest_review_put_my_review($this->request([
            'id' => 40, 'vote' => 'community', 'body_md' => 'New body', 'edited_at' => '',
        ]));

        $this->assertSame(200, $res->get_status());
        $this->assertSame([1, ['body_md' => 'New body', 'vote' => 'community'], ''], $update);
        $this->assertSame(
            [
                [40, 'vote_changed', 7, 1, ['from' => 'reject', 'to' => 'community']],
                [40, 'review_edited', 7, 1, []],
            ],
            $this->events
        );
    }

    public function test_stale_edited_at_returns_409_without_writing(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn($this->entry(['edited_at' => '2026-10-15 11:00:00.000000']));
        Functions\expect('cdcf_review_update_entry')->never();

        $res = cdcf_rest_review_put_my_review($this->request([
            'id' => 40, 'vote' => 'cdcf', 'body_md' => 'x', 'edited_at' => '',
        ]));

        $this->assertSame('stale_entry', $res->get_error_code());
        $this->assertSame(409, $res->get_error_data()['status']);
    }

    public function test_lost_compare_and_set_returns_409(): void
    {
        Functions\when('cdcf_review_find_review')->justReturn($this->entry([]));
        Functions\when('cdcf_review_update_entry')->justReturn(false);

        $res = cdcf_rest_review_put_my_review($this->request([
            'id' => 40, 'vote' => 'cdcf', 'body_md' => 'x', 'edited_at' => '',
        ]));

        $this->assertSame(409, $res->get_error_data()['status']);
        $this->assertSame([], $this->events);
    }

    public function test_review_writes_are_locked_after_decision(): void
    {
        Functions\when('get_post_meta')->justReturn('decided');
        Functions\expect('cdcf_review_insert_entry')->never();

        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => 'x']));

        $this->assertSame('review_locked', $res->get_error_code());
    }

    public function test_empty_review_body_is_400(): void
    {
        $res = cdcf_rest_review_put_my_review($this->request(['id' => 40, 'vote' => 'cdcf', 'body_md' => '']));

        $this->assertSame(400, $res->get_error_data()['status']);
    }

    // ─── POST entries ────────────────────────────────────────────────

    public function test_reply_to_a_review_is_created(): void
    {
        $inserted = null;
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 9;
        });

        $res = cdcf_rest_review_post_entry($this->request([
            'id' => 40, 'kind' => 'reply', 'parent_id' => 1, 'body_md' => 'Agreed.',
        ]));

        $this->assertSame(201, $res->get_status());
        $this->assertSame(1, $inserted['parent_id']);
        $this->assertSame([[40, 'reply_posted', 7, 9, []]], $this->events);
    }

    public function test_reply_to_non_review_or_other_project_is_invalid_parent(): void
    {
        Functions\expect('cdcf_review_insert_entry')->never();
        $cases = [
            'missing'      => null,
            'reply parent' => $this->entry(['kind' => 'reply']),
            'discussion'   => $this->entry(['kind' => 'discussion']),
            'other project' => $this->entry(['project_id' => 41]),
        ];
        foreach ($cases as $label => $parent) {
            Functions\when('cdcf_review_get_entry')->justReturn($parent);

            $res = cdcf_rest_review_post_entry($this->request([
                'id' => 40, 'kind' => 'reply', 'parent_id' => 1, 'body_md' => 'x',
            ]));

            $this->assertSame('invalid_parent', $res->get_error_code(), $label);
            $this->assertSame(400, $res->get_error_data()['status'], $label);
        }
    }

    public function test_discussion_post_ignores_parent_and_records_event(): void
    {
        $inserted = null;
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 10;
        });

        cdcf_rest_review_post_entry($this->request([
            'id' => 40, 'kind' => 'discussion', 'parent_id' => 1, 'body_md' => 'Next call?',
        ]));

        $this->assertNull($inserted['parent_id']);
        $this->assertSame([[40, 'discussion_posted', 7, 10, []]], $this->events);
    }

    // ─── PATCH entries/{id} ──────────────────────────────────────────

    public function test_author_can_edit_own_reply(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry(['id' => 9, 'kind' => 'reply', 'parent_id' => 1, 'vote' => null]));
        Functions\expect('cdcf_review_update_entry')->once()->with(9, ['body_md' => 'Edited'], '')->andReturn(true);

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 9, 'body_md' => 'Edited', 'edited_at' => '']));

        $this->assertSame(200, $res->get_status());
        $this->assertSame([], $this->events);
    }

    public function test_editing_someone_elses_entry_is_403(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry(['kind' => 'reply', 'author_user_id' => 8]));

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 9, 'body_md' => 'x', 'edited_at' => '']));

        $this->assertSame('not_author', $res->get_error_code());
        $this->assertSame(403, $res->get_error_data()['status']);
    }

    public function test_reviews_must_be_edited_through_my_review(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn($this->entry([]));

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 1, 'body_md' => 'x', 'edited_at' => '']));

        $this->assertSame('use_my_review', $res->get_error_code());
    }

    public function test_patch_unknown_entry_is_404(): void
    {
        Functions\when('cdcf_review_get_entry')->justReturn(null);

        $res = cdcf_rest_review_patch_entry($this->request(['id' => 77, 'body_md' => 'x', 'edited_at' => '']));

        $this->assertSame(404, $res->get_error_data()['status']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ReviewWriteHandlerTest`
Expected: FAIL — `Call to undefined function cdcf_review_sanitize_markdown()`.

- [ ] **Step 3: Append the write half to `includes/handlers/review.php`**

```php
/**
 * sanitize_callback for Markdown bodies. Markdown is stored verbatim and
 * rendered with raw HTML disabled (Phase 2), so sanitizing means only:
 * reject invalid UTF-8, drop NUL bytes, trim.
 */
function cdcf_review_sanitize_markdown($value): string {
    if (!is_string($value)) {
        return '';
    }
    return trim(str_replace("\0", '', wp_check_invalid_utf8($value)));
}

/** @return true|WP_Error */
function cdcf_review_validate_body(string $body) {
    if ($body === '') {
        return new WP_Error('empty_body', 'Write something before posting.', ['status' => 400]);
    }
    if (mb_strlen($body) > CDCF_REVIEW_BODY_MAX) {
        return new WP_Error(
            'body_too_long',
            sprintf('Text is limited to %d characters. Attach longer documents as a Markdown file.', CDCF_REVIEW_BODY_MAX),
            ['status' => 400]
        );
    }
    return true;
}

/** @return WP_REST_Response|WP_Error */
function cdcf_review_entry_response(int $id, int $status) {
    $entry = cdcf_review_get_entry($id);
    if ($entry === null) {
        return new WP_Error('entry_missing', 'The entry was saved but could not be read back.', ['status' => 500]);
    }
    return new WP_REST_Response(cdcf_review_present_entry($entry), $status);
}

/** @return true|WP_Error First error among the reviewable/open/body checks. */
function cdcf_review_guard_write(int $project_id, string $body) {
    foreach ([
        cdcf_review_get_reviewable_project($project_id),
        cdcf_review_assert_open($project_id),
        cdcf_review_validate_body($body),
    ] as $result) {
        if (is_wp_error($result)) {
            return $result;
        }
    }
    return true;
}

function cdcf_rest_review_put_my_review(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $body = (string) $request['body_md'];
    $ok   = cdcf_review_guard_write($pid, $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    $uid      = get_current_user_id();
    $vote     = (string) $request['vote'];
    $existing = cdcf_review_find_review($pid, $uid);

    if ($existing === null) {
        $id = cdcf_review_insert_entry([
            'project_id'     => $pid,
            'kind'           => 'review',
            'author_user_id' => $uid,
            'body_md'        => $body,
            'vote'           => $vote,
        ]);
        if ($id <= 0) {
            // A concurrent first save by the same reviewer won the
            // one_review unique key.
            if (cdcf_review_find_review($pid, $uid) !== null) {
                return new WP_Error('review_exists', 'Your review was already saved. Reload to edit it.', ['status' => 409]);
            }
            return new WP_Error('insert_failed', 'Could not save the review.', ['status' => 500]);
        }
        cdcf_review_record_event($pid, 'review_posted', $uid, $id);
        return cdcf_review_entry_response($id, 201);
    }

    $expected = (string) ($request['edited_at'] ?? '');
    $stale    = new WP_Error('stale_entry', 'This review changed since you opened it. Reload and try again.', ['status' => 409]);
    if ((string) ($existing['edited_at'] ?? '') !== $expected) {
        return $stale;
    }
    if (!cdcf_review_update_entry($existing['id'], ['body_md' => $body, 'vote' => $vote], $expected)) {
        return $stale;
    }
    if ($existing['vote'] !== $vote) {
        cdcf_review_record_event($pid, 'vote_changed', $uid, $existing['id'], ['from' => $existing['vote'], 'to' => $vote]);
    }
    if ($existing['body_md'] !== $body) {
        cdcf_review_record_event($pid, 'review_edited', $uid, $existing['id']);
    }
    return cdcf_review_entry_response($existing['id'], 200);
}

function cdcf_rest_review_post_entry(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $body = (string) $request['body_md'];
    $ok   = cdcf_review_guard_write($pid, $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    $kind      = (string) $request['kind'];
    $parent_id = null;
    if ($kind === 'reply') {
        $parent = cdcf_review_get_entry((int) $request['parent_id']);
        if ($parent === null || $parent['kind'] !== 'review' || $parent['project_id'] !== $pid) {
            return new WP_Error('invalid_parent', 'Replies must answer a review on this project.', ['status' => 400]);
        }
        $parent_id = $parent['id'];
    }
    $uid = get_current_user_id();
    $id  = cdcf_review_insert_entry([
        'project_id'     => $pid,
        'kind'           => $kind,
        'parent_id'      => $parent_id,
        'author_user_id' => $uid,
        'body_md'        => $body,
    ]);
    if ($id <= 0) {
        return new WP_Error('insert_failed', 'Could not save the post.', ['status' => 500]);
    }
    cdcf_review_record_event($pid, $kind === 'reply' ? 'reply_posted' : 'discussion_posted', $uid, $id);
    return cdcf_review_entry_response($id, 201);
}

function cdcf_rest_review_patch_entry(WP_REST_Request $request) {
    $entry = cdcf_review_get_entry((int) $request['id']);
    if ($entry === null) {
        return new WP_Error('entry_not_found', 'No such entry.', ['status' => 404]);
    }
    if ($entry['author_user_id'] !== get_current_user_id()) {
        return new WP_Error('not_author', 'You can only edit your own posts.', ['status' => 403]);
    }
    if (!in_array($entry['kind'], ['reply', 'discussion'], true)) {
        return new WP_Error('use_my_review', 'Edit your review through the my-review endpoint.', ['status' => 400]);
    }
    $body = (string) $request['body_md'];
    $ok   = cdcf_review_guard_write($entry['project_id'], $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    $expected = (string) ($request['edited_at'] ?? '');
    if ((string) ($entry['edited_at'] ?? '') !== $expected
        || !cdcf_review_update_entry($entry['id'], ['body_md' => $body], $expected)) {
        return new WP_Error('stale_entry', 'This post changed since you opened it. Reload and try again.', ['status' => 409]);
    }
    return cdcf_review_entry_response($entry['id'], 200);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'ReviewWriteHandlerTest|ReviewReadHandlerTest'`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add includes/handlers/review.php tests/ReviewWriteHandlerTest.php
git commit -m "feat(review): write endpoints for reviews, replies and discussion

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Wire into WordPress — routes, hooks, and new submissions

**Files:**

- Modify: `functions.php` (after the `my-team-member` route block, ~line 800)
- Modify: `includes/handlers/submit-project.php` (after the submitter meta, ~line 115)
- Modify: `tests/SubmissionHandlerTestBase.php` (`stubCommonFunctions`)
- Test: `tests/SubmitProjectHandlerTest.php`

**Interfaces:**

- Consumes: everything above.
- Produces: the live routes listed in the handler header; new submissions carry
  `_cdcf_review_stage = in_review` and a `submission_received` event.

- [ ] **Step 1: Write the failing submit-project test**

In `tests/SubmissionHandlerTestBase.php`, inside `stubCommonFunctions()`, add:

```php
        Functions\when('cdcf_review_record_event')->justReturn(1);
```

In `tests/SubmitProjectHandlerTest.php`, add:

```php
    public function test_new_submission_enters_council_review(): void
    {
        $this->stubCommonFunctions();
        $meta = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value) use (&$meta): bool {
            $meta[$key] = $value;
            return true;
        });
        $events = [];
        Functions\when('cdcf_review_record_event')->alias(function (int $pid, string $type, ?int $actor) use (&$events): int {
            $events[] = [$pid, $type, $actor];
            return 1;
        });
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest());

        $this->assertSame('in_review', $meta['_cdcf_review_stage']);
        $this->assertSame([[800, 'submission_received', null]], $events);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_new_submission_enters_council_review`
Expected: FAIL — `Undefined array key "_cdcf_review_stage"`.

- [ ] **Step 3: Update `includes/handlers/submit-project.php`**

Directly after the two `update_post_meta($post_id, '_submission_submitter_…')` lines add:

```php
    // Hand the submission to the advisory councils (see
    // includes/review/). The admin notification below stays as-is.
    update_post_meta($post_id, CDCF_REVIEW_STAGE_META, 'in_review');
    cdcf_review_record_event((int) $post_id, 'submission_received', null);
```

- [ ] **Step 4: Run the submission suites**

Run: `vendor/bin/phpunit --filter 'SubmitProject|ReferCommunityProject|ReferLocalGroup'`
Expected: PASS.

- [ ] **Step 5: Register modules, hooks and routes in `functions.php`**

Immediately after the closing `});` of the `my-team-member` `rest_api_init` block, add:

```php
// Council review of submitted projects — storage, membership, endpoints.
// See docs/superpowers/specs/2026-10-15-project-review-design.md.
require_once __DIR__ . '/includes/review/schema.php';
require_once __DIR__ . '/includes/review/membership.php';
require_once __DIR__ . '/includes/review/repository.php';
require_once __DIR__ . '/includes/review/events.php';
require_once __DIR__ . '/includes/handlers/review.php';
require_once __DIR__ . '/includes/admin/review-access.php';

add_action('admin_init', 'cdcf_review_maybe_upgrade');
add_action('rest_api_init', 'cdcf_review_maybe_upgrade', 5);
add_action('acf/save_post', 'cdcf_review_flush_on_about_save', 20);
add_action('added_user_meta', 'cdcf_review_flush_on_user_meta', 10, 3);
add_action('updated_user_meta', 'cdcf_review_flush_on_user_meta', 10, 3);
add_action('deleted_user_meta', 'cdcf_review_flush_on_user_meta', 10, 3);
add_action('admin_menu', 'cdcf_review_access_admin_menu', 20);

add_action('rest_api_init', function () {
    $id_arg = [
        'required'          => true,
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
    ];
    $body_arg = [
        'required'          => true,
        'type'              => 'string',
        'sanitize_callback' => 'cdcf_review_sanitize_markdown',
    ];
    $edited_at_arg = [
        'required'          => false,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ];

    register_rest_route('cdcf/v1', '/review/me', [
        'methods'             => 'GET',
        'callback'            => 'cdcf_rest_review_me',
        'permission_callback' => 'cdcf_rest_review_permission_signed_in',
    ]);
    register_rest_route('cdcf/v1', '/review/projects', [
        'methods'             => 'GET',
        'callback'            => 'cdcf_rest_review_list_projects',
        'permission_callback' => 'cdcf_rest_review_permission_view',
        'args'                => [
            'stage' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
                'enum'              => array_merge([''], CDCF_REVIEW_STAGES),
                'default'           => '',
            ],
        ],
    ]);
    register_rest_route('cdcf/v1', '/review/projects/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'cdcf_rest_review_get_project',
        'permission_callback' => 'cdcf_rest_review_permission_view',
        'args'                => ['id' => $id_arg],
    ]);
    register_rest_route('cdcf/v1', '/review/projects/(?P<id>\d+)/my-review', [
        'methods'             => 'PUT',
        'callback'            => 'cdcf_rest_review_put_my_review',
        'permission_callback' => 'cdcf_rest_review_permission_vote',
        'args'                => [
            'id'        => $id_arg,
            'vote'      => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
                'enum'              => CDCF_REVIEW_VOTES,
            ],
            'body_md'   => $body_arg,
            'edited_at' => $edited_at_arg,
        ],
    ]);
    register_rest_route('cdcf/v1', '/review/projects/(?P<id>\d+)/entries', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_review_post_entry',
        'permission_callback' => 'cdcf_rest_review_permission_discuss',
        'args'                => [
            'id'        => $id_arg,
            'kind'      => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
                'enum'              => ['reply', 'discussion'],
            ],
            'parent_id' => [
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ],
            'body_md'   => $body_arg,
        ],
    ]);
    register_rest_route('cdcf/v1', '/review/entries/(?P<id>\d+)', [
        'methods'             => 'PATCH',
        'callback'            => 'cdcf_rest_review_patch_entry',
        'permission_callback' => 'cdcf_rest_review_permission_discuss',
        'args'                => [
            'id'        => $id_arg,
            'body_md'   => $body_arg,
            'edited_at' => $edited_at_arg,
        ],
    ]);
});
```

(`includes/admin/review-access.php` is created in Task 7; do Task 7 before
loading the site, or temporarily comment out that `require_once` and the
`admin_menu` line if you need to smoke-test between tasks.)

- [ ] **Step 6: Lint the PHP**

Run: `php -l functions.php && php -l includes/handlers/submit-project.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 7: Commit**

```bash
git add functions.php includes/handlers/submit-project.php tests/SubmissionHandlerTestBase.php tests/SubmitProjectHandlerTest.php
git commit -m "feat(review): register review routes and route new submissions into review

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Projects → Review access admin screen

**Files:**

- Create: `includes/admin/review-access.php`
- Modify: `tests/bootstrap.php` (require after `handlers/review.php`)
- Test: `tests/ReviewAccessScreenTest.php`

**Interfaces:**

- Consumes: `cdcf_review_roster()` (Task 2)
- Produces: `cdcf_review_access_admin_menu(): void`, `cdcf_review_access_render(): void`

- [ ] **Step 1: Write the failing tests**

`tests/ReviewAccessScreenTest.php`:

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/admin/review-access.php.
 */
final class ReviewAccessScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('get_edit_user_link')->alias(static fn(int $id): string => "user-edit.php?user_id={$id}");
        Functions\when('get_userdata')->alias(static function (int $id) {
            $u = new WP_User($id);
            $u->display_name = 'Fr. A';
            $u->user_email = 'a@example.org';
            return $u;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_menu_is_registered_under_projects_for_admins(): void
    {
        Functions\expect('add_submenu_page')->once()->with(
            'cdcf-projects', 'Review access', 'Review access', 'manage_options',
            'cdcf-review-access', 'cdcf_review_access_render'
        );

        cdcf_review_access_admin_menu();

        $this->addToAssertionCount(1);
    }

    public function test_render_lists_linked_and_unlinked_members(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('cdcf_review_roster')->justReturn([
            ['council' => 'technical', 'team_member_id' => 700, 'name' => 'Dr. B', 'user_id' => 0],
            ['council' => 'board', 'team_member_id' => 702, 'name' => 'Fr. A', 'user_id' => 8],
        ]);

        ob_start();
        cdcf_review_access_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Technical Council', $html);
        $this->assertStringContainsString('Dr. B', $html);
        $this->assertStringContainsString('Not linked', $html);
        $this->assertStringContainsString('Board of Directors', $html);
        $this->assertStringContainsString('user-edit.php?user_id=8', $html);
        $this->assertStringContainsString('a@example.org', $html);
    }

    public function test_render_explains_an_empty_roster(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('cdcf_review_roster')->justReturn([]);

        ob_start();
        cdcf_review_access_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('No council members are listed on the English About page.', $html);
    }

    public function test_render_outputs_nothing_for_non_admins(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('cdcf_review_roster')->never();

        ob_start();
        cdcf_review_access_render();

        $this->assertSame('', ob_get_clean());
    }
}
```

- [ ] **Step 2: Add the require and run tests to verify they fail**

In `tests/bootstrap.php`, after the `handlers/review.php` require add:

```php
require_once __DIR__ . '/../includes/admin/review-access.php';
```

Run: `vendor/bin/phpunit --filter ReviewAccessScreenTest`
Expected: FAIL — `Failed to open stream` for `review-access.php`.

- [ ] **Step 3: Implement `includes/admin/review-access.php`**

```php
<?php
/**
 * Projects → Review access: every council and Board seat from the
 * English About page with the WP user linked to it. A member without a
 * linked user cannot sign in to /review and receives no notifications;
 * link them via /cdcf/v1/author-team-member or the user-edit screen.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_COUNCIL_LABELS = [
    'technical' => 'Technical Council',
    'ecclesial' => 'Ecclesial Council',
    'board'     => 'Board of Directors',
];

function cdcf_review_access_admin_menu(): void {
    add_submenu_page(
        'cdcf-projects',
        'Review access',
        'Review access',
        'manage_options',
        'cdcf-review-access',
        'cdcf_review_access_render'
    );
}

function cdcf_review_access_render(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $rows = cdcf_review_roster();
    echo '<div class="wrap"><h1>' . esc_html('Review access') . '</h1>';
    echo '<p>' . esc_html('Council and Board members review submitted projects at /review. Each one needs a WordPress user linked to their bio card.') . '</p>';
    if (!$rows) {
        echo '<p>' . esc_html('No council members are listed on the English About page.') . '</p></div>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html('Council') . '</th><th>'
        . esc_html('Member') . '</th><th>' . esc_html('Linked user') . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $user = $row['user_id'] > 0 ? get_userdata($row['user_id']) : false;
        $linked = $user
            ? '<a href="' . esc_url(get_edit_user_link($row['user_id'])) . '">' . esc_html($user->display_name)
                . '</a> &lt;' . esc_html($user->user_email) . '&gt;'
            : '<strong>' . esc_html('Not linked') . '</strong>';
        echo '<tr><td>' . esc_html(CDCF_REVIEW_COUNCIL_LABELS[$row['council']] ?? $row['council']) . '</td><td>'
            . esc_html($row['name']) . '</td><td>' . $linked . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
```

- [ ] **Step 4: Run tests and the whole suite**

Run: `vendor/bin/phpunit --filter ReviewAccessScreenTest && composer test`
Expected: PASS, then the full suite passes with no warnings or notices
(`failOnWarning`/`failOnNotice` are on).

- [ ] **Step 5: Coverage check**

Run: `php8.3 -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --only-summary-for-coverage-text 2>/dev/null | tail -5`
(If pcov is not installed for 8.3, skip; CI reports coverage to Codecov.)
Expected: no drop versus `main`; the new `includes/review/*` files near 100% lines.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/review-access.php tests/bootstrap.php tests/ReviewAccessScreenTest.php
git commit -m "feat(review): Review access admin screen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Documentation, local smoke test, PR

**Files:**

- Modify: `AGENTS.md` (repo root; `CLAUDE.md`/`GEMINI.md` are symlinks)
- Modify: `docs/superpowers/specs/2026-10-15-project-review-design.md`

- [ ] **Step 1: Document the endpoints in `AGENTS.md`**

Add these rows to the REST table under "REST API Endpoints (`cdcf/v1`)", after the
`/translation-status` row:

```markdown
| `GET` | `/review/me` | Caller's council/Board membership and review permissions (`member`, `councils[]`, `can{view,discuss,vote,decide}`). Any signed-in user. |
| `GET` | `/review/projects` | Submissions under council review, optional `stage` filter, with tally, caller's vote and unread count. Council/Board members only (see below). |
| `GET` | `/review/projects/{id}` | One submission's review workspace: fields, reviews with replies, discussion, submitter Q&A, tally, plurality. Submitter email only for Board members. |
| `PUT` | `/review/projects/{id}/my-review` | Create or update the caller's review (`vote`, `body_md`, `edited_at`). Technical/Ecclesial council members only. `409` on a stale `edited_at`. |
| `POST` | `/review/projects/{id}/entries` | Post a `reply` (to a review, `parent_id`) or a `discussion` entry (`body_md`). |
| `PATCH` | `/review/entries/{id}` | Edit the caller's own reply or discussion entry (`body_md`, `edited_at`). |
```

Then add this section after the `GET /translation-status` section:

```markdown
### Council review (`/review/*`)

Public project submissions enter council review (`_cdcf_review_stage = in_review`)
instead of waiting for an administrator. Reviews, votes and discussion are stored
in custom tables (`{prefix}cdcf_review_*`, created by `cdcf_review_maybe_upgrade()`),
never in comments or posts, so nothing exposes them publicly. Design:
`docs/superpowers/specs/2026-10-15-project-review-design.md`.

Access is derived, not granted: a signed-in user is a member when their
`author_team_member` bio (any language) is listed in the English About page's
`technical_council`, `ecclesial_council` or `team_members` (Board) field.
Technical and Ecclesial members vote; Board members decide; everyone discusses.
Administrators who are not members have no access. **Projects → Review access** in
wp-admin lists every seat and whether a WordPress user is linked to it.
```

- [ ] **Step 2: Record the two planning refinements in the spec**

In `docs/superpowers/specs/2026-10-15-project-review-design.md`:

1. In "Membership and permissions", replace step 3
   ("For each language's About page, read …") with:

   ```markdown
   3. Read `technical_council`, `ecclesial_council` and `team_members` (the
      Board) on the **English** About page — the canonical copy, as in the
      existing `cdcf_my_team_member_is_board_member()` check. A flag is true
      when any of the collected ids appears in the corresponding field.
   ```

2. In "Tables", replace the paragraph starting "A generated column
   `review_author`" with:

   ```markdown
   A plain column `review_slot` (equal to `author_user_id` on `review` rows,
   NULL otherwise) carries a unique key with `project_id`, enforcing one
   review per reviewer per project. `dbDelta()` does not handle generated
   columns, and MySQL permits many NULLs under a unique key.
   ```

   and add `review_slot` to the `cdcf_review_entries` column table after
   `author_user_id`.

- [ ] **Step 3: Format and lint Markdown** (from the repo root)

Run: `npm run format:md && npm run lint:md`
Expected: prettier rewrites table padding; markdownlint reports `0 issues`.

- [ ] **Step 4: Smoke-test on the local stack** (from the repo root)

```bash
docker compose up -d --build
```

Then:

1. Load any wp-admin page — the four `wp_cdcf_review_*` tables are created
   (check with `docker compose exec db mariadb -u root -p"$WP_DB_ROOT_PASSWORD" "$WP_DB_NAME" -e "SHOW TABLES LIKE '%cdcf_review%'"`)
   and **Projects → Review access** lists the About page councils.
2. Submit a project through the public form; in wp-admin confirm its
   `_cdcf_review_stage` custom field is `in_review`.
3. With the application password (admin, not a member):
   `scripts/.venv/bin/python scripts/cdcf_api.py rest-get cdcf/v1/review/projects`
   → `403 rest_forbidden` (administrators are not members).
4. `scripts/.venv/bin/python scripts/cdcf_api.py rest-get cdcf/v1/review/me`
   → `200` with `"member": false`.

Bearer-authenticated member flows are exercised end to end in Phase 2, when
the `/review` UI exists.

- [ ] **Step 5: Commit**

```bash
git add AGENTS.md docs/superpowers/specs/2026-10-15-project-review-design.md
git commit -m "docs(review): document council review endpoints and refine spec

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin HEAD
gh pr create --title "feat(review): council review backend (phase 1 of 5)" --body "$(cat <<'EOF'
## Summary
- Custom tables for council review (entries, attachments, events, notifications) with a versioned dbDelta upgrade and a one-time backfill of pending submissions into `in_review`.
- Council membership derived from the English About page via each user's `author_team_member` bio link, cached behind a generation counter that bumps on About saves and link changes.
- `cdcf/v1/review/*` endpoints: me, list, detail, my-review (vote + rationale), replies/discussion, edits with optimistic concurrency.
- New public submissions enter review and record `submission_received`.
- Projects → Review access admin screen.

Spec: `docs/superpowers/specs/2026-10-15-project-review-design.md`. Plan: `docs/superpowers/plans/2026-10-15-project-review-phase1.md`.

Deploy note: theme change — deploy with `-f environment=production`.

## Test plan
- [ ] `composer test --working-dir=wordpress/themes/cdcf-headless`
- [ ] Local stack: tables created, Review access screen lists councils, new submission gets `in_review`, non-member admin gets 403 from `/review/projects`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR URL printed.
