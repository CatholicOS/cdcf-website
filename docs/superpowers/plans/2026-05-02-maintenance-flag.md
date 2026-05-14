# Maintenance Flag Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pause the `cdcf-queue-worker` for the duration of a production deploy by setting a TTL'd Redis key via a new authenticated WP REST endpoint, and have the worker check the key before each poll cycle.

**Architecture:** Three components — a new `POST /cdcf/v1/maintenance` endpoint in the `cdcf-redis-translations` plugin (sets/clears `cdcf:maintenance:until` in Redis with `[60, 600]`-clamped TTL), an `in_maintenance()` helper in the worker bash script that does `redis-cli EXISTS` at the top of each loop, and two new production-only steps in the deploy workflow that call begin/end around the WP-stress block. Plus a Python CLI subcommand and docs updates.

**Tech Stack:** PHP (WordPress plugin), Bash (systemd worker + GitHub Actions), Python (CLI client), Redis (loopback-only, protected-mode), curl with Basic auth via WP Application Password.

**Spec reference:** `docs/superpowers/specs/2026-05-02-maintenance-flag-design.md` (untracked, local only).

**Issue:** #62. Related: #41 (root-cause), #61 (idempotent activation), #63 (test suites follow-up).

**Important project context:**

- No test runner is configured (per `CLAUDE.md`). Verification in this plan is manual: curl + `redis-cli` + `journalctl` checks. A test-suite follow-up is tracked separately as #63.
- The deploy SSH user is chrooted on the VPS — it cannot run `redis-cli` directly. All maintenance-flag operations from the deploy workflow must go through HTTP to the WP REST endpoint.
- Redis on the production VPS is loopback-only with `protected-mode yes`. No extra env vars needed by the plugin or the worker.

---

## File Structure

| File                                                                    | Action | Responsibility                                                                                                                                                                |
| ----------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php` | Modify | Register `POST /cdcf/v1/maintenance` route alongside existing `/process-queue` route                                                                                          |
| `scripts/cdcf_queue_worker.sh`                                          | Modify | Add `in_maintenance()` helper; wrap main loop with state-tracked maintenance check                                                                                            |
| `.github/workflows/deploy.yml`                                          | Modify | Add "Pause queue worker for deploy" step before `Extract WP theme and plugin bundles`, "Resume queue worker after deploy" step (with `if: always()`) after `Activate plugins` |
| `scripts/cdcf_api.py`                                                   | Modify | Add `CdcfClient.maintenance()` method; add `maintenance` subparser; add dispatch case in `_run_cli`                                                                           |
| `docs/redis-queue-worker.md`                                            | Modify | Add "Maintenance mode" section explaining flag, manual control, expected logs, deploy-time verification                                                                       |
| `CLAUDE.md`                                                             | Modify | Add `POST /cdcf/v1/maintenance` row to REST endpoints table; document new `maintenance` CLI subcommand                                                                        |

No new files are created — the new endpoint goes in the existing plugin file (kept small at <100 lines after the change), following the same convention as the existing `/process-queue` route.

---

## Task 1: Create feature branch

**Files:** none

- [ ] **Step 1: Create and check out branch**

```bash
git checkout main
git pull --ff-only
git checkout -b feat/maintenance-flag
```

- [ ] **Step 2: Verify clean starting state**

```bash
git status
```

Expected: "On branch feat/maintenance-flag", "nothing to commit, working tree clean" (the spec at `docs/superpowers/specs/2026-05-02-maintenance-flag-design.md` may show as untracked — that's fine, leave it untracked).

---

## Task 2: Add `POST /cdcf/v1/maintenance` endpoint

**Files:**

- Modify: `wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php` (append new `register_rest_route` call inside the existing `rest_api_init` callback)

- [ ] **Step 1: Add the route registration**

Read the file first to locate the existing `rest_api_init` `add_action` block (around line 22). Inside that callback, after the existing `register_rest_route('cdcf/v1', '/process-queue', [...])` block, add:

```php
register_rest_route('cdcf/v1', '/maintenance', [
    'methods'             => 'POST',
    'permission_callback' => function () {
        return current_user_can('manage_options');
    },
    'callback' => function (WP_REST_Request $request) {
        $action = $request['action'] ?? '';
        if ($action !== 'begin' && $action !== 'end') {
            return new WP_Error(
                'invalid_action',
                "action must be 'begin' or 'end'",
                ['status' => 400]
            );
        }

        if (!class_exists('Redis')) {
            return new WP_Error(
                'redis_unavailable',
                'PHP Redis extension not installed',
                ['status' => 500]
            );
        }

        try {
            $redis = new Redis();
            if (!$redis->connect('127.0.0.1', 6379, 1.0)) {
                return new WP_Error(
                    'redis_unavailable',
                    'Could not connect to Redis at 127.0.0.1:6379',
                    ['status' => 500]
                );
            }
        } catch (\Throwable $e) {
            return new WP_Error('redis_unavailable', $e->getMessage(), ['status' => 500]);
        }

        if ($action === 'end') {
            $redis->del('cdcf:maintenance:until');
            return new WP_REST_Response(['ok' => true], 200);
        }

        // action === 'begin'
        $duration = intval($request['duration_seconds'] ?? 300);
        $duration = max(60, min(600, $duration));
        $redis->setex('cdcf:maintenance:until', $duration, '1');
        return new WP_REST_Response([
            'ok'       => true,
            'until'    => time() + $duration,
            'duration' => $duration,
        ], 200);
    },
    'args' => [
        'action' => [
            'required' => true,
            'type'     => 'string',
        ],
        'duration_seconds' => [
            'required'         => false,
            'type'             => 'integer',
            'default'          => 300,
            'sanitize_callback' => 'absint',
        ],
    ],
]);
```

- [ ] **Step 2: Verify the file parses (PHP lint)**

Run: `docker compose exec wordpress php -l /var/www/html/wp-content/plugins/cdcf-redis-translations/cdcf-redis-translations.php` if Docker Compose is up. If not running locally, skip — the next step's curl will catch a parse error.

Expected: `No syntax errors detected in ...`

- [ ] **Step 3: Verify endpoint manually (requires docker compose up OR deployed environment)**

```bash
# Use the Python CLI (preferred — handles auth automatically)
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"begin","duration_seconds":300}'
# Expected JSON: {"ok": true, "until": <unix timestamp>, "duration": 300}

