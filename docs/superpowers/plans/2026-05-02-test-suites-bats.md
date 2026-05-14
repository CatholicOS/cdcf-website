# bats-core Test Suite — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add bats-core coverage for the pure-ish helpers in `scripts/cdcf_queue_worker.sh` so future tweaks to the worker have a regression net. Particularly relevant after #85 added `queue_is_empty()` and #66 added `in_maintenance()` and the transition-state log — both are subtle.

**Spec reference:** `docs/superpowers/specs/2026-05-02-test-suites-design.md` — Component 3.

**Issue:** #63. Related: #66 (maintenance flag — `in_maintenance` came from there), #85 (empty-queue fast path — `queue_is_empty` came from there), #86 (Vitest pilot — established the per-suite conventions), and the PHPUnit plan at `docs/superpowers/plans/2026-05-02-test-suites-phpunit.md` (parallelizable with this one).

**Pilot lessons carried in from #86 (Vitest):**

- Scope coverage by motivation. Test the helpers that have had bugs or that capture non-trivial logic, not every line.
- Mock one abstraction layer down. For bash that means shimming external commands (`redis-cli`, `curl`, `python3`) via PATH-injected fakes; the helpers under test still see what they expect to see.
- Non-blocking CI at job level. Land tests first; required-check gating is a follow-up.

**Important project context:**

- The worker script (`scripts/cdcf_queue_worker.sh`) is a single monolithic bash file: env loading, banner, helper definitions (`run_daily_tasks`, `in_maintenance`, `queue_is_empty`, `process_one`), and the main `while true` loop, in that order.
- It's installed on the production VPS at `/usr/local/bin/cdcf_queue_worker.sh` and run by `cdcf-queue-worker.service`. Tests run locally against the repo copy, not against the installed copy.
- This plan **does NOT touch the production worker behaviour.** To make the helpers testable in isolation, a thin refactor extracts them into `scripts/cdcf_queue_worker.lib.sh` and the main script `source`s the lib at startup. The runtime byte-for-byte output of a normal cycle should be unchanged.

---

## File Structure

| File                                        | Action            | Responsibility                                                                                                                                                     |
| ------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `scripts/cdcf_queue_worker.lib.sh`          | Create            | Pure-ish helper functions: `in_maintenance`, `queue_is_empty`, `parse_processed`, `parse_domain_count`, `should_run_daily_tasks`, `process_one`, `run_daily_tasks` |
| `scripts/cdcf_queue_worker.sh`              | Modify            | Env loading, banner, `source ./cdcf_queue_worker.lib.sh`, the main loop. Drops to ~60 lines                                                                        |
| `scripts/tests/helpers/shims/redis-cli`     | Create            | Shim that reads `SHIM_REDIS_OUTPUT` / `SHIM_REDIS_EXIT` env vars and acts accordingly                                                                              |
| `scripts/tests/helpers/shims/curl`          | Create            | Shim that emits a configured body + HTTP code                                                                                                                      |
| `scripts/tests/helpers/shims/date`          | Create (optional) | For `should_run_daily_tasks` — only if `date +%s` mocking is needed; usually env-var override is cleaner                                                           |
| `scripts/tests/in_maintenance.bats`         | Create            | Three cases                                                                                                                                                        |
| `scripts/tests/queue_is_empty.bats`         | Create            | Four cases                                                                                                                                                         |
| `scripts/tests/parse_processed.bats`        | Create            | Five cases                                                                                                                                                         |
| `scripts/tests/should_run_daily_tasks.bats` | Create            | Four cases                                                                                                                                                         |
| `scripts/tests/process_one.bats`            | Create            | Four cases                                                                                                                                                         |
| `scripts/tests/README.md`                   | Create            | bats-core invocation, shim convention, submodule pin                                                                                                               |
| `scripts/tests/bats/`                       | Submodule         | bats-core vendored at a pinned commit                                                                                                                              |
| `.github/workflows/test-worker.yml`         | Create            | CI: bats run on changes under `scripts/cdcf_queue_worker*` or `scripts/tests/**`                                                                                   |
| `AGENTS.md`                                 | Modify            | Add `bats scripts/tests/` to "Build & Development Commands"                                                                                                        |

---

## Task 1: Create feature branch (worktree-friendly)

**Files:** none

