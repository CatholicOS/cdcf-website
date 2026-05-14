# Maintenance Flag — Design

**Issue:** #62
**Related:** #41 (root-cause investigation), #61 (idempotent plugin activation), #63 (follow-up: add test suites)
**Status:** Approved, ready for implementation plan

## Problem

Production deploys to `cms.catholicdigitalcommons.org` cause a 504 wave on the WP REST API (262 504s on the Apr 29 deploy day vs ~10/day baseline). Investigation in #41 traced the cause to PHP-FPM pool saturation: tarball extraction triggers OPcache invalidation, plugin activation runs synchronous hooks, and the `cdcf-queue-worker` is _simultaneously_ firing `CONCURRENCY=5` parallel POSTs every `POLL_INTERVAL=15s` that each kick off OpenAI translation jobs lasting seconds.

The combination saturates Plesk's small (5–10 worker) FPM pool, causing nginx `fastcgi_read_timeout` to fire on subsequent requests.

## Goal

Pause the queue worker for the duration of a production deploy so its parallel POSTs don't compete with deploy-time WP traffic, eliminating one of the three concurrent FPM stressors.

## Non-goals

- Fixing OPcache invalidation behaviour (separate concern)
- Increasing the FPM pool size (operational change, not a code change)
- Per-environment maintenance flags or maintenance windows for non-deploy reasons (YAGNI)
- Observability dashboards, GET endpoint for status (defer to later issue if needed)

## Architecture

Three components, each with one job. State lives in a single Redis key with a TTL.

```text
┌────────────────────────┐    POST /cdcf/v1/maintenance       ┌──────────────────────────┐
│ GitHub Actions deploy  │ ──── {action:begin,duration:300} ─▶│ WP plugin                │
│ (.github/workflows/    │                                    │ (cdcf-redis-translations)│
│  deploy.yml)           │ ──── {action:end} ────────────────▶│                          │
└────────────────────────┘                                    │  SETEX/DEL                │
                                                              │  cdcf:maintenance:until   │
                                                              └────────────┬─────────────┘
                                                                           │
                                                              127.0.0.1:6379│ DB 0 (loopback)
                                                                           ▼
                              redis-cli EXISTS cdcf:maintenance:until      ┌──────────────┐
       ┌─────────────────────┐  ──────────────────────────────────────────│ Redis        │
       │ cdcf-queue-worker   │  ◀─────────────────────────────────────────│              │
       │ (systemd, bash)     │                                            └──────────────┘
       └─────────────────────┘
```

Redis on the production VPS is loopback-only with `protected-mode yes`. No extra env vars are needed in either the plugin or the worker.

### Redis key

| Key                      | Type   | Value                                                       | TTL                                                          |
| ------------------------ | ------ | ----------------------------------------------------------- | ------------------------------------------------------------ |
| `cdcf:maintenance:until` | string | `1` (sentinel; value is unused — only key presence matters) | requested duration in seconds, server-clamped to `[60, 600]` |

## Components

### 1. WP plugin endpoint — `POST /cdcf/v1/maintenance`

Lives in `wordpress/plugins/cdcf-redis-translations/cdcf-redis-translations.php` next to the existing `/process-queue` route.

**Auth & gating:**

- `permission_callback`: `current_user_can('manage_options')` — same gate as `/process-queue`
- HTTP Basic via WP Application Password — standard for `cdcf/v1`
- Server-side TTL clamp `[60, 600]` — if credentials leak, worst case is a 10-minute pause per call

**Request body:**

```json
{ "action": "begin", "duration_seconds": 300 } // or "end"
```

**Behavior:**

| `action` | Steps                                                                                                                  | Response                                                                    |
| -------- | ---------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| `begin`  | Clamp `duration_seconds` to `[60, 600]`; open fresh `Redis()` to `127.0.0.1:6379`; `SETEX cdcf:maintenance:until $n 1` | `200 {"ok":true,"until":<unix-ts>,"duration":<n>}`                          |
| `end`    | Open fresh `Redis()`; `DEL cdcf:maintenance:until`                                                                     | `200 {"ok":true}`                                                           |
| (other)  | —                                                                                                                      | `400 {"code":"invalid_action","message":"action must be 'begin' or 'end'"}` |

**Connection:** open a fresh PhpRedis connection inside the endpoint (`new Redis(); $r->connect('127.0.0.1', 6379, 1.0);`). Three lines, no coupling to the redis-queue plugin's internals. The connection cost is sub-millisecond on loopback. If `Redis` extension is not available or `connect()` fails, return `500 {"code":"redis_unavailable","message":...}`.

