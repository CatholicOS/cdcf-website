# Test Suites — Design

**Issue:** #63
**Related:** #62 (forcing function — `/maintenance` endpoint, `in_maintenance()` worker helper), #57 / #59 (sitemap regressions that an `lib/wordpress/*` test net would have caught), #41 (root-cause investigation that produced #62)
**Status:** Draft — awaiting user review of written spec

## Problem

There is no test runner anywhere in the repo. `npm run build` catches type errors, and everything else is verified by hand — `curl` for REST endpoints, `journalctl` for the worker, manual click-throughs for the frontend. Every recent server-side feature (#41, #57, #59, #61, #62) shipped with no automated coverage, and the data-mapping bugs in `getAllPages` / `getPostsForSitemap` / `getProjectsForSitemap` (#57, #59) are the kind of regression a tiny unit test would have caught the first time the function was touched.

## Goal

Establish a **regression net** across the three production stacks the project actually ships:

1. The Next.js TypeScript data layer (`lib/wordpress/*` and the GraphQL→view mapping)
2. The WordPress PHP plugin and theme (`cdcf/v1` REST endpoints, especially the new `/maintenance` from #62)
3. The bash queue worker (`scripts/cdcf_queue_worker.sh` — `in_maintenance`, JSON parsing, daily-task cadence)

The bar is "would a test catch the regression next time someone touches this code," not "high coverage percentage."

## Non-goals

- E2E browser tests (separate concern)
- Any blocking CI gate — non-blocking only for v1; gating becomes a follow-up issue once the suites are stable
- Theme `functions.php` endpoints beyond `/relationship` (separate follow-up)
- WP CPT / ACF registration tests (separate follow-up)
- Coverage thresholds
- Refactoring code beyond what testing requires

## Architecture

Three independent test suites, one per stack, each living under its own tree so the three implementation PRs do not conflict.

```text
cdcf-website/
├── lib/wordpress/
│   ├── api.ts
│   ├── api.test.ts                         ← Vitest (PR 1)
│   ├── client.ts
│   ├── client.test.ts                      ← Vitest (PR 1)
│   └── …
├── vitest.config.ts                        ← Vitest (PR 1)
│
├── wordpress/plugins/cdcf-redis-translations/
│   ├── cdcf-redis-translations.php         (refactored — PR 2)
│   ├── includes/handlers.php               (new — PR 2)
│   ├── tests/                              ← PHPUnit + Brain Monkey (PR 2)
│   │   ├── bootstrap.php
│   │   ├── MaintenanceHandlerTest.php
│   │   ├── ProcessQueueHandlerTest.php
│   │   └── EnqueueTranslationFallbackTest.php
│   ├── composer.json                       ← PR 2
│   └── phpunit.xml.dist                    ← PR 2
│
├── wordpress/themes/cdcf-headless/
│   ├── functions.php                       (refactored — PR 2)
│   ├── includes/handlers/relationship.php  (new — PR 2)
│   ├── tests/                              ← PHPUnit + Brain Monkey (PR 2)
│   │   ├── bootstrap.php
│   │   └── RelationshipHandlerTest.php
│   ├── composer.json                       ← PR 2
│   └── phpunit.xml.dist                    ← PR 2
│
├── scripts/
│   ├── cdcf_queue_worker.sh                (refactored to source lib — PR 3)
│   ├── cdcf_queue_worker.lib.sh            (new — PR 3)
│   └── tests/                              ← bats-core (PR 3)
│       ├── helpers/
│       │   └── shims/                      (PATH-injected fakes for redis-cli, curl, python3)
│       ├── in_maintenance.bats
│       ├── parse_processed.bats
│       ├── should_run_daily_tasks.bats
│       └── process_one.bats
│
└── .github/workflows/
    ├── test-nextjs.yml                     ← PR 1
    ├── test-wordpress.yml                  ← PR 2
    └── test-worker.yml                     ← PR 3
```

The three PRs touch entirely disjoint paths (modulo `CLAUDE.md` and `package.json` for PR 1 only), so they can be developed in parallel worktrees and merged in any order.

## Shared conventions

| Decision               | Choice                                                                                                                        | Why                                                                                                      |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| CI gating              | Non-blocking — `continue-on-error: true` per workflow; not added to required-checks list                                      | "Gate later" per the issue. Land tests, watch them stabilize, flip the gate in a follow-up.              |
| Coverage tooling       | Install where free (`@vitest/coverage-v8`); no thresholds                                                                     | Visibility without enforcement. Thresholds invite gaming and add CI friction before the suite is mature. |
| Test runner invocation | One command per stack: `npm test` (root), `composer test` (each PHP tree), `bats scripts/tests/` (root)                       | Predictable for both humans and CI.                                                                      |
| Documentation          | Update `CLAUDE.md` "Build & Development Commands" with the three invocations; add a brief "Testing" subsection per stack tree | Keeps the canonical onboarding doc accurate.                                                             |
| File location          | Each stack uses its native convention (co-located `*.test.ts`, `tests/` subdir for PHP & bash)                                | No artificial unification.                                                                               |

## Component 1 — Vitest (Next.js) — PR 1

### Setup

- Add devDependencies: `vitest`, `@vitest/coverage-v8`
- New file `vitest.config.ts` at repo root: `pool: 'threads'`, `environment: 'node'`, `globals: false`, `coverage.provider: 'v8'`, `coverage.include: ['lib/wordpress/**/*.ts']`
- `package.json` scripts:
  - `"test": "vitest run"`
  - `"test:watch": "vitest"`
  - `"test:coverage": "vitest run --coverage"`
- `tsconfig.json` already covers `lib/wordpress/**/*.test.ts` via its include glob; no path changes needed

### Test files

Co-located, one file per source file under test.

| File                           | Targets                                                   | Key cases                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------------------ | --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `lib/wordpress/client.test.ts` | `wpQuery()`                                               | Happy path JSON response; `!res.ok` throws with status text; non-JSON `content-type` throws "WPGraphQL returned non-JSON"; GraphQL `errors[]` throws with concatenated messages; `revalidate`, `tags`, and `draft` headers/options forwarded to `fetch` correctly                                                                                                                                                                                                             |
| `lib/wordpress/api.test.ts`    | `LOCALE_MAP`, `langCode()`, all exported `get*` functions | `langCode` returns mapped uppercase for known locales, `'EN'` for unknown; each `get*` has a happy path + `wpQuery`-throws path returning the documented fallback (`null`, `[]`); `getPage`/`getPostBySlug` English-fallback path when translation missing; mapping correctness for `getAllPages`, `getPostsForSitemap`, `getProjectsForSitemap`, `getChildPages` (the #57/#59 hot spots — assert `enUri`, `availableLocales`, translation slug shape, `hideFromBlog` filter) |

### Mocking strategy

- `client.test.ts`: mock global `fetch` via `vi.stubGlobal('fetch', vi.fn())`; assert call args including `next: { revalidate, tags }`
- `api.test.ts`: mock `./client.js` with `vi.mock('./client', () => ({ wpQuery: vi.fn() }))`; configure return value or rejection per test

### CI workflow — `.github/workflows/test-nextjs.yml`

Trigger: `pull_request`, `push: { branches: [main] }` with paths-filter on `lib/**`, `app/**`, `components/**`, `package.json`, `vitest.config.ts`, the workflow itself.
Steps: checkout → setup-node@v4 with `node-version-file: .nvmrc` and `cache: npm` → `npm ci` → `npm test`. Job-level `continue-on-error: true`.

## Component 2 — PHPUnit + Brain Monkey (WordPress) — PR 2

### Setup

Two PHP trees get their own `composer.json` + `phpunit.xml.dist`: the plugin (`wordpress/plugins/cdcf-redis-translations/`) and the theme (`wordpress/themes/cdcf-headless/`). Each tree's `composer.json` requires `phpunit/phpunit:^10`, `brain/monkey:^2.6`, `mockery/mockery:^1.6` as dev deps. PHP version pinned to the plugin header's `Requires PHP: 8.3`.

### Refactor — extract handlers from inline closures

Brain Monkey unit-tests pure-PHP functions, so each route's logic must be callable outside its `register_rest_route` closure. The refactor pattern:

**Before (current):**

```php
register_rest_route('cdcf/v1', '/maintenance', [
    'methods' => 'POST',
    'permission_callback' => function () { return current_user_can('manage_options'); },
    'callback' => function (WP_REST_Request $request) {
        // 50 lines of logic …
    },
]);
```

**After:**

```php
register_rest_route('cdcf/v1', '/maintenance', [
    'methods' => 'POST',
    'permission_callback' => 'cdcf_maintenance_permission_check',
    'callback' => 'cdcf_handle_maintenance',
]);

function cdcf_maintenance_permission_check(): bool {
    return current_user_can('manage_options');
}

function cdcf_handle_maintenance(WP_REST_Request $request) {
    // same 50 lines, now callable from tests
}
```

Handlers go in `wordpress/plugins/cdcf-redis-translations/includes/handlers.php` (new file, `require_once`'d from the plugin entry) and `wordpress/themes/cdcf-headless/includes/handlers/relationship.php` (new file, `require_once`'d from `functions.php`).

Redis access in the maintenance handler stays as `new Redis()` instantiation inside the handler — Brain Monkey can substitute the class via Mockery's `overload:` prefix in the test.

### Test files

#### `wordpress/plugins/cdcf-redis-translations/tests/`

| File                                 | Targets                                                            | Key cases                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ------------------------------------ | ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `MaintenanceHandlerTest.php`         | `cdcf_handle_maintenance()`, `cdcf_maintenance_permission_check()` | `action: 'begin'` calls `setex` with clamped TTL and returns 200 with `{ok, until, duration}`; `action: 'end'` calls `del` and returns 200 with `{ok}`; invalid action returns 400 `WP_Error('invalid_action')`; missing PHP `Redis` class returns 500 `redis_unavailable`; `Redis::connect()` returns false → 500; `setex` returns false → 500 `redis_write_failed`; TTL clamp at boundaries — input 1 → 60, input 99999 → 600, input 300 → 300; `end` is idempotent (works whether or not key exists); permission check delegates to `current_user_can('manage_options')` |
| `ProcessQueueHandlerTest.php`        | `cdcf_handle_process_queue()`                                      | `redis_queue()` not defined → 200 with `{processed: 0, error: 'redis_queue not available'}`; happy path delegates to `redis_queue()->get_job_processor()->process_jobs(['default'], $batch_size)` and returns 200 wrapping the result; `batch_size` clamping at boundaries — input 0 → 1, input 100 → 50, default omitted → 10; permission check delegates to `current_user_can('manage_options')`                                                                                                                                                                          |
| `EnqueueTranslationFallbackTest.php` | `cdcf_enqueue_translation()` (in `includes/functions.php`)         | Redis-up: enqueues a translation job and skips `wp_schedule_single_event`; Redis-down (or `redis_queue()` not defined): falls back to `wp_schedule_single_event` exactly once with the right hook + args; arg shape passed downstream matches the documented contract                                                                                                                                                                                                                                                                                                       |

#### `wordpress/themes/cdcf-headless/tests/`

| File                          | Targets                                                                                  | Key cases                                                                                                                                                                                                                                   |
| ----------------------------- | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `RelationshipHandlerTest.php` | `cdcf_handle_relationship_get()`, `cdcf_handle_relationship_post()`, permission callback | GET returns ACF field as JSON; GET with missing `post_id` or `field` → 400; POST with non-array `value` → 400; POST writes ACF field via `update_field` and returns the new value; permission delegates to `current_user_can('edit_posts')` |

### Test bootstrap

`tests/bootstrap.php` per tree:

```php
require_once __DIR__ . '/../vendor/autoload.php';
\Brain\Monkey\setUp();
register_shutdown_function(fn() => \Brain\Monkey\tearDown());
// require_once the handler files under test, NOT the entry that calls register_rest_route
require_once __DIR__ . '/../includes/handlers.php';
```

Each test class uses `Brain\Monkey\setUp()` / `tearDown()` in `setUp()`/`tearDown()` and `Mockery::close()` in `tearDown()`. WP functions like `current_user_can`, `update_field`, `get_field`, `wp_schedule_single_event`, `WP_REST_Response`, `WP_Error`, `absint` are stubbed with `Brain\Monkey\Functions\when(...)->justReturn(...)` or `Brain\Monkey\Functions\expect(...)->once()->with(...)->andReturn(...)`.

For `WP_REST_Request`, use Mockery to mock the `[]`-access (`offsetGet`/`offsetExists`).

### CI workflow — `.github/workflows/test-wordpress.yml`

Trigger: `pull_request`, `push: { branches: [main] }` with paths-filter on `wordpress/**`, the workflow itself.
Steps: checkout → `shivammathur/setup-php@v2` with `php-version: '8.3'`, `tools: composer` → matrix `[plugins/cdcf-redis-translations, themes/cdcf-headless]` → `composer install --working-dir=wordpress/${{ matrix.tree }}` → `wordpress/${{ matrix.tree }}/vendor/bin/phpunit -c wordpress/${{ matrix.tree }}/phpunit.xml.dist`. Job-level `continue-on-error: true`.

## Component 3 — bats-core (bash worker) — PR 3

### Refactor — extract helpers into a lib

Split `scripts/cdcf_queue_worker.sh` into:

- `scripts/cdcf_queue_worker.sh` — env-var loading, banner, `source ./cdcf_queue_worker.lib.sh`, the `while true` loop. ~50 lines.
- `scripts/cdcf_queue_worker.lib.sh` — pure functions: `in_maintenance`, `parse_processed`, `parse_domain_count`, `should_run_daily_tasks`, `process_one`, `run_daily_tasks`. Side effects (`echo`, `curl`, `redis-cli`, `python3`, `date`) are still done inline within these functions — they are unit-of-work helpers, not pure functions — but each is independently sourceable and callable.

The two new pure functions extracted from inline Python:

```bash
# parse_processed reads JSON from stdin and prints the processed count.
# Returns "error" on invalid JSON, "0" or a positive integer otherwise.
parse_processed() {
    python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
    p = d.get('processed', {})
    print(p.get('processed', 0) if isinstance(p, dict) else p)
except Exception:
    print('error')
" 2>/dev/null
}

# parse_domain_count reads the disposable-domains response from stdin
# and prints d['domains'] (a count), or '?' on parse failure.
parse_domain_count() {
    python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
    print(d.get('domains', '?'))
except Exception:
    print('?')
" 2>/dev/null
}
```

And the cadence guard:

```bash
# should_run_daily_tasks returns 0 (true) if NOW - LAST_DAILY_RUN >= DAILY_INTERVAL.
# Reads $LAST_DAILY_RUN and $DAILY_INTERVAL from the environment.
should_run_daily_tasks() {
    local now
    now=$(date +%s)
    (( now - LAST_DAILY_RUN >= DAILY_INTERVAL ))
}
```

### Test files — `scripts/tests/`

Each `.bats` file `source`s the lib at the top, sets up shims, runs cases.

| File                          | Targets                    | Key cases                                                                                                                                                                                                                                                                                         |
| ----------------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `in_maintenance.bats`         | `in_maintenance()`         | redis-cli returns `1` on stdout → function returns 0; returns `0` → function returns 1; redis-cli exits non-zero → function returns 1 (the "Redis-down means not in maintenance" policy from #62)                                                                                                 |
| `parse_processed.bats`        | `parse_processed()`        | Valid `{processed: 5}` → `5`; valid `{processed: {processed: 3}}` → `3`; missing `processed` → `0`; invalid JSON → `error`; empty input → `error`                                                                                                                                                 |
| `should_run_daily_tasks.bats` | `should_run_daily_tasks()` | `LAST_DAILY_RUN=0` → returns 0 (true); `LAST_DAILY_RUN=NOW` → returns 1 (false); `LAST_DAILY_RUN=NOW-86400` exact boundary → returns 0; `DAILY_INTERVAL` overridden via env                                                                                                                       |
| `process_one.bats`            | `process_one()`            | curl returns 200 + valid JSON → "Processed N job(s)" log line; HTTP 500 with HTML body → "WARNING: HTTP 500: …" with HTML stripped; curl exits with `http_code=000` → "WARNING: request failed (connection error or timeout)"; invalid JSON in 200 response → "WARNING: invalid JSON response: …" |

### Shimming external commands

Tests rely on a `helpers/shims/` directory containing fake `redis-cli`, `curl`, `python3` scripts. A `setup_file()` hook in each `.bats` file prepends this directory to `PATH`:

```bash
setup_file() {
    PATH="$BATS_TEST_DIRNAME/helpers/shims:$PATH"
    export PATH
}
```

Each fake reads its expected behavior from per-test env vars (`SHIM_REDIS_OUTPUT`, `SHIM_REDIS_EXIT`, `SHIM_CURL_BODY`, `SHIM_CURL_HTTP_CODE`, etc.). The real `python3` is **not** shimmed for `parse_processed.bats` — those tests want the real JSON parser.

### Bats runner

bats-core gets vendored as a git submodule under `scripts/tests/bats/` (the standard recommendation). The CI workflow invokes `scripts/tests/bats/bin/bats scripts/tests/`.

### CI workflow — `.github/workflows/test-worker.yml`

Trigger: `pull_request`, `push: { branches: [main] }` with paths-filter on `scripts/cdcf_queue_worker*`, `scripts/tests/**`, the workflow itself.
Steps: checkout with `submodules: recursive` → ensure `python3` is on PATH → `scripts/tests/bats/bin/bats scripts/tests/`. Job-level `continue-on-error: true`.

## Failure modes and operational notes

| #   | Scenario                                                                                                         | Behavior                                                                                                                                                                                   |
| --- | ---------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | A new test is flaky                                                                                              | CI is non-blocking — flake does not block merges. The follow-up gating issue must address flake budget before flipping the gate.                                                           |
| 2   | The handler refactor (PR 2) breaks a route in production                                                         | Each refactored handler has unit-test coverage of all its branches before the closure swap. Pre-merge manual smoke against the dev Docker stack is in the verification plan.               |
| 3   | The worker refactor (PR 3) breaks the daemon                                                                     | The main script's behavior is unchanged — only the source structure changes. Pre-merge manual run against the dev stack confirms output is unchanged.                                      |
| 4   | Three PRs land at slightly different times and one breaks `npm test` / `composer test` invocation in `CLAUDE.md` | Only PR 1 touches `package.json`; PR 2 and PR 3 add their own commands which work independently of each other. `CLAUDE.md` updates per-PR are additive — last-merger reconciles if needed. |
| 5   | Bats submodule pinning breaks if upstream renames a tag                                                          | Vendor a specific commit SHA, not a tag. Document in `scripts/tests/README.md`.                                                                                                            |

## Verification plan

Each PR is self-verifying via its own test suite plus a manual smoke before merge.

### PR 1 (Vitest)

- `npm test` exits 0 with all `lib/wordpress/*.test.ts` passing
- `npm run test:coverage` produces a report; spot-check that `getAllPages`, `getPostsForSitemap`, `getProjectsForSitemap`, `getChildPages`, `client.ts` error branches all show as covered
- `npm run build` still succeeds (no type regressions)
- CI workflow runs green on the PR

### PR 2 (PHPUnit)

- `composer test` from each PHP tree exits 0
- `docker compose up --build` boots without WP errors (the require_once + handler functions load correctly)
- Manual `curl` against the dev WP — `POST /wp-json/cdcf/v1/maintenance` with `{action:begin,duration_seconds:1}` returns 200 with `duration: 60` (server-side clamp); `POST /wp-json/cdcf/v1/maintenance` with `{action:foo}` returns 400 `invalid_action`; `POST /wp-json/cdcf/v1/maintenance` with `{action:end}` returns 200; existing translate-via-Redis flow still works (validates `cdcf_enqueue_translation` refactor)
- CI workflow runs green on the PR

### PR 3 (bats)

- `scripts/tests/bats/bin/bats scripts/tests/` exits 0
- Manual run of `scripts/cdcf_queue_worker.sh` against the dev stack — output is byte-identical (modulo timestamps) to the pre-refactor version on a representative cycle (one normal cycle, one maintenance-paused cycle, one daily-task cycle)
- CI workflow runs green on the PR

## Rollout & rollback

**Rollout.** Three parallel worktree agents, one per PR. Each PR is independently mergeable in any order. Recommended **dispatch order** if a serial reviewer is the bottleneck: Vitest → bats → PHPUnit (smallest refactor → largest refactor). The agents themselves work concurrently; only review queue is serial.

**Rollback.** Each PR reverts cleanly. PR 2 and PR 3 ship code refactors as part of the test addition — the refactors are mechanical (extract closure → named function, source helpers from a lib) and reversible by `git revert`. No production data or schema is touched.

## Documentation updates

- `CLAUDE.md` — three additions to "Build & Development Commands":
  - `npm test` / `npm run test:coverage` (PR 1)
  - `composer test` from each WP tree, with the path to each (PR 2)
  - `scripts/tests/bats/bin/bats scripts/tests/` (PR 3)
- `wordpress/plugins/cdcf-redis-translations/tests/README.md` — one-paragraph note on running PHPUnit + Brain Monkey (PR 2)
- `wordpress/themes/cdcf-headless/tests/README.md` — same for the theme tree (PR 2)
- `scripts/tests/README.md` — bats-core invocation + the shim convention (PR 3)

## Implementation order

Each PR has its own implementation plan written separately. The three plans are:

1. **PR 1 — Vitest:** `docs/superpowers/plans/2026-05-02-test-suites-vitest.md`
2. **PR 2 — PHPUnit:** `docs/superpowers/plans/2026-05-02-test-suites-phpunit.md`
3. **PR 3 — bats:** `docs/superpowers/plans/2026-05-02-test-suites-bats.md`

After this spec is approved, the next step is `writing-plans` to produce all three plan documents, then `dispatching-parallel-agents` (in worktrees) to execute them concurrently.