- [ ] Create branch:

```bash
git checkout main
git pull --ff-only
git checkout -b feat/bats-worker-suite
```

If parallelizing with the PHPUnit plan:

```bash
git worktree add ../cdcf-website-bats feat/bats-worker-suite
```

---

## Task 2: Extract helpers into a lib

**Files:**

- Create: `scripts/cdcf_queue_worker.lib.sh`
- Modify: `scripts/cdcf_queue_worker.sh`

The refactor pattern: every function definition currently in `cdcf_queue_worker.sh` moves into `cdcf_queue_worker.lib.sh`, byte-for-byte unchanged. The main script becomes:

```bash
#!/bin/bash
set -e

# (existing env-var loading: ENDPOINT, AUTH, POLL_INTERVAL, etc.)
# (existing banner)

# Source the library — must be next to this script.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./cdcf_queue_worker.lib.sh
source "$SCRIPT_DIR/cdcf_queue_worker.lib.sh"

# (existing main while-true loop, unchanged)
```

The lib file gets a header:

```bash
# Helper functions for cdcf-queue-worker.
#
# Sourced by cdcf_queue_worker.sh at startup. Each function is also
# directly sourceable by bats tests in scripts/tests/, so external
# command dependencies (redis-cli, curl, python3, date) live inside
# the functions rather than as ambient globals — tests can PATH-shim
# the commands without monkey-patching variables.
```

- [ ] **Step 1: Identify the function boundaries** in the current `cdcf_queue_worker.sh`. The existing function definitions are: `run_daily_tasks`, `in_maintenance`, `queue_is_empty`, `process_one`. Plus any internal-only helpers (currently the inline `python3 -c '...'` blocks inside `run_daily_tasks` and `process_one`).
- [ ] **Step 2: Extract the inline `python3 -c '...'` blocks** into two new pure helpers in the lib:

```bash
# parse_processed reads JSON from stdin and prints the processed count.
# Returns "error" on invalid JSON, or the processed value (string).
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

Then update the callers (`run_daily_tasks`, `process_one`) to invoke these by name instead of the inline heredocs.

- [ ] **Step 3: Add `should_run_daily_tasks` as an explicit function** (currently the cadence check is a one-liner inside the main loop — easier to test if it's a named function):

```bash
# Returns 0 (true) iff NOW - LAST_DAILY_RUN >= DAILY_INTERVAL.
# Reads LAST_DAILY_RUN and DAILY_INTERVAL from the environment.
should_run_daily_tasks() {
    local now
    now=$(date +%s)
    (( now - LAST_DAILY_RUN >= DAILY_INTERVAL ))
}
```

Update the main loop to use it.

- [ ] **Step 4: Verify byte-equivalence**

In a temporary docker compose stack (or just locally against a mock endpoint):

```bash
bash -n scripts/cdcf_queue_worker.sh && echo OK
bash -n scripts/cdcf_queue_worker.lib.sh && echo OK
```

Then run the worker against a known-empty queue and confirm the output matches the pre-refactor version. If shellcheck is installed:

```bash
shellcheck scripts/cdcf_queue_worker.sh scripts/cdcf_queue_worker.lib.sh
```

Expect no errors.

- [ ] **Step 5: Commit**

```bash
git add scripts/cdcf_queue_worker.sh scripts/cdcf_queue_worker.lib.sh
git commit -m "$(cat <<'EOF'
refactor(worker): extract helpers into cdcf_queue_worker.lib.sh

Pure-mechanical extraction — no behaviour change. Every function moves
verbatim into a sibling lib file the main script sources at startup.
Two new pure helpers (parse_processed, parse_domain_count) replace
inline python3 -c '...' heredocs that the callers previously embedded.
A new should_run_daily_tasks function replaces the inline cadence
check in the main loop.

Sets up the helpers for unit testing with bats-core in scripts/tests/.

Refs #63.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Vendor bats-core as a submodule

**Files:**

- Add submodule: `scripts/tests/bats/` from `https://github.com/bats-core/bats-core.git`

- [ ] **Step 1: Add the submodule**

```bash
mkdir -p scripts/tests
git submodule add --depth 1 https://github.com/bats-core/bats-core.git scripts/tests/bats
cd scripts/tests/bats
git checkout v1.11.0   # or the latest tagged release at plan execution time
cd ../../..
```

