# PHPUnit + Brain Monkey Test Suite — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add PHPUnit + Brain Monkey coverage for the `cdcf/v1` REST endpoints in the `cdcf-redis-translations` plugin and the `relationship` endpoints in the `cdcf-headless` theme, so future refactors of those endpoints (especially the `/cdcf/v1/maintenance` and `/cdcf/v1/process-queue` handlers) have a regression net.

**Spec reference:** `docs/superpowers/specs/2026-05-02-test-suites-design.md` — Component 2.

**Issue:** #63. Related: #62 (maintenance flag), #66 (the endpoint this suite is the regression net for), #41 (the deploy-day FPM saga that motivated #63), #86 (Vitest pilot, established the per-suite conventions).

**Pilot lessons carried in from #86 (Vitest):**

- Scope coverage by motivation (bug history + non-trivial logic), not by line target. `api.ts` shipped at 64% with all motivated cases covered.
- Mock one abstraction layer down from the unit under test. For Vitest that was `./client`; for PHPUnit it's the WP function layer (Brain Monkey) and the `Redis` class (Mockery `overload:`).
- Non-blocking CI (`continue-on-error: true`) at job level is the right gate for the "land tests first" stage. Flipping to required is a separate follow-up issue once the suites are stable.

**Important project context:**

- Two separate PHP trees, each with its own composer.json + phpunit.xml.dist: `wordpress/plugins/cdcf-redis-translations/` and `wordpress/themes/cdcf-headless/`. They run independently.
- PHP version pinned to **8.3** (matches the plugin header's `Requires PHP: 8.3`).
- The plugin and theme currently register their REST routes via inline closures. Brain Monkey can't test closures, so this plan includes a small mechanical refactor: extract each closure body into a named function (`cdcf_handle_maintenance`, `cdcf_handle_process_queue`, `cdcf_handle_relationship_get`, `cdcf_handle_relationship_post`, plus the permission callbacks). The plugin/theme entry then references the named functions in `register_rest_route(...)`. This is the same refactor pattern that future-proofs the routes for any addition.

---

## File Structure

| File                                                                                 | Action | Responsibility                                                                                                                                                                                                   |
| ------------------------------------------------------------------------------------ | ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php`              | Modify | Replace closure callbacks with named-function references; `require_once` the new `includes/handlers.php`                                                                                                         |
| `wordpress/plugins/cdcf-redis-translations/includes/handlers.php`                    | Create | Houses `cdcf_handle_maintenance`, `cdcf_handle_process_queue`, their permission callbacks, plus `cdcf_enqueue_translation` if not already in `includes/functions.php` (already there per current tree — confirm) |
| `wordpress/plugins/cdcf-redis-translations/tests/bootstrap.php`                      | Create | Brain Monkey setup, autoload, require handler files                                                                                                                                                              |
| `wordpress/plugins/cdcf-redis-translations/tests/MaintenanceHandlerTest.php`         | Create | Tests for `cdcf_handle_maintenance` and its permission callback                                                                                                                                                  |
| `wordpress/plugins/cdcf-redis-translations/tests/ProcessQueueHandlerTest.php`        | Create | Tests for `cdcf_handle_process_queue`                                                                                                                                                                            |
| `wordpress/plugins/cdcf-redis-translations/tests/EnqueueTranslationFallbackTest.php` | Create | Tests for `cdcf_enqueue_translation` Redis-up vs Redis-down fallback path                                                                                                                                        |
| `wordpress/plugins/cdcf-redis-translations/composer.json`                            | Create | phpunit/phpunit ^10, brain/monkey ^2.6, mockery/mockery ^1.6                                                                                                                                                     |
| `wordpress/plugins/cdcf-redis-translations/phpunit.xml.dist`                         | Create | Discovers `tests/*Test.php`                                                                                                                                                                                      |
| `wordpress/plugins/cdcf-redis-translations/.gitignore`                               | Create | Ignore `vendor/`, `.phpunit.result.cache`                                                                                                                                                                        |
| `wordpress/themes/cdcf-headless/functions.php`                                       | Modify | Replace closure callbacks for the `/cdcf/v1/relationship` routes with named functions; `require_once` the new `includes/handlers/relationship.php`                                                               |
| `wordpress/themes/cdcf-headless/includes/handlers/relationship.php`                  | Create | Houses `cdcf_handle_relationship_get`, `cdcf_handle_relationship_post`, permission callback                                                                                                                      |
| `wordpress/themes/cdcf-headless/tests/bootstrap.php`                                 | Create | Brain Monkey setup                                                                                                                                                                                               |
| `wordpress/themes/cdcf-headless/tests/RelationshipHandlerTest.php`                   | Create | Tests for the relationship handler pair                                                                                                                                                                          |
| `wordpress/themes/cdcf-headless/composer.json`                                       | Create | Same deps as plugin tree                                                                                                                                                                                         |
| `wordpress/themes/cdcf-headless/phpunit.xml.dist`                                    | Create | Discovers `tests/*Test.php`                                                                                                                                                                                      |
| `wordpress/themes/cdcf-headless/.gitignore`                                          | Create | Ignore `vendor/`, `.phpunit.result.cache`                                                                                                                                                                        |
| `.github/workflows/test-wordpress.yml`                                               | Create | CI: matrix over the two trees, `continue-on-error: true`                                                                                                                                                         |
| `AGENTS.md`                                                                          | Modify | Add `composer test` invocations to "Build & Development Commands"                                                                                                                                                |

The two PHP trees are intentionally independent — no shared composer root, no shared vendor. Keeps the install footprint tiny and lets each tree be deployed without the test deps.

---

## Task 1: Create feature branch and worktree

**Files:** none

- [ ] **Step 1: Create branch**

```bash
git checkout main
git pull --ff-only
git checkout -b feat/phpunit-wp-suites
```

This PR is parallelizable with the bats one (#63 PR 3); if running both at once, use git worktrees rather than juggling branches in a single checkout:

```bash
git worktree add ../cdcf-website-phpunit feat/phpunit-wp-suites
```

- [ ] **Step 2: Verify clean starting state**

```bash
git status
```

Expect "nothing to commit, working tree clean".

---

## Task 2: Plugin — refactor closures into named handlers

**Files:**

- Create: `wordpress/plugins/cdcf-redis-translations/includes/handlers.php`
- Modify: `wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php`

- [ ] **Step 1: Read the current plugin entry**

```bash
cat wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php
```

Note the two existing `register_rest_route` blocks for `/cdcf/v1/maintenance` and `/cdcf/v1/process-queue`. Both use inline closure `callback` and `permission_callback`. Capture the closure bodies verbatim — the refactor must not change behaviour.

- [ ] **Step 2: Write `includes/handlers.php`**

Move each closure body into a named global function. Names:

- `cdcf_maintenance_permission_check(): bool`
- `cdcf_handle_maintenance(WP_REST_Request $request)`
- `cdcf_process_queue_permission_check(): bool`
- `cdcf_handle_process_queue(WP_REST_Request $request)`

Each function's body is the original closure body verbatim. No logic changes. Add a header comment:

```php
<?php
/**
 * REST route handlers for /cdcf/v1/*.
 *
 * Extracted from the inline closures in cdcf-redis-translations.php so
 * they can be unit-tested with Brain Monkey + Mockery. The plugin
 * entry require_once's this file and references the named functions in
 * its register_rest_route() calls.
 */
```

- [ ] **Step 3: Update the plugin entry**

In `cdcf-redis-translations.php`, near the top (before `add_action('rest_api_init', ...)`):

```php
require_once __DIR__ . '/includes/handlers.php';
```

Inside the `rest_api_init` callback, replace each route's closure references:

```php
register_rest_route('cdcf/v1', '/maintenance', [
    'methods'             => 'POST',
    'permission_callback' => 'cdcf_maintenance_permission_check',
    'callback'            => 'cdcf_handle_maintenance',
    'args'                => [ /* unchanged */ ],
]);

register_rest_route('cdcf/v1', '/process-queue', [
    'methods'             => 'POST',
    'permission_callback' => 'cdcf_process_queue_permission_check',
    'callback'            => 'cdcf_handle_process_queue',
    'args'                => [ /* unchanged */ ],
]);
```

- [ ] **Step 4: PHP lint and smoke-test in docker-compose**

```bash
docker compose exec wordpress php -l /var/www/html/wp-content/plugins/cdcf-redis-translations/cdcf-redis-translations.php
docker compose exec wordpress php -l /var/www/html/wp-content/plugins/cdcf-redis-translations/includes/handlers.php
```

Expect `No syntax errors detected` for both. Then a behavioural smoke-test against the local stack:

```bash
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"begin","duration_seconds":300}'
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"end"}'
```

Expect the same responses as before the refactor (200 with `{ok:true,until,duration}` and `{ok:true}`).

If docker compose isn't running locally, defer verification to the post-merge production deploy and note it in the PR description.

- [ ] **Step 5: Commit**

```bash
git add wordpress/plugins/cdcf-redis-translations/includes/handlers.php \
        wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php