# Confirm Redis state (run on the host that has redis-cli available)
redis-cli TTL cdcf:maintenance:until
# Expected: integer between 295 and 300

# Test clamping (low)
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"begin","duration_seconds":1}'
# Expected: "duration": 60

# Test clamping (high)
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"begin","duration_seconds":99999}'
# Expected: "duration": 600

# End
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"end"}'
# Expected: {"ok": true}
redis-cli EXISTS cdcf:maintenance:until
# Expected: (integer) 0

# Idempotent end
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"end"}'
# Expected: {"ok": true}

# Bad action
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/maintenance --data '{"action":"foo"}'
# Expected: HTTPError 400 with body {"code":"invalid_action", ...}
```

If a local environment with Redis isn't available, defer the manual verification to the post-merge production deploy and note this in the PR description.

- [ ] **Step 4: Commit**

```bash
git add wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php
git commit -m "$(cat <<'EOF'
feat(plugin): add POST /cdcf/v1/maintenance endpoint

Sets/clears cdcf:maintenance:until in Redis with a TTL clamped to
[60, 600] seconds. The cdcf-queue-worker will check this key at the
top of each poll cycle to pause processing during deploys (#62).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Worker — add `in_maintenance()` and wrap the main loop

**Files:**

- Modify: `scripts/cdcf_queue_worker.sh` (add helper above the main `while true` loop; wrap loop body with state-tracked check)

- [ ] **Step 1: Add `in_maintenance()` helper above the main loop**

Insert this function after the existing `run_daily_tasks` function definition and before the `while true; do` loop:

```bash
# ─── Maintenance flag check ──────────────────────────────────────────
# Returns 0 (true) if the maintenance flag is set in Redis, 1 (false)
# otherwise. Redis-unreachable counts as "not in maintenance" so a
# Redis outage does not stall the worker indefinitely.
in_maintenance() {
    local result
    result=$(redis-cli -h 127.0.0.1 -p 6379 -n 0 EXISTS cdcf:maintenance:until 2>/dev/null) || return 1
    [ "$result" = "1" ]
}
```

- [ ] **Step 2: Wrap the main loop body with maintenance check + transition logging**

Replace the existing main loop (currently around lines 190-204):

```bash
while true; do
    run_daily_tasks

    if [ "$CONCURRENCY" -le 1 ]; then
        process_one
    else
        # Fire N parallel workers and wait for all to finish.
        for i in $(seq 1 "$CONCURRENCY"); do
            process_one &
        done
        wait
    fi

    sleep "${POLL_INTERVAL}"
done
```

with:

```bash
IN_MAINTENANCE=0
while true; do
    if in_maintenance; then
        if [ "$IN_MAINTENANCE" = "0" ]; then
            echo "$(date -Iseconds) Entering maintenance mode (worker paused)"
            IN_MAINTENANCE=1
        fi
        sleep "${POLL_INTERVAL}"
        continue
    fi

    if [ "$IN_MAINTENANCE" = "1" ]; then
        echo "$(date -Iseconds) Exiting maintenance mode (worker resumed)"
        IN_MAINTENANCE=0
    fi

    run_daily_tasks

    if [ "$CONCURRENCY" -le 1 ]; then
        process_one
    else
        # Fire N parallel workers and wait for all to finish.
        for i in $(seq 1 "$CONCURRENCY"); do
            process_one &
        done
        wait
    fi

    sleep "${POLL_INTERVAL}"
done
```

Note: `run_daily_tasks` is intentionally inside the "not in maintenance" branch so daily WP REST calls are skipped during a deploy too.

- [ ] **Step 3: Shellcheck**

Run: `shellcheck scripts/cdcf_queue_worker.sh`
Expected: no errors. If `shellcheck` isn't installed locally, skip.

- [ ] **Step 4: Verify with bash syntax check**

Run: `bash -n scripts/cdcf_queue_worker.sh`
Expected: exits 0, no output.

- [ ] **Step 5: Verify on the VPS (post-merge, after operator copies script)**

This step requires the updated worker to be installed on the VPS by an operator after the PR merges:

```bash
sudo cp scripts/cdcf_queue_worker.sh /usr/local/bin/cdcf_queue_worker.sh
sudo systemctl restart cdcf-queue-worker
```

Then:

```bash
# Tail logs in one terminal
journalctl -u cdcf-queue-worker -f

# In another terminal: simulate maintenance
redis-cli SETEX cdcf:maintenance:until 60 1
# Within POLL_INTERVAL (~15s): expect ONE "Entering maintenance mode" line, then silence

# Re-set while paused — should NOT re-log
redis-cli SETEX cdcf:maintenance:until 60 1
# No new log lines

# End it
redis-cli DEL cdcf:maintenance:until
# Within POLL_INTERVAL: expect ONE "Exiting maintenance mode" line, then resumed processing logs
```

- [ ] **Step 6: Commit**

```bash
git add scripts/cdcf_queue_worker.sh
git commit -m "$(cat <<'EOF'
feat(worker): pause processing while cdcf:maintenance:until is set

Adds an in_maintenance() helper that checks Redis at the top of each
poll cycle. Logs a single transition line on entry and exit, not per
cycle. Treats redis-cli failures as "not in maintenance" so a Redis
outage doesn't stall the worker (#62).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Deploy workflow — add begin/end steps

**Files:**

- Modify: `.github/workflows/deploy.yml`

The new "Pause" step goes immediately before the existing `- name: Extract WP theme and plugin bundles` step (currently around line 298). The new "Resume" step goes immediately after the existing `- name: Activate plugins` step (currently the last step in the file).

- [ ] **Step 1: Add the "Pause queue worker for deploy" step**

Insert this step **immediately before** the `- name: Extract WP theme and plugin bundles` block:

```yaml
- name: Pause queue worker for deploy
  if: env.ENVIRONMENT == 'production'
  # When staging gets its own WP backend, also gate this step on
  # env.ENVIRONMENT == 'staging' and route to the staging WP REST URL.
  # Today staging shares the production backend, so only production
  # deploys touch FPM hard enough to need the pause.
  env:
    WP_REST_URL: ${{ vars.WP_REST_URL }}
    WP_APP_USERNAME: ${{ secrets.WP_APP_USERNAME }}
    WP_APP_PASSWORD: ${{ secrets.WP_APP_PASSWORD }}
  run: |
    AUTH=$(echo -n "$WP_APP_USERNAME:$WP_APP_PASSWORD" | base64)
    for attempt in 1 2 3; do
      STATUS=$(curl -sS -o /tmp/maint-begin.json -w '%{http_code}' \
        --connect-timeout 10 --max-time 30 \
        -X POST "$WP_REST_URL/cdcf/v1/maintenance" \
        -H "Authorization: Basic $AUTH" \
        -H "Content-Type: application/json" \
        -d '{"action":"begin","duration_seconds":300}')
      if [ "$STATUS" = "200" ]; then
        echo "Worker paused: $(cat /tmp/maint-begin.json)"
        exit 0
      fi
      echo "Maintenance begin attempt $attempt failed (HTTP $STATUS)"
      [ "$attempt" -lt 3 ] && sleep 5
    done
    echo "::error::Failed to pause queue worker; aborting deploy."
    exit 1
```

- [ ] **Step 2: Add the "Resume queue worker after deploy" step**

Append this step **after** the existing `- name: Activate plugins` block (it must be the last step in the job, since `if: always()` should fire even if earlier steps failed):

```yaml
- name: Resume queue worker after deploy
  if: always() && env.ENVIRONMENT == 'production'
  env:
    WP_REST_URL: ${{ vars.WP_REST_URL }}
    WP_APP_USERNAME: ${{ secrets.WP_APP_USERNAME }}
    WP_APP_PASSWORD: ${{ secrets.WP_APP_PASSWORD }}
  run: |
    AUTH=$(echo -n "$WP_APP_USERNAME:$WP_APP_PASSWORD" | base64)
    for attempt in 1 2 3; do
      STATUS=$(curl -sS -o /dev/null -w '%{http_code}' \
        --connect-timeout 10 --max-time 30 \
        -X POST "$WP_REST_URL/cdcf/v1/maintenance" \
        -H "Authorization: Basic $AUTH" \
        -H "Content-Type: application/json" \
        -d '{"action":"end"}')
      if [ "$STATUS" = "200" ]; then
        echo "Worker resumed."
        exit 0
      fi
      echo "Maintenance end attempt $attempt failed (HTTP $STATUS)"
      [ "$attempt" -lt 3 ] && sleep 5
    done
    # Don't exit 1 here — the TTL self-heals within 600s and failing
    # this step would mask the real outcome of the deploy.
    echo "::warning::Failed to resume queue worker; will self-heal via TTL within 600s."
```

- [ ] **Step 3: Lint the workflow file**

Run: `actionlint .github/workflows/deploy.yml` if `actionlint` is installed. Otherwise, eyeball the YAML indentation matches the surrounding steps (six-space indent for step keys: `- name:`, `if:`, `env:`, `run:`).

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "$(cat <<'EOF'
ci(deploy): pause queue worker around production WP-touching steps

Calls POST /cdcf/v1/maintenance{begin,300} before tarball extraction
and {end} after plugin activation (with if: always()). If begin fails,
abort the deploy — the whole point is to mitigate FPM stress, so
deploying without the pause defeats the purpose. End failures emit a
warning; the Redis TTL self-heals within 600s (#62).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Python CLI — `maintenance` subcommand

**Files:**

- Modify: `scripts/cdcf_api.py` (add client method, subparser, dispatch case)

- [ ] **Step 1: Add `maintenance()` method to `CdcfClient`**

Locate the existing client methods. Insert the following method near the other `cdcf/v1` methods (e.g. just after `update_relationship` around line 210, or before `revalidate` around line 345 — pick wherever fits the existing logical grouping):

```python
    def maintenance(self, action: str, duration_seconds: int = 300) -> dict:
        """Set or clear the deploy-time maintenance flag.

        action: 'begin' (sets cdcf:maintenance:until in Redis with a
                clamped TTL) or 'end' (deletes the key).
        duration_seconds: only used for 'begin'. Server clamps to [60, 600].

        Returns the endpoint response dict.
        """
        if action not in ("begin", "end"):
            raise ValueError(f"action must be 'begin' or 'end', got {action!r}")
        payload: dict = {"action": action}
        if action == "begin":
            payload["duration_seconds"] = int(duration_seconds)
        return self._wp_post("cdcf/v1/maintenance", payload)
```

- [ ] **Step 2: Add the `maintenance` subparser**

In `_build_parser()` (around line 368 onward), add a subparser registration. Insert it near the other `cdcf/v1` subparsers — for example, after the `revalidate` subparser (around line 471):

```python
    p = sub.add_parser("maintenance",
                       help="Pause/resume the cdcf-queue-worker via Redis flag")
    p.add_argument("--action", required=True, choices=["begin", "end"])
    p.add_argument("--duration", type=int, default=300,
                   help="Seconds to pause for (clamped server-side to 60-600). "
                        "Only used with --action begin. Default: 300")
```

- [ ] **Step 3: Add dispatch case in `_run_cli`**

In the `_run_cli` function, add an `if cmd == "maintenance"` branch alongside the others (e.g. after the `revalidate` branch):

```python
    if cmd == "maintenance":
        return client.maintenance(args.action, args.duration)
```

- [ ] **Step 4: Verify the CLI parses**

```bash
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --help
```

Expected: argparse usage block showing `--action` and `--duration`.

- [ ] **Step 5: Verify against a live endpoint (requires Task 2 deployed)**

```bash
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action begin --duration 120
# Expected: {"ok": true, "until": <ts>, "duration": 120}

scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action end
# Expected: {"ok": true}
```

If no deployed environment is available, skip the live-endpoint checks; argparse `--help` output is enough confirmation that the wiring compiles.

- [ ] **Step 6: Commit**

```bash
git add scripts/cdcf_api.py
git commit -m "$(cat <<'EOF'
feat(cli): add maintenance subcommand to cdcf_api.py

Wraps POST /cdcf/v1/maintenance for ergonomic admin use:
  scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action begin --duration 300
  scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action end

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Documentation updates

**Files:**

- Modify: `docs/redis-queue-worker.md` (add "Maintenance mode" section)
- Modify: `CLAUDE.md` (add REST table row + CLI subcommand example)

- [ ] **Step 1: Add "Maintenance mode" section to `docs/redis-queue-worker.md`**

Insert this section between the existing "Updating the worker script" and "Troubleshooting" sections:

````markdown
## Maintenance mode

The worker can be paused via a Redis flag. While the flag is set, the worker skips both `process_one` and `run_daily_tasks` and just sleeps `POLL_INTERVAL` seconds per cycle. This is used by the production deploy workflow to prevent the worker's parallel POSTs from competing with deploy-time WP traffic for FPM workers.

### Setting and clearing the flag

Via the WP REST API (the way the deploy workflow does it):

```bash
# Pause for 300 seconds
curl -u "$WP_APP_USERNAME:$WP_APP_PASSWORD" \
  -X POST "$WP_REST_URL/cdcf/v1/maintenance" \
  -H "Content-Type: application/json" \
  -d '{"action":"begin","duration_seconds":300}'

# Resume
curl -u "$WP_APP_USERNAME:$WP_APP_PASSWORD" \
  -X POST "$WP_REST_URL/cdcf/v1/maintenance" \
  -H "Content-Type: application/json" \
  -d '{"action":"end"}'
```
````

Or via the Python CLI:

```bash
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action begin --duration 300
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action end
```

Or directly via `redis-cli` on the VPS (operator-only):

```bash
redis-cli SETEX cdcf:maintenance:until 300 1
redis-cli DEL cdcf:maintenance:until
```

The TTL is server-clamped to `[60, 600]` seconds. If `end` is never called, the flag self-expires within ≤600 seconds.

### Expected log output

The worker logs exactly one line per transition, never per cycle:

```
2026-05-02T12:00:00+00:00 Entering maintenance mode (worker paused)
2026-05-02T12:02:30+00:00 Exiting maintenance mode (worker resumed)
2026-05-02T12:02:45+00:00 Processed 3 job(s)
```

### Verifying during a deploy

```bash
journalctl -u cdcf-queue-worker -f
# Expect one "Entering" then one "Exiting" line bracketing the deploy.
# Compare 504 counts in the WP access log before/after the deploy day
# to baseline (~10/day; bad days hit 200+).
```

````

- [ ] **Step 2: Update `CLAUDE.md` REST endpoints table**

Find the table starting with `| Method | Route | Description |`. Add this row (alphabetical-ish placement is fine — between `/local-group` and `/relationship` works):

```markdown
| `POST` | `/maintenance` | Pause or resume the cdcf-queue-worker by setting/clearing a Redis flag (`{action: "begin"|"end", duration_seconds?: 60-600}`) |
````

- [ ] **Step 3: Add CLI example to `CLAUDE.md`**

Find the "Python API Client" section's CLI Usage block. Add an example under the "REST API calls" or near the `revalidate` example:

```bash
# Pause/resume the queue worker (used by the deploy workflow)
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action begin --duration 300
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action end
```

- [ ] **Step 4: Commit**

```bash
git add docs/redis-queue-worker.md CLAUDE.md
git commit -m "$(cat <<'EOF'
docs: maintenance mode for the queue worker

Documents the new POST /cdcf/v1/maintenance endpoint, its redis-cli
fallback, the Python CLI subcommand, and the expected worker log
output during a deploy (#62).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Push branch and open PR

**Files:** none (uses `gh`)

- [ ] **Step 1: Push the branch**

```bash
git push -u origin feat/maintenance-flag
```

- [ ] **Step 2: Open the PR**

```bash
gh pr create --title "feat: maintenance flag to pause queue worker during deploys" --body "$(cat <<'EOF'
Closes #62.

## Summary

- Adds `POST /cdcf/v1/maintenance` to `cdcf-redis-translations` plugin — sets/clears `cdcf:maintenance:until` in Redis with TTL clamped to `[60, 600]`.
- Worker checks the flag with `redis-cli EXISTS` at the top of each poll cycle; logs one transition line per pause window.
- Deploy workflow calls `begin` before tarball extraction and `end` after plugin activation (with `if: always()`); production-only.
- Adds `maintenance` subcommand to `scripts/cdcf_api.py`.
- Documents the flag in `docs/redis-queue-worker.md` and `CLAUDE.md`.

Mitigates the FPM saturation that produced the 504 wave during deploys (root cause investigated in #41). Complementary to #61's idempotent activation — neither alone fixes everything; together they remove two of the three concurrent stressors.

## Verification done

- [List which manual checks from the plan have been done; if local Docker Compose was unavailable, note that all live verification will happen on the post-merge production deploy.]

## Verification still needed (post-merge)

- Trigger a manual `workflow_dispatch` deploy with `environment: production` and tail `journalctl -u cdcf-queue-worker -f`. Expect exactly one "Entering" then one "Exiting" line bracketing the deploy.
- Compare 504 count in the WP access log for the deploy day to the Apr 29 baseline (262). Expect a meaningful drop.

## Test coverage

None — no test runner is configured in this project. A follow-up to add Vitest/PHPUnit/bats coverage is tracked as #63.

## Rollback

Revert the PR. The Redis key auto-expires within ≤600s of the last `begin`, so a worker on the old version simply resumes immediately. The endpoint going away is harmless once the workflow no longer calls it.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Confirm PR opened**

The previous command prints the PR URL. Open it in the browser (or `gh pr view`) and confirm:

- All five commits are present (Task 2 plugin endpoint, Task 3 worker, Task 4 workflow, Task 5 CLI, Task 6 docs)
- CI checks start running

- [ ] **Step 4: Address any CodeRabbit / CI feedback**

Wait for CI + CodeRabbit. Address actionable feedback as separate commits on the same branch (per project pattern in #41/#61).

- [ ] **Step 5: Merge**

Once approved and CI green:

```bash
gh pr merge --merge --delete-branch
```

(Per saved memory: this project — and all of the user's projects — use `--merge`, never `--squash` or `--rebase`.)

---

## Post-merge verification (manual)

After the merge lands on main and the next production deploy runs (either automatically via release publish or via `gh workflow run deploy.yml -f environment=production`):

1. **Tail worker journal during the deploy:**

   ```bash
   ssh <user>@<vps-host> "journalctl -u cdcf-queue-worker -f"
   ```

   Expect one "Entering maintenance mode" line and one "Exiting maintenance mode" line, bracketing the deploy. Anything else (no transition lines, multiple sets, "Entering" with no matching "Exiting") indicates a problem.

2. **Compare 504 counts:**

   ```bash
   # Adjust path/grep to match your access log layout
   ssh <user>@<vps-host> "grep ' 504 ' /var/log/nginx/cms.catholicdigitalcommons.org_access.log | wc -l"
   ```

   Compare against Apr 29 baseline of 262. Expect a substantial drop.

3. **Confirm the deploy log shows begin + end:**
   In the GitHub Actions log for the deploy run, the "Pause queue worker for deploy" step should print the `{"ok":true,"until":...,"duration":300}` response, and "Resume queue worker after deploy" should print "Worker resumed."

If any of those don't match expectations, open a follow-up issue with a `journalctl --since "1 hour ago"` capture and the relevant Actions log excerpts.