- [ ] **Step 2: Verify the binary runs**

```bash
scripts/tests/bats/bin/bats --version
```

Expect `Bats 1.11.0` (or whatever was pinned).

- [ ] **Step 3: Commit**

```bash
git add .gitmodules scripts/tests/bats
git commit -m "test(worker): vendor bats-core v1.11.0 as submodule (Refs #63)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Build the PATH-injected shims

**Files:**

- Create: `scripts/tests/helpers/shims/redis-cli`
- Create: `scripts/tests/helpers/shims/curl`

Each shim is an executable bash script that reads per-test env vars and writes the configured response.

- [ ] **Step 1: `scripts/tests/helpers/shims/redis-cli`**

```bash
#!/bin/bash
# Test shim for redis-cli.
#
# Reads SHIM_REDIS_OUTPUT (string to print on stdout) and SHIM_REDIS_EXIT
# (integer exit code) from the environment. Defaults: empty output, exit 0.
#
# If SHIM_REDIS_OUTPUTS is set instead, it is a newline-separated list and
# each invocation pops the first line — letting one test exercise multiple
# redis-cli calls with different responses.
echo -n "${SHIM_REDIS_OUTPUT:-}"
exit "${SHIM_REDIS_EXIT:-0}"
```

`chmod 755` it.

- [ ] **Step 2: `scripts/tests/helpers/shims/curl`**

```bash
#!/bin/bash
# Test shim for curl.
#
# Reads SHIM_CURL_BODY (response body to print) and SHIM_CURL_HTTP_CODE
# (HTTP status to append on its own line when -w '\n%{http_code}' is
# passed). Defaults: empty body, 200.
#
# The shim only supports the call shape the worker uses:
#   curl -sS -w '\n%{http_code}' ... -X POST ... -d ...

# Drop everything to /dev/null; we just need to produce the canned output.
echo "${SHIM_CURL_BODY:-}"
echo "${SHIM_CURL_HTTP_CODE:-200}"
exit "${SHIM_CURL_EXIT:-0}"
```

`chmod 755` it.

- [ ] **Step 3: Commit**

```bash
git add scripts/tests/helpers/shims/
git commit -m "test(worker): redis-cli + curl shims for bats tests (Refs #63)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: in_maintenance.bats

**Files:**

- Create: `scripts/tests/in_maintenance.bats`

Cases:

| #   | SHIM_REDIS_OUTPUT | SHIM_REDIS_EXIT | Expected                   | Reason                                  |
| --- | ----------------- | --------------- | -------------------------- | --------------------------------------- |
| 1   | `1`               | 0               | function returns 0 (true)  | key present                             |
| 2   | `0`               | 0               | function returns 1 (false) | key absent                              |
| 3   | (any)             | 1               | function returns 1 (false) | redis-cli failed → "not in maintenance" |

- [ ] **Step 1: Write the bats file**

```bash
#!/usr/bin/env bats

setup_file() {
    PATH="$BATS_TEST_DIRNAME/helpers/shims:$PATH"
    export PATH
    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

@test "in_maintenance: returns 0 when redis-cli reports EXISTS=1" {
    SHIM_REDIS_OUTPUT=1 SHIM_REDIS_EXIT=0 run in_maintenance
    [ "$status" -eq 0 ]
}

@test "in_maintenance: returns 1 when redis-cli reports EXISTS=0" {
    SHIM_REDIS_OUTPUT=0 SHIM_REDIS_EXIT=0 run in_maintenance
    [ "$status" -eq 1 ]
}

@test "in_maintenance: returns 1 when redis-cli fails (Redis-down means not paused)" {
    SHIM_REDIS_OUTPUT= SHIM_REDIS_EXIT=1 run in_maintenance
    [ "$status" -eq 1 ]
}
```

- [ ] **Step 2: Run**

```bash
scripts/tests/bats/bin/bats scripts/tests/in_maintenance.bats
```

Expect 3/3 green.

- [ ] **Step 3: Commit** — same pattern as previous commits.

---

## Task 6: queue_is_empty.bats

Cases:

| #   | Setup                      | Expected                                |
| --- | -------------------------- | --------------------------------------- |
| 1   | ZCARD=0, ZCOUNT=0          | returns 0 (empty)                       |
| 2   | ZCARD=3, ZCOUNT=0          | returns 1 (work)                        |
| 3   | ZCARD=0, ZCOUNT=2          | returns 1 (delayed work ready)          |
| 4   | first redis-cli call fails | returns 1 (safe fall-back to HTTP poll) |

The shim's `SHIM_REDIS_OUTPUTS` mode (newline-separated, one per call) is needed here since `queue_is_empty` calls `redis-cli` twice. Extend the shim if needed to consume the list.

- [ ] Extend `scripts/tests/helpers/shims/redis-cli` to support a state file:

```bash
#!/bin/bash
if [ -n "${SHIM_REDIS_OUTPUTS:-}" ]; then
    # Pop the first line from a state file unique to this test invocation.
    state="${SHIM_REDIS_STATE:-/tmp/shim-redis-state.$$}"
    if [ ! -f "$state" ]; then
        printf '%s\n' "$SHIM_REDIS_OUTPUTS" > "$state"
    fi
    line=$(head -n1 "$state")
    tail -n +2 "$state" > "$state.tmp" && mv "$state.tmp" "$state"
    printf '%s' "$line"
    exit 0
fi
echo -n "${SHIM_REDIS_OUTPUT:-}"
exit "${SHIM_REDIS_EXIT:-0}"
```

Tests using the multi-call mode set `SHIM_REDIS_STATE=$BATS_TEST_TMPDIR/redis-state` and a fresh `SHIM_REDIS_OUTPUTS` per case.

- [ ] **Write + run + commit** the bats file (one case per `@test`).

---

## Task 7: parse_processed.bats

Cases:

| #   | Input on stdin                    | Expected stdout |
| --- | --------------------------------- | --------------- |
| 1   | `{"processed": 5}`                | `5`             |
| 2   | `{"processed": {"processed": 3}}` | `3`             |
| 3   | `{}` (no processed key)           | `0`             |
| 4   | `not json`                        | `error`         |
| 5   | empty input                       | `error`         |

- [ ] **Write + run + commit.**

Note: don't shim `python3` for these tests — the real JSON parser is the unit under test (indirectly).

---

## Task 8: should_run_daily_tasks.bats

Cases:

| #   | LAST_DAILY_RUN                     | DAILY_INTERVAL | Expected                    |
| --- | ---------------------------------- | -------------- | --------------------------- |
| 1   | 0 (never run)                      | 86400          | returns 0 (true)            |
| 2   | (now - 1 second)                   | 86400          | returns 1 (false) — not yet |
| 3   | (now - 86400) — exact boundary     | 86400          | returns 0 (true)            |
| 4   | LAST_DAILY_RUN=0, DAILY_INTERVAL=1 | (override)     | returns 0                   |

- [ ] **Write + run + commit.** Use `printf -v NOW '%(%s)T' -1` in the test to compute "now" for the timestamps.

---

## Task 9: process_one.bats

Cases:

| #   | SHIM_CURL_HTTP_CODE | SHIM_CURL_BODY                   | Expected log line                                       |
| --- | ------------------- | -------------------------------- | ------------------------------------------------------- |
| 1   | 200                 | `{"processed": 5}`               | `Processed 5 job(s)`                                    |
| 2   | 500                 | `<html><body>boom</body></html>` | `WARNING: HTTP 500: boom` (HTML stripped)               |
| 3   | 000                 | (empty)                          | `WARNING: request failed (connection error or timeout)` |
| 4   | 200                 | `not json`                       | `WARNING: invalid JSON response: not json`              |

Use `run --separate-stderr process_one` and assert against `$output` for the log line presence.

- [ ] **Write + run + commit.**

---

## Task 10: CI workflow

**Files:**

- Create: `.github/workflows/test-worker.yml`

- [ ] **Step 1: Write the workflow**

```yaml
name: bats (queue worker)

permissions:
  contents: read

on:
  pull_request:
    branches: [main]
    paths:
      - "scripts/cdcf_queue_worker*"
      - "scripts/tests/**"
      - ".github/workflows/test-worker.yml"
  push:
    branches: [main]
    paths:
      - "scripts/cdcf_queue_worker*"
      - "scripts/tests/**"
      - ".github/workflows/test-worker.yml"

concurrency:
  group: test-worker-${{ github.event.pull_request.number || github.ref }}
  cancel-in-progress: true

jobs:
  bats:
    runs-on: ubuntu-latest
    continue-on-error: true
    steps:
      - uses: actions/checkout@<pinned-sha>
        with:
          submodules: recursive
      - name: Verify python3 + redis-cli not needed (shims cover both)
        run: |
          which python3 || (echo "::error::python3 missing — parse_processed tests need it" && exit 1)
      - name: Run bats
        run: scripts/tests/bats/bin/bats scripts/tests/
```