git commit -m "$(cat <<'EOF'
refactor(plugin): extract /cdcf/v1 route closures into named functions

Pure-mechanical extraction — no logic changes, behaviour-preserving.
Sets up the routes for unit testing with PHPUnit + Brain Monkey, which
cannot stub or call inline closures. Each route's permission and
callback closures move into includes/handlers.php as named globals;
the register_rest_route() calls now reference them by name.

Refs #63.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Plugin — composer + phpunit scaffolding

**Files:**

- Create: `wordpress/plugins/cdcf-redis-translations/composer.json`
- Create: `wordpress/plugins/cdcf-redis-translations/phpunit.xml.dist`
- Create: `wordpress/plugins/cdcf-redis-translations/.gitignore`
- Create: `wordpress/plugins/cdcf-redis-translations/tests/bootstrap.php`

- [ ] **Step 1: composer.json**

```json
{
  "name": "cdcf/redis-translations-tests",
  "description": "Tests for the cdcf-redis-translations plugin.",
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "brain/monkey": "^2.6",
    "mockery/mockery": "^1.6"
  },
  "scripts": {
    "test": "phpunit"
  },
  "config": {
    "allow-plugins": false
  }
}
```

- [ ] **Step 2: phpunit.xml.dist**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
    failOnWarning="true"
    failOnNotice="true"