**Idempotency:** `SETEX` is naturally idempotent (replaces TTL); back-to-back `begin{60}` then `begin{300}` results in a final TTL of 300, not 360. `DEL` of an absent key is a no-op; back-to-back `end` calls are safe.

### 2. Worker check — `scripts/cdcf_queue_worker.sh`

Add an `in_maintenance()` helper and call it at the top of the main loop, before `process_one`:

```bash
in_maintenance() {
    local result
    result=$(redis-cli -h 127.0.0.1 -p 6379 -n 0 EXISTS cdcf:maintenance:until 2>/dev/null) || return 1
    [ "$result" = "1" ]
}
```

Exit-code semantics:

- Key present → stdout `1` → function returns 0 (true → "in maintenance")
- Key absent → stdout `0` → function returns 1 (false → "not in maintenance")
- `redis-cli` non-zero exit (Redis unreachable, etc.) → function returns 1 (false → "not in maintenance")

The "Redis unreachable → not in maintenance" policy is intentional. If Redis is down, the queue itself is dead anyway and the worker's `process-queue` calls will fail through their existing error handling. Pausing the worker on a Redis outage would only mask the real problem.

**Transition logging.** Hold an `IN_MAINTENANCE` bash variable across iterations so transition lines fire exactly once per pause window:

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
    # …existing run_daily_tasks, process_one, parallel fan-out…
    sleep "${POLL_INTERVAL}"
done
```

Daily tasks (`run_daily_tasks`) are also skipped while paused — they hit FPM, so deferring them is consistent with the pause's intent.

### 3. Deploy workflow — `.github/workflows/deploy.yml`

Two new steps, both production-only (gated `if: env.ENVIRONMENT == 'production'` like the existing WP-touching steps):

**Begin step** — placed _before_ "Extract WP theme and plugin bundles" (the first FPM stressor):

```yaml
- name: Pause queue worker for deploy
  if: env.ENVIRONMENT == 'production'
  # When staging gets its own WP backend, also gate this step on env.ENVIRONMENT == 'staging'
  # and update the maintenance flag for that environment too.
  env:
    WP_REST_URL: ${{ vars.WP_REST_URL }}
    WP_APP_USERNAME: ${{ secrets.WP_APP_USERNAME }}
    WP_APP_PASSWORD: ${{ secrets.WP_APP_PASSWORD }}
  run: |
    AUTH=$(echo -n "$WP_APP_USERNAME:$WP_APP_PASSWORD" | base64)
    for attempt in 1 2 3; do
      STATUS=$(curl -sS -o /tmp/maint.json -w '%{http_code}' \
        --connect-timeout 10 --max-time 30 \
        -X POST "$WP_REST_URL/cdcf/v1/maintenance" \
        -H "Authorization: Basic $AUTH" \
        -H "Content-Type: application/json" \
        -d '{"action":"begin","duration_seconds":300}')
      if [ "$STATUS" = "200" ]; then
        cat /tmp/maint.json
        exit 0
      fi
      echo "Maintenance begin attempt $attempt failed (HTTP $STATUS)"
      [ "$attempt" -lt 3 ] && sleep 5
    done
    echo "::error::Failed to pause queue worker; aborting deploy."
    exit 1
```

If we cannot pause the worker, abort the deploy. The whole point of #62 is to mitigate FPM stress; deploying without the pause defeats the purpose.

**End step** — placed _after_ "Activate plugins", with `if: always()` so it fires even if extraction or activation failed:

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
    echo "::warning::Failed to resume queue worker; will self-heal via TTL within 600s."
    # Do NOT exit 1 — the TTL self-heals, and failing here would mask the real deploy outcome.
```

`end` failures emit a warning, not an error. The TTL self-heals within ≤600s; failing the deploy on `end` would mask the real deploy outcome (which the steps before us already determined).

### 4. Python CLI — `scripts/cdcf_api.py`

Add `maintenance` subcommand for ergonomic admin use:

```bash
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action begin --duration 300
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action end
```

Wraps `POST /cdcf/v1/maintenance`. `--duration` defaults to 300 and is only relevant for `begin`. Reuses the existing `_wp_post()` helper and credential loading.

## Failure modes