Pin the action SHA at plan-execution time.

- [ ] **Step 2: Commit**

---

## Task 11: README + AGENTS.md updates

**Files:**

- Create: `scripts/tests/README.md`
- Modify: `AGENTS.md`

- [ ] **Step 1: `scripts/tests/README.md`**

Short doc covering:

- How to run: `scripts/tests/bats/bin/bats scripts/tests/`
- The shim convention: PATH-injected fakes in `helpers/shims/`, configured per test via `SHIM_*` env vars
- Adding a new test: source the lib in `setup_file`, write `@test` blocks
- Updating bats-core: `cd scripts/tests/bats && git checkout v<new-tag> && cd ../../.. && git add scripts/tests/bats && git commit`

- [ ] **Step 2: AGENTS.md "Build & Development Commands"**

```bash
scripts/tests/bats/bin/bats scripts/tests/   # Queue-worker bash unit tests
```

Plus a one-line note that the bats-core runner is vendored as a git submodule (so `git clone --recurse-submodules` is needed for fresh checkouts).

- [ ] **Step 3: Commit.**

---

## Task 12: Push + open PR

```bash
git push -u origin feat/bats-worker-suite

gh pr create --title "feat(test): bats-core suite for cdcf_queue_worker.sh (Refs #63)" --body "$(cat <<'EOF'
Third of three planned test suites for #63 (following the Vitest pilot in #86 and parallel to the PHPUnit plan).

## Summary

- bats-core vendored as a git submodule at \`scripts/tests/bats/\`.
- Mechanical refactor: helpers extracted from cdcf_queue_worker.sh into a sibling cdcf_queue_worker.lib.sh that the main script sources. No runtime behaviour change.
- Five test files covering 20 cases:
  - in_maintenance.bats (3) — present / absent / redis-down fallback
  - queue_is_empty.bats (4) — empty / immediate / delayed / redis-down
  - parse_processed.bats (5) — happy + nested + missing + invalid + empty
  - should_run_daily_tasks.bats (4) — cadence boundaries
  - process_one.bats (4) — 200 / 500-with-HTML / connection-error / invalid-JSON
- PATH-injected shims for \`redis-cli\` and \`curl\` configured per test via \`SHIM_*\` env vars; real \`python3\` for the JSON parsing tests.
- Non-blocking CI (\`continue-on-error: true\`) gated on changes to scripts/cdcf_queue_worker* or scripts/tests/.

## Verification

- \`scripts/tests/bats/bin/bats scripts/tests/\` exits 0 locally; 20/20 green.
- Byte-identical worker output before vs after the refactor on a representative cycle.
- \`bash -n\` and (if installed) \`shellcheck\` are clean.

## Post-merge step (manual)

Once merged, the operator copies the updated worker script + lib file to the VPS:

\`\`\`bash
scp scripts/cdcf_queue_worker.sh scripts/cdcf_queue_worker.lib.sh ubuntu@catholicdigitalcommons.org:/tmp/
ssh ubuntu@catholicdigitalcommons.org "sudo cp /tmp/cdcf_queue_worker.{sh,lib.sh} /usr/local/bin/ && sudo systemctl restart cdcf-queue-worker"
\`\`\`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 1: Wait for CI + CodeRabbit, address feedback, merge with \`gh pr merge --merge --delete-branch\`.**

---

## Post-merge verification

After this lands and the operator copies the script onto the VPS:

1. `sudo systemctl is-active cdcf-queue-worker` → `active`
2. `journalctl -u cdcf-queue-worker -f` for a few minutes — output should be identical in shape to before (silent on empty queue, periodic transition lines on maintenance flips).
3. The next production deploy still pauses the worker and resumes correctly (covered by the maintenance flag mechanism, not by these tests, but worth verifying the refactor didn't break that interaction).