>
    <testsuites>
        <testsuite name="cdcf-redis-translations">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: .gitignore for the plugin tree**

```text
/vendor/
/.phpunit.cache/
/.phpunit.result.cache
```

- [ ] **Step 4: tests/bootstrap.php**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Brain Monkey teardown is per-test in setUp/tearDown; nothing to do here.

// Define minimal WP constants/classes the production code touches at
// load time, so requiring handlers.php doesn't blow up before Brain
// Monkey gets a chance to stub.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public function __construct(public mixed $data = null, public int $status = 200) {}
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code = '', public string $message = '', public array $data = []) {}
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess {
        private array $params = [];
        public function set_param(string $k, mixed $v): void { $this->params[$k] = $v; }
        public function offsetExists($k): bool { return isset($this->params[$k]); }
        public function offsetGet($k): mixed { return $this->params[$k] ?? null; }
        public function offsetSet($k, $v): void { $this->params[$k] = $v; }
        public function offsetUnset($k): void { unset($this->params[$k]); }
    }
}

require_once __DIR__ . '/../includes/handlers.php';
```

- [ ] **Step 5: Install deps**

```bash
composer install --working-dir=wordpress/plugins/cdcf-redis-translations
```

Expect `vendor/` to appear. The plugin will still load fine in WordPress because PHP only sees `vendor/` if something explicitly requires it — and the production code never does.

- [ ] **Step 6: Smoke-test the runner**

```bash
wordpress/plugins/cdcf-redis-translations/vendor/bin/phpunit -c wordpress/plugins/cdcf-redis-translations/phpunit.xml.dist
```

Expect "No tests executed" (zero tests so far). If it errors instead, the bootstrap is wrong — fix before adding tests.

- [ ] **Step 7: Commit**

```bash
git add wordpress/plugins/cdcf-redis-translations/composer.json \
        wordpress/plugins/cdcf-redis-translations/phpunit.xml.dist \
        wordpress/plugins/cdcf-redis-translations/.gitignore \
        wordpress/plugins/cdcf-redis-translations/tests/bootstrap.php