| #   | Scenario                                 | Behavior                                                          | Notes                                                   |
| --- | ---------------------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------- |
| 1   | `begin` POST fails (3 retries exhausted) | Deploy step exits 1, deploy aborts                                | Pre-empt FPM stress                                     |
| 2   | Deploy job killed mid-flight             | Key TTL expires after ≤300s                                       | Self-heal via TTL                                       |
| 3   | `end` POST fails                         | Log warning, do **not** fail deploy                               | Best-effort; TTL self-heals                             |
| 4   | Redis down when `begin` fires            | Endpoint returns 500, deploy retries then aborts                  | Same as #1                                              |
| 5   | Redis dies mid-deploy                    | Worker treats Redis errors as "not in maintenance" → resumes      | Best-effort; queue is dead anyway                       |
| 6   | Worker mid-call when `begin` fires       | In-flight curl continues; next cycle pauses                       | Acceptable — single in-flight job                       |
| 7   | Multiple `begin` calls                   | Each `SETEX` replaces the prior TTL — no stacking                 | Per design                                              |
| 8   | `end` called with no key set             | `DEL` no-op, endpoint returns 200                                 | Idempotent                                              |
| 9   | Deploy takes >300s                       | Worker resumes mid-deploy, reverts to current FPM-stress for tail | Mitigation: bump default to 600 if observed; not for v1 |
| 10  | Two deploys racing                       | Already prevented by existing `concurrency: deploy-${env}` block  | No new concern                                          |

## Security model

1. **WP Application Password** (Basic auth) — required by `cdcf/v1` baseline
2. **`current_user_can('manage_options')`** — same gate as `/process-queue`
3. **Server-side TTL clamp `[60, 600]`** — leaked credentials buy at most 10 minutes per call
4. **Redis bound to `127.0.0.1`** — no external attack surface against Redis directly

No `X-Maintenance-Secret` shared-secret header. The four layers above are sufficient for an endpoint whose worst-case abuse is a 10-minute pause.

## Verification plan

### Plugin endpoint (manual curl on staging or prod)

| Test                                   | Expected                                   |
| -------------------------------------- | ------------------------------------------ |
| `begin` with `duration_seconds: 300`   | 200, `duration:300`, `redis-cli TTL` ≈ 300 |
| `begin` with `duration_seconds: 1`     | 200, `duration:60` (clamped)               |
| `begin` with `duration_seconds: 99999` | 200, `duration:600` (clamped)              |
| `end`                                  | 200, `redis-cli EXISTS` → 0                |
| `end` again (idempotent)               | 200                                        |
| `{"action":"foo"}`                     | 400                                        |
| Unauthenticated                        | 401                                        |
| Authenticated as non-admin             | 403                                        |

### Worker check (manual on the VPS)

- `redis-cli SETEX cdcf:maintenance:until 60 1` → tail journal → "Entering maintenance mode" within `POLL_INTERVAL`s, then silence
- `redis-cli DEL cdcf:maintenance:until` → "Exiting maintenance mode" within `POLL_INTERVAL`s, then normal "Processed N job(s)" lines resume
- Re-set the key while paused → no duplicate "Entering" line
- `sudo systemctl stop redis-server` briefly while _not_ paused → worker keeps trying `process-queue` (does not spuriously enter maintenance) → `sudo systemctl start redis-server`

### End-to-end deploy

On a real production deploy (`workflow_dispatch` with `environment: production`):

1. Tail `journalctl -u cdcf-queue-worker -f` — expect exactly one "Entering" then one "Exiting" line bracketing the deploy
2. Compare 504 count for the deploy day to the Apr 29 baseline (262); expect a meaningful drop
3. Confirm GitHub Actions log shows the begin response with `until` and `duration:300`

## Rollout & rollback

**Rollout:** single PR. Plugin endpoint, worker change, and workflow change are interlocked — splitting them creates awkward intermediate states (endpoint exists with no caller, or worker checks for a key nothing sets).

**Rollback:** revert the PR. The Redis key auto-expires within 600s of the last `begin`, so a worker reverted to the old version simply resumes immediately. The endpoint disappearing is harmless once the workflow no longer calls it.

## Documentation updates

- `docs/redis-queue-worker.md` — new "Maintenance mode" section: what the flag does, manual set/clear via `redis-cli`, expected log output, deploy-time verification
- `CLAUDE.md` — add `POST /cdcf/v1/maintenance` row to the REST API table; document the new `maintenance` CLI subcommand

## Implementation order (suggested)

1. WP plugin: register `/cdcf/v1/maintenance` with `begin`/`end` actions and TTL clamp
2. Worker: add `in_maintenance()` helper and the transition-state loop wrapper
3. Deploy workflow: add begin step before extraction, end step with `if: always()` after activation
4. Python CLI: add `maintenance` subcommand
5. Docs: update `docs/redis-queue-worker.md` and `CLAUDE.md`
6. Verify end-to-end on a manual `workflow_dispatch` deploy and confirm the 504 rate drops to baseline