git commit -m "$(cat <<'EOF'
test(plugin): scaffold PHPUnit + Brain Monkey runner

composer.json pulls in phpunit/phpunit ^10.5, brain/monkey ^2.6,
mockery/mockery ^1.6 as dev deps. phpunit.xml.dist discovers tests/
with failOnWarning + failOnNotice so deprecations bite early. Bootstrap
defines stub WP_REST_Request/Response/Error classes the handler code
mentions at load time, then require_once's includes/handlers.php so
Brain Monkey can intercept the WP function calls inside.

vendor/ is .gitignored — composer.json + the lockfile are enough to
reproduce.

Refs #63.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Plugin — MaintenanceHandlerTest

**Files:**

- Create: `wordpress/plugins/cdcf-redis-translations/tests/MaintenanceHandlerTest.php`

- [ ] **Step 1: Write the test class**

Cases (one PHPUnit method per row):

| Case                                                               | Setup                                                                                                 | Expected                                                                      |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Permission check delegates to `current_user_can('manage_options')` | `Brain\Monkey\Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);` | `cdcf_maintenance_permission_check() === true`                                |
| `begin` with default duration → 200 + `setex` called with 300s TTL | Mockery `overload:Redis`, expect `connect` then `setex('cdcf:maintenance:until', 300, '1')`           | response is `WP_REST_Response`, `data['ok']===true`, `data['duration']===300` |
| `begin` with low duration → clamped to 60                          | `duration_seconds=1`                                                                                  | `data['duration']===60`, `setex(..., 60, ...)` called                         |
| `begin` with high duration → clamped to 600                        | `duration_seconds=99999`                                                                              | `data['duration']===600`                                                      |
| `begin` with `300` → passes through unchanged                      | `duration_seconds=300`                                                                                | `data['duration']===300`                                                      |
| `end` → 200 + `del` called                                         | `action='end'`                                                                                        | `data['ok']===true`, `del('cdcf:maintenance:until')` called                   |
| `end` idempotent (key absent)                                      | Mockery `del` returns 0                                                                               | still `{ok:true}` — endpoint doesn't error                                    |
| Invalid action → 400 `WP_Error('invalid_action')`                  | `action='foo'`                                                                                        | response is a `WP_Error` with code `invalid_action` and HTTP 400              |
| Missing `Redis` class → 500 `redis_unavailable`                    | Don't define `Redis` (or use a guard); skip-if needed                                                 | `WP_Error('redis_unavailable')`, HTTP 500                                     |
| `Redis::connect()` returns false → 500                             | Mockery `connect` returns false                                                                       | `WP_Error('redis_unavailable')`, HTTP 500                                     |
| `setex` returns false → 500 `redis_write_failed`                   | Mockery `setex` returns false                                                                         | `WP_Error('redis_write_failed')`, HTTP 500                                    |

Use a `WP_REST_Request` constructed in the test, with `set_param` for `action` and `duration_seconds`.

Pattern (per test):

```php
public function test_begin_clamps_low_duration_to_60(): void {
    $req = new WP_REST_Request();
    $req->set_param('action', 'begin');
    $req->set_param('duration_seconds', 1);

    $redis = Mockery::mock('overload:Redis');
    $redis->shouldReceive('connect')->once()->andReturn(true);
    $redis->shouldReceive('setex')
        ->once()
        ->with('cdcf:maintenance:until', 60, '1')
        ->andReturn(true);

    $response = cdcf_handle_maintenance($req);

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(60, $response->data['duration']);
}
```

Use `Brain\Monkey\setUp()` in `setUp()` and `Brain\Monkey\tearDown(); Mockery::close()` in `tearDown()`.

Mockery's `overload:` requires running each test in a separate process — set `@runInSeparateProcess` and `@preserveGlobalState disabled` annotations on tests that overload `Redis`. Tests that don't touch Redis can run in-process.

- [ ] **Step 2: Run the suite**

```bash
wordpress/plugins/cdcf-redis-translations/vendor/bin/phpunit -c wordpress/plugins/cdcf-redis-translations/phpunit.xml.dist tests/MaintenanceHandlerTest.php
```

Expect all 11 tests green. If any fail, the test is wrong (the handler code is verified in production) — fix the test.

- [ ] **Step 3: Commit**

```bash
git add wordpress/plugins/cdcf-redis-translations/tests/MaintenanceHandlerTest.php
git commit -m "test(plugin): cover cdcf_handle_maintenance branches

Eleven cases over the begin/end actions, TTL clamp boundaries, invalid
action, missing-Redis-class, connect failure, and setex failure paths.
The same handler is the one used in production by the deploy workflow
and verified end-to-end via #66; this suite is the regression net.

Refs #63.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Plugin — ProcessQueueHandlerTest

**Files:**

- Create: `wordpress/plugins/cdcf-redis-translations/tests/ProcessQueueHandlerTest.php`

- [ ] **Step 1: Write the test class**

Cases:

| Case                                                                                                 | Setup                                                                                              | Expected                              |
| ---------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | ------------------------------------- |
| `redis_queue()` not defined → 200 with `{processed:0, error:'redis_queue not available'}`            | `Brain\Monkey\Functions\when('function_exists')->alias(fn($f) => $f !== 'redis_queue');`           | response `data` matches               |
| Happy path delegates to `redis_queue()->get_job_processor()->process_jobs(['default'], $batch_size)` | Mockery-mock the global `redis_queue` function via Brain Monkey + a stub object exposing the chain | response wraps the processor's return |
| `batch_size` clamp — input 0 → 1                                                                     | `batch_size=0`                                                                                     | `process_jobs` called with `1`        |
| `batch_size` clamp — input 100 → 50                                                                  | `batch_size=100`                                                                                   | `process_jobs` called with `50`       |
| `batch_size` omitted → default 10                                                                    | no batch_size param                                                                                | `process_jobs` called with `10`       |
| Permission check delegates to `current_user_can('manage_options')`                                   | (as in MaintenanceHandlerTest)                                                                     | returns true                          |

- [ ] **Step 2: Run + commit**

```bash
wordpress/plugins/cdcf-redis-translations/vendor/bin/phpunit -c wordpress/plugins/cdcf-redis-translations/phpunit.xml.dist tests/ProcessQueueHandlerTest.php

git add wordpress/plugins/cdcf-redis-translations/tests/ProcessQueueHandlerTest.php
git commit -m "test(plugin): cover cdcf_handle_process_queue (Refs #63)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Plugin — EnqueueTranslationFallbackTest

**Files:**

- Create: `wordpress/plugins/cdcf-redis-translations/tests/EnqueueTranslationFallbackTest.php`

The `cdcf_enqueue_translation()` function in `includes/functions.php` has a Redis-up vs Redis-down branch — Redis-up enqueues a job to the queue; Redis-down (or `redis_queue()` not defined) falls back to `wp_schedule_single_event`. That's a high-stakes branch (translation goes via cron instead of Redis) and worth pinning.

Cases:

| Case                                                                 | Setup                                                                                | Expected                                                                                                                 |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| Redis-up: enqueues translation job, does NOT schedule WP-Cron        | Mock `redis_queue()` to return a chain that swallows `enqueue`                       | `wp_schedule_single_event` NOT called (verify with `Brain\Monkey\Functions\expect('wp_schedule_single_event')->never()`) |
| Redis-down: falls back to `wp_schedule_single_event` exactly once    | `function_exists('redis_queue')` returns false                                       | `wp_schedule_single_event` called once with the right hook + args                                                        |
| `redis_queue()->queue_manager->enqueue` throws → fallback to WP-Cron | Mock enqueue to throw `RuntimeException('redis down')`                               | `wp_schedule_single_event` called once                                                                                   |
| Argument shape passed downstream                                     | inspect the args of `enqueue` (Redis-up) and `wp_schedule_single_event` (Redis-down) | match the documented contract: `[ 'post_id', 'source_id', 'target_lang' ]`                                               |

- [ ] **Run + commit:**

```bash
wordpress/plugins/cdcf-redis-translations/vendor/bin/phpunit -c wordpress/plugins/cdcf-redis-translations/phpunit.xml.dist tests/EnqueueTranslationFallbackTest.php

git add wordpress/plugins/cdcf-redis-translations/tests/EnqueueTranslationFallbackTest.php
git commit -m "test(plugin): cover cdcf_enqueue_translation Redis fallback (Refs #63)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Theme — refactor relationship handlers + scaffold + tests

**Files:**

- Modify: `wordpress/themes/cdcf-headless/functions.php`
- Create: `wordpress/themes/cdcf-headless/includes/handlers/relationship.php`
- Create: `wordpress/themes/cdcf-headless/composer.json`
- Create: `wordpress/themes/cdcf-headless/phpunit.xml.dist`
- Create: `wordpress/themes/cdcf-headless/.gitignore`
- Create: `wordpress/themes/cdcf-headless/tests/bootstrap.php`
- Create: `wordpress/themes/cdcf-headless/tests/RelationshipHandlerTest.php`

Same pattern as Tasks 2-6, scoped to the theme's `/cdcf/v1/relationship` GET+POST routes.

- [ ] **Step 1: Locate the closure callbacks in `functions.php`** and capture their bodies verbatim.
- [ ] **Step 2: Extract into `includes/handlers/relationship.php`** with names `cdcf_handle_relationship_get`, `cdcf_handle_relationship_post`, `cdcf_relationship_permission_check`.
- [ ] **Step 3: Update `functions.php`** with `require_once` and the named-function references.
- [ ] **Step 4: composer.json + phpunit.xml.dist + .gitignore + bootstrap.php** — same shape as the plugin tree.
- [ ] **Step 5: `RelationshipHandlerTest.php`** cases:

| Case                                                               | Expected                                  |
| ------------------------------------------------------------------ | ----------------------------------------- |
| GET reads ACF field via `get_field` and returns it as JSON         | response shape matches                    |
| GET with missing `post_id` → 400 `WP_Error('missing_param')`       |                                           |
| GET with missing `field` → 400                                     |                                           |
| POST writes ACF field via `update_field` and returns the new value | `update_field` called with the right args |
| POST with non-array `value` → 400 `WP_Error('invalid_value')`      |                                           |
| Permission callback delegates to `current_user_can('edit_posts')`  |                                           |

- [ ] **Step 6: Run + commit** the refactor and the tests as two separate commits (refactor first so it's reviewable on its own).

---

## Task 8: CI workflow

**Files:**

- Create: `.github/workflows/test-wordpress.yml`

- [ ] **Step 1: Write the workflow**

```yaml
name: PHPUnit (WordPress plugin + theme)

permissions:
  contents: read

on:
  pull_request:
    branches: [main]
    paths:
      - "wordpress/plugins/cdcf-redis-translations/**"
      - "wordpress/themes/cdcf-headless/**"
      - ".github/workflows/test-wordpress.yml"
  push:
    branches: [main]
    paths:
      - "wordpress/plugins/cdcf-redis-translations/**"
      - "wordpress/themes/cdcf-headless/**"
      - ".github/workflows/test-wordpress.yml"

concurrency:
  group: test-wordpress-${{ github.event.pull_request.number || github.ref }}
  cancel-in-progress: true

jobs:
  phpunit:
    runs-on: ubuntu-latest
    continue-on-error: true
    strategy:
      fail-fast: false
      matrix:
        tree:
          - plugins/cdcf-redis-translations
          - themes/cdcf-headless
    steps:
      - uses: actions/checkout@<pinned-sha> # v6
      - uses: shivammathur/setup-php@<pinned-sha>
        with:
          php-version: "8.3"
          tools: composer
      - name: Install dependencies
        working-directory: wordpress/${{ matrix.tree }}
        run: composer install --no-interaction --no-progress
      - name: Run PHPUnit
        working-directory: wordpress/${{ matrix.tree }}
        run: vendor/bin/phpunit
```

Pin actions to commit SHAs (look up the latest tags for `actions/checkout`, `shivammathur/setup-php`).

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/test-wordpress.yml
git commit -m "ci(test): non-blocking PHPUnit workflow for plugin + theme (Refs #63)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Documentation

**Files:**

- Modify: `AGENTS.md`

- [ ] **Step 1: Add the commands to "Build & Development Commands"**

```bash
# WordPress plugin / theme tests (run independently per tree)
composer test --working-dir=wordpress/plugins/cdcf-redis-translations
composer test --working-dir=wordpress/themes/cdcf-headless
```

Plus a short paragraph noting the two trees are independent and that the suites use PHPUnit + Brain Monkey + Mockery, with vendor/ gitignored.

- [ ] **Step 2: Commit**

```bash
git add AGENTS.md
git commit -m "docs: PHPUnit invocations in build commands (Refs #63)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Push branch and open PR

- [ ] **Step 1: Push**

```bash
git push -u origin feat/phpunit-wp-suites
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "feat(test): PHPUnit + Brain Monkey suites for plugin and theme (Refs #63)" --body "$(cat <<'EOF'
Second of three planned test suites for #63 (following the Vitest pilot in #86).

## Summary

- New PHPUnit + Brain Monkey + Mockery suites for the cdcf-redis-translations plugin and the cdcf-headless theme, each as an independent composer tree.
- Mechanical refactor: route closures extracted into named handler functions so Brain Monkey can call them directly. No production behaviour change.
- Non-blocking CI (\`continue-on-error: true\`) gated on changes under each tree.

## Coverage

| Suite | Cases |
|---|---|
| MaintenanceHandlerTest | 11 — begin/end actions, TTL clamp boundaries, invalid action, missing Redis class, connect failure, setex failure |
| ProcessQueueHandlerTest | 6 — happy path, redis_queue absence, batch_size clamps, permission delegation |
| EnqueueTranslationFallbackTest | 4 — Redis-up enqueue path vs three Redis-down fallback variants |
| RelationshipHandlerTest | 6 — GET/POST, missing params, ACF integration, permission delegation |

## Verification

- Local smoke-test against docker-compose stack confirms the refactored handlers still respond identically before/after (begin returns the same JSON shape, end is idempotent, etc.).
- Production stack continues to serve POST /cdcf/v1/maintenance after deploy — already verified in #41 close-out.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Wait for CI + CodeRabbit, address feedback as separate commits, merge with \`gh pr merge --merge --delete-branch\` once green.**

---

## Post-merge verification

After this lands and the next production deploy runs:

1. \`scripts/.venv/bin/python scripts/cdcf_api.py --target production rest-post cdcf/v1/maintenance --data '{"action":"begin","duration_seconds":60}'\` → 200 (handler still works)
2. \`scripts/.venv/bin/python scripts/cdcf_api.py --target production rest-post cdcf/v1/maintenance --data '{"action":"end"}'\` → 200
3. Worker journal shows transitions as before.

Any regression indicates the refactor changed behaviour despite the tests passing — open a fix-up.
