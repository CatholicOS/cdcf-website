# Local Zitadel Stack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a local Zitadel to this repo's Docker Compose stack and make it the default identity provider for local development, so `cdcf-infra` #20 can remove the `http://localhost:3000` client from the production Zitadel.

**Architecture:** Four new Compose services — `zitadel-db` (its own PostgreSQL, because the stack's `db` is MariaDB), `zitadel`, `zitadel-login` (the v2 sign-in UI production also runs) and `zitadel-proxy` (nginx, giving all of it one origin on `${ZITADEL_PORT:-8090}`) — with Zitadel writing two first-instance PATs into a bind-mounted `./.zitadel-data/`: `automation-user.pat` for provisioning and `login-client.pat` for the login UI. A host-run `cdcf-infra/auth/setup-zitadel.sh --target local` reads the former and provisions the CDCF app. No provisioning script is added to this repo.

> **Amendment (post-implementation):** this plan was written for a two-service, Login V1 stack. Login V2 was added afterwards to match production, which turned it into four services and moved the published port from `zitadel` to `zitadel-proxy`. The Global Constraints below are current; the literal compose and bats snippets inside Task 1 and Task 2 are **not** — they predate the change. Read `docs/superpowers/specs/2026-08-17-local-zitadel-stack-design.md` §3.2–§3.3 and the committed `docker-compose.yml` / `scripts/tests/zitadel_compose.bats` for the shipped shape.

**Tech Stack:** Docker Compose, `ghcr.io/zitadel/zitadel:v4.15.0`, `postgres:16-alpine`, bats-core (`scripts/tests/*.bats`), Auth.js v5 (already present).

**Spec:** `docs/superpowers/specs/2026-08-17-local-zitadel-stack-design.md`

## Global Constraints

Copied verbatim from the spec. Every task's requirements implicitly include these.

- Zitadel image is **pinned**: `ghcr.io/zitadel/zitadel:v4.15.0`. Never `:latest` — `cdcf-infra`'s script calls the management API by versioned path.
- `zitadel-db` is `postgres:16-alpine`, a **separate** service. The stack's `db` is `mariadb:11` and cannot host Zitadel.
- Published port: `127.0.0.1:${ZITADEL_PORT:-8090}:8080`. Default is **8090, not 8080** — `martyrology-api` and `LiturgicalCalendarFrontend` both use 8080.
- `ZITADEL_EXTERNALPORT` must equal the published host port, derived from the same `${ZITADEL_PORT:-8090}` expression.
- **One port, four places** (spec §4): `ZITADEL_PORT` is the only knob, but its value is restated in `ZITADEL_EXTERNALPORT`, `cdcf-infra`'s `ZITADEL_ISSUER` / `ZITADEL_INTERNAL_URL`, and `.env.local.example`'s `AUTH_ZITADEL_ISSUER`. Task 1 and Task 2 each add a test pinning an agreement that nothing else validates.
- Both Postgres SSL modes are `disable`: `ZITADEL_DATABASE_POSTGRES_ADMIN_SSL_MODE` and `..._USER_SSL_MODE`.
- `ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED: "true"` — Login V2, matching production. Requires the `zitadel-login` container AND the four `ZITADEL_FIRSTINSTANCE_ORG_LOGINCLIENT_*`/`LOGINCLIENTPATPATH` settings that mint its `login-client.pat`; the flag alone yields a redirect to `/ui/v2/login` that nothing serves. The container is interim — it goes away once cdcf-website implements sign-in natively against the Zitadel APIs.
- `zitadel-proxy` owns the published port; `zitadel` publishes none. In `nginx/zitadel.conf` both `Host` and `X-Forwarded-Host` must be `$http_host` — `$host` drops the port and Auth.js then fails discovery on an issuer mismatch.
- `ZITADEL_FIRSTINSTANCE_PATPATH: /zitadel-data/automation-user.pat`, plus all three `ZITADEL_FIRSTINSTANCE_ORG_MACHINE_*` settings. `PATPATH` alone writes nothing.
- `user: "0"` on the `zitadel` service, or the PAT is unreadable from the host.
- Master key must be **exactly 32 characters** and stable forever; changing it makes existing instance data undecryptable.
- `ZITADEL_DB_PASSWORD` is equally write-once: `POSTGRES_PASSWORD` initialises the role only on an empty volume, so changing it after `zitadel_db_data` exists breaks Zitadel's database auth. Same recovery — remove the volume and re-provision.
- Compose reads `.env`, **not** `.env.local`. `ZITADEL_PORT`, `ZITADEL_MASTERKEY`, `ZITADEL_DB_PASSWORD` all come from `.env` or their defaults.
- `./.zitadel-data/` is already gitignored (`.gitignore:58`). Do not re-add it.

---

### Task 1: Compose services, with a config test that pins the port derivation

**Files:**

- Create: `scripts/tests/zitadel_compose.bats`
- Modify: `docker-compose.yml` (add two services + one volume)
- Modify: `.github/workflows/test-worker.yml:17-26` (add `docker-compose.yml` to both `paths:` lists)
- Delete: `zitadel/` (stray, root-owned)

**Interfaces:**

- Consumes: nothing.
- Produces: Compose services `zitadel-db` and `zitadel`; named volume `zitadel_db_data`; the host-readable PAT at `./.zitadel-data/automation-user.pat`; the published endpoint `http://localhost:${ZITADEL_PORT:-8090}`. Task 2 depends on 8090 being the default.

The test asserts against `docker compose config`, which resolves interpolation without starting containers. It passes `-f docker-compose.yml` explicitly so `docker-compose.override.yml` cannot change the result.

- [ ] **Step 1: Write the failing test**

Create `scripts/tests/zitadel_compose.bats`:

```bash
#!/usr/bin/env bats
#
# Coverage for the local Zitadel services in docker-compose.yml.
#
# These assert the RESOLVED compose config (`docker compose config`), not the
# raw YAML, so ${ZITADEL_PORT:-8090} is evaluated the way Compose evaluates it.
# -f docker-compose.yml is explicit: docker-compose.override.yml is merged by
# default and would otherwise make the result depend on local overrides.
#
# The port cases are the point of this file. ZITADEL_PORT is restated in four
# places across two repos and nothing validates that they agree (spec §4); a
# published port that disagrees with ZITADEL_EXTERNALPORT yields issuer and
# discovery URLs that look right and do not resolve.

setup() {
    cd "$BATS_TEST_DIRNAME/../.." || return 1
    # The cases below invoke this through `run bash -c`, which starts a fresh
    # shell that does NOT inherit shell functions. Without the export they
    # fail with "compose_service_json: command not found" — a failure that
    # reads like a broken compose file rather than a broken harness.
    export -f compose_service_json
}

# Emit the resolved config for one service as JSON.
compose_service_json() {
    docker compose -f docker-compose.yml config --format json 2>/dev/null \
        | python3 -c "import sys,json;print(json.dumps(json.load(sys.stdin)['services']['$1']))"
}

@test "zitadel: image is pinned to v4.15.0, never :latest" {
    run bash -c "compose_service_json zitadel | python3 -c \"import sys,json;print(json.load(sys.stdin)['image'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "ghcr.io/zitadel/zitadel:v4.15.0" ]
}

@test "zitadel-db: is its own postgres, not the stack's mariadb" {
    run bash -c "compose_service_json zitadel-db | python3 -c \"import sys,json;print(json.load(sys.stdin)['image'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "postgres:16-alpine" ]
}

@test "zitadel: default published port is 8090, not 8080" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
p=json.load(sys.stdin)['ports'][0]
print(f\\\"{p['published']}:{p['target']}\\\")\""
    [ "$status" -eq 0 ]
    [ "$output" = "8090:8080" ]
}

@test "zitadel: EXTERNALPORT tracks the published port under an override" {
    run bash -c "ZITADEL_PORT=9099 compose_service_json zitadel | python3 -c \"
import sys,json
s=json.load(sys.stdin)
print(f\\\"{s['ports'][0]['published']}:{s['environment']['ZITADEL_EXTERNALPORT']}\\\")\""
    [ "$status" -eq 0 ]
    [ "$output" = "9099:9099" ]
}

@test "zitadel: both postgres SSL modes are disabled" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
e=json.load(sys.stdin)['environment']
print(e['ZITADEL_DATABASE_POSTGRES_ADMIN_SSL_MODE'], e['ZITADEL_DATABASE_POSTGRES_USER_SSL_MODE'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "disable disable" ]
}

@test "zitadel: the machine-user block that produces the PAT is complete" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
e=json.load(sys.stdin)['environment']
keys=['ZITADEL_FIRSTINSTANCE_PATPATH',
      'ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_USERNAME',
      'ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_NAME',
      'ZITADEL_FIRSTINSTANCE_ORG_MACHINE_PAT_EXPIRATIONDATE']
print('ok' if all(e.get(k) for k in keys) else 'missing')
print(e['ZITADEL_FIRSTINSTANCE_PATPATH'])\""
    [ "$status" -eq 0 ]
    [ "${lines[0]}" = "ok" ]
    [ "${lines[1]}" = "/zitadel-data/automation-user.pat" ]
}

@test "zitadel: Login V2 is disabled, since no zitadel-login service exists" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
print(json.load(sys.stdin)['environment']['ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "false" ]
}

@test "zitadel: master key is exactly 32 characters" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json,re
c=json.load(sys.stdin)['command']
c=' '.join(c) if isinstance(c,list) else c
m=re.search(r'--masterkey\s+\\\"?([^\\\" ]+)', c)
print(len(m.group(1)) if m else 'nomatch')\""
    [ "$status" -eq 0 ]
    [ "$output" = "32" ]
}

@test "zitadel: runs as uid 0 so the host can read the PAT" {
    run bash -c "compose_service_json zitadel | python3 -c \"import sys,json;print(json.load(sys.stdin).get('user',''))\""
    [ "$status" -eq 0 ]
    [ "$output" = "0" ]
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./scripts/tests/bats/bin/bats scripts/tests/zitadel_compose.bats`
Expected: every case FAILs — `docker compose config` succeeds but has no `zitadel` key, so the python lookup raises `KeyError` and `compose_service_json` exits non-zero.

- [ ] **Step 3: Add the two services and the volume to `docker-compose.yml`**

Add under `services:` (placement next to `db` keeps storage services together):

```yaml
# ── Local identity provider ────────────────────────────────────────────
# Local development authenticates against THIS Zitadel, not the production
# one at auth.catholicdigitalcommons.org. See
# docs/superpowers/specs/2026-08-17-local-zitadel-stack-design.md.
#
# Its own Postgres: Zitadel requires PostgreSQL and this stack's `db` is
# MariaDB, so it cannot be shared the way martyrology-api shares its.
zitadel-db:
  image: postgres:16-alpine
  restart: unless-stopped
  environment:
    POSTGRES_USER: postgres
    POSTGRES_PASSWORD: ${ZITADEL_DB_PASSWORD:-postgres}
    POSTGRES_DB: zitadel
  volumes:
    - zitadel_db_data:/var/lib/postgresql/data
  healthcheck:
    test: ["CMD-SHELL", "pg_isready -U postgres -d zitadel"]
    interval: 5s
    timeout: 3s
    retries: 10

zitadel:
  # PINNED. cdcf-infra/auth/setup-zitadel.sh calls the management API by
  # versioned path (/zitadel.application.v2.ApplicationService/…), so a
  # silent major bump breaks provisioning with no change in this repo.
  image: ghcr.io/zitadel/zitadel:v4.15.0
  restart: unless-stopped
  # The master key must be EXACTLY 32 chars and must never change: Zitadel
  # encrypts instance data with it and cannot decrypt after a rotation.
  # Losing it means deleting zitadel_db_data and re-provisioning, which
  # invalidates the client IDs in .env.local.
  command: start-from-init --masterkey "${ZITADEL_MASTERKEY:-MasterkeyNeedsToHave32Characters}"
  # The image is scratch-based; without uid 0 the PAT lands in the bind
  # mount with ownership the host-run provisioning script cannot read.
  user: "0"
  ports:
    # 8090, not 8080: martyrology-api and LiturgicalCalendarFrontend both
    # default their local Zitadel to 8080, and a collision presents as an
    # opaque bind failure.
    - "127.0.0.1:${ZITADEL_PORT:-8090}:8080"
  environment:
    # EXTERNALPORT must equal the published port above — issuer and
    # discovery URLs are minted from these two.
    ZITADEL_EXTERNALDOMAIN: localhost
    ZITADEL_EXTERNALPORT: ${ZITADEL_PORT:-8090}
    ZITADEL_EXTERNALSECURE: "false"
    ZITADEL_TLS_ENABLED: "false"

    # Points at zitadel-db above, NOT the stack's mariadb `db`.
    ZITADEL_DATABASE_POSTGRES_HOST: zitadel-db
    ZITADEL_DATABASE_POSTGRES_PORT: 5432
    ZITADEL_DATABASE_POSTGRES_DATABASE: zitadel
    ZITADEL_DATABASE_POSTGRES_ADMIN_USERNAME: postgres
    ZITADEL_DATABASE_POSTGRES_ADMIN_PASSWORD: ${ZITADEL_DB_PASSWORD:-postgres}
    # Both SSL modes must be `disable`: zitadel-db serves no TLS, and
    # omitting these leaves Zitadel retrying TLS and failing during
    # migration, before it ever answers a request.
    ZITADEL_DATABASE_POSTGRES_ADMIN_SSL_MODE: disable
    ZITADEL_DATABASE_POSTGRES_USER_USERNAME: zitadel
    ZITADEL_DATABASE_POSTGRES_USER_PASSWORD: ${ZITADEL_DB_PASSWORD:-zitadel}
    ZITADEL_DATABASE_POSTGRES_USER_SSL_MODE: disable

    # Login V1. Zitadel v4 can require the separate zitadel-login
    # container; with no such service the authorize flow lands on a route
    # nothing serves. Auth.js drives sign-in here, so V1 suffices.
    ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED: "false"

    # These four are one unit. PATPATH alone names a file that is never
    # written — without the machine user there is no token, and the
    # host-run provisioning script has nothing to authenticate with.
    ZITADEL_FIRSTINSTANCE_PATPATH: /zitadel-data/automation-user.pat
    ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_USERNAME: automation-user
    ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_NAME: Automation User
    ZITADEL_FIRSTINSTANCE_ORG_MACHINE_PAT_EXPIRATIONDATE: "2030-01-01T00:00:00Z"
  volumes:
    # Bind mount, not a named volume: the host-run cdcf-infra script must
    # be able to read the PAT.
    - ./.zitadel-data:/zitadel-data:delegated
  depends_on:
    zitadel-db:
      condition: service_healthy
  healthcheck:
    # Zitadel's own readiness check, so dependents wait for migrations
    # rather than merely for the port to open.
    test: ["CMD", "/app/zitadel", "ready"]
    interval: 10s
    timeout: 5s
    retries: 20
```

Add to the existing `volumes:` block:

```yaml
zitadel_db_data:
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./scripts/tests/bats/bin/bats scripts/tests/zitadel_compose.bats`
Expected: all 9 PASS.

- [ ] **Step 5: Gate the new test on the file it tests**

In `.github/workflows/test-worker.yml`, add `docker-compose.yml` to **both** `paths:` lists (the `pull_request` one at `:17-20` and the `push` one at `:24-26`), so editing compose runs these tests:

```yaml
- "scripts/cdcf_queue_worker*"
- "scripts/tests/**"
- "docker-compose.yml"
- ".github/workflows/test-worker.yml"
```

Without this, `scripts/tests/**` only fires when a test changes — the compose file could regress untested. (Note the job is `continue-on-error: true`, so this reports rather than blocks; that is the existing repo posture, not a decision of this plan.)

- [ ] **Step 6: Delete the stray `zitadel/` directory**

It is untracked on `main` and holds only an empty, root-owned `nginx.conf/` **directory** — a bind-mount artifact. It is root-owned, so:

```bash
sudo rm -rf zitadel/
```

Do **not** touch the `feature/zitadel-integration` branch on `origin`, where `zitadel/nginx.conf` exists as a real file.

- [ ] **Step 7: Verify the stack actually boots**

The config test proves the file is right; it does not prove Zitadel starts. Run:

```bash
docker compose up -d zitadel-db zitadel
docker compose ps zitadel-db zitadel          # both healthy (allow ~60s for migrations)
ls -l .zitadel-data/automation-user.pat       # exists and is host-readable
curl -sf http://localhost:8090/debug/healthz && echo OK
```

If `zitadel` restarts in a loop, read `docker compose logs zitadel`. The two most likely causes are covered by Step 3's comments: an SSL-mode omission (fails during migration) or an `EXTERNALPORT` mismatch.

- [ ] **Step 8: Commit**

```bash
git add docker-compose.yml scripts/tests/zitadel_compose.bats .github/workflows/test-worker.yml
git commit -m "feat(auth): add a local Zitadel to the compose stack

Local development authenticates against the production Zitadel today, via a
localhost:3000 client registered there. This is the replacement that lets
cdcf-infra #20 remove that client.

Its own postgres:16-alpine rather than the stack's db, which is mariadb:11 and
cannot host Zitadel. Port defaults to 8090 because martyrology-api and
LiturgicalCalendarFrontend both use 8080, and a third stack there means only
one runs at a time.

The bats cases pin what nothing else validates: that the published port and
ZITADEL_EXTERNALPORT stay equal under a ZITADEL_PORT override, since a
mismatch mints issuer URLs that look correct and do not resolve."
```

---

### Task 2: Point local development at the local Zitadel

**Files:**

- Modify: `.env.local.example:70` (and the comment block at `:53-69`)
- Modify: `README.md` (after `### Environment Variables`, `:44`)
- Modify: `scripts/tests/zitadel_compose.bats` (add the cross-file agreement case)

**Interfaces:**

- Consumes: Task 1's `${ZITADEL_PORT:-8090}` default and the PAT path.
- Produces: `.env.local.example` with `AUTH_ZITADEL_ISSUER=http://localhost:8090` and a new `AUTH_ZITADEL_ORG_ID` key. No code reads anything new — `lib/auth.ts:69` already consumes `AUTH_ZITADEL_ORG_ID`.

This is the task that actually retires the production localhost client. Leaving the example pointed at production means developers stay on it and `cdcf-infra` #20 can never remove the origin.

- [ ] **Step 1: Write the failing test**

Append to `scripts/tests/zitadel_compose.bats`:

```bash
@test "env example: AUTH_ZITADEL_ISSUER matches the compose default port" {
    port=$(compose_service_json zitadel | python3 -c "
import sys,json
print(json.load(sys.stdin)['ports'][0]['published'])")
    run grep -E "^AUTH_ZITADEL_ISSUER=http://localhost:${port}$" .env.local.example
    [ "$status" -eq 0 ]
}

@test "env example: local dev does not point at the production Zitadel" {
    run grep -E "^AUTH_ZITADEL_ISSUER=.*auth\.catholicdigitalcommons\.org" .env.local.example
    [ "$status" -ne 0 ]
}

@test "env example: AUTH_ZITADEL_ORG_ID is present for lib/auth.ts to read" {
    run grep -E "^AUTH_ZITADEL_ORG_ID=" .env.local.example
    [ "$status" -eq 0 ]
}
```

The first case is the "one port, four places" guard from the spec: it reads the port out of the resolved compose config and requires the env example to agree, so the two cannot drift silently.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./scripts/tests/bats/bin/bats scripts/tests/zitadel_compose.bats`
Expected: the three new cases FAIL — `AUTH_ZITADEL_ISSUER` is still the production URL and `AUTH_ZITADEL_ORG_ID` does not exist.

- [ ] **Step 3: Update `.env.local.example`**

Replace line 70 and extend the comment block above it. The existing production/non-production `aud` guidance stays — it still applies to deployed environments:

```bash
# For LOCAL development this points at the Zitadel in this repo's compose
# stack, not the production instance. Bring it up and provision the app:
#
#   docker compose up -d zitadel-db zitadel
#   # then, from cdcf-infra/auth, with .env.local carrying
#   #   ZITADEL_ISSUER=http://localhost:8090
#   #   ZITADEL_INTERNAL_URL=http://127.0.0.1:8090
#   #   ZITADEL_PAT_FILE=<path-to>/cdcf-website/.zitadel-data/automation-user.pat
#   ./setup-zitadel.sh --target local --create-orgs --provision-cdcf-website
#
# That run prints AUTH_ZITADEL_ID, AUTH_ZITADEL_SECRET and the CDCF Org ID.
# If you change ZITADEL_PORT in .env, change the URL below to match — see
# docs/superpowers/specs/2026-08-17-local-zitadel-stack-design.md §4.
AUTH_ZITADEL_ID=
AUTH_ZITADEL_SECRET=
AUTH_ZITADEL_ISSUER=http://localhost:8090

# The LOCAL CDCF Org ID from the provisioning run above — not the production
# one. Read by lib/auth.ts:69. A wrong value fails sign-in in a way that looks
# like a credentials problem, so it is worth checking first.
AUTH_ZITADEL_ORG_ID=
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./scripts/tests/bats/bin/bats scripts/tests/zitadel_compose.bats`
Expected: all 12 PASS.

- [ ] **Step 5: Document the stack in `README.md`**

Insert after the `### Environment Variables` section (`:44`), before `### Development` (`:57`):

````markdown
### Local Identity Provider (Zitadel)

Local sign-in runs against a Zitadel in this repo's compose stack — **not**
the production instance at `auth.catholicdigitalcommons.org`.

```bash
docker compose up -d zitadel-db zitadel
```

First boot runs migrations and writes a machine-user token to
`.zitadel-data/automation-user.pat`. Provisioning the OIDC app is done from
[`cdcf-infra`](https://github.com/CatholicOS/cdcf-infra), which owns
Zitadel configuration for every property — this repo adds no provisioning
script. From `cdcf-infra/auth`, with `.env.local` carrying:

```bash
ZITADEL_ISSUER=http://localhost:8090
ZITADEL_INTERNAL_URL=http://127.0.0.1:8090
ZITADEL_PAT_FILE=<path-to>/cdcf-website/.zitadel-data/automation-user.pat
```

then:

```bash
./setup-zitadel.sh --target local --create-orgs --provision-cdcf-website
```

`--create-orgs` must come first — provisioning exits 13 without the CDCF Org.
Copy the printed `AUTH_ZITADEL_ID`, `AUTH_ZITADEL_SECRET` and Org ID into
`.env.local`.

These Compose variables come from `.env` (Compose does not read `.env.local`):

| Variable              | Default                            | Notes                                                |
| --------------------- | ---------------------------------- | ---------------------------------------------------- |
| `ZITADEL_PORT`        | `8090`                             | 8080 collides with the LitCal and Martyrology stacks |
| `ZITADEL_MASTERKEY`   | `MasterkeyNeedsToHave32Characters` | Exactly 32 chars, and never change it — see below    |
| `ZITADEL_DB_PASSWORD` | `postgres` / `zitadel`             | Local only                                           |

Changing `ZITADEL_MASTERKEY` after first boot makes existing instance data
undecryptable; recovery means `docker compose down -v` and re-provisioning,
which invalidates the client IDs in `.env.local`. Changing `ZITADEL_PORT`
means updating `AUTH_ZITADEL_ISSUER` in `.env.local` and the two
`cdcf-infra` URLs above to match.
````

- [ ] **Step 6: Verify sign-in end to end**

The tests pin configuration; only this proves the goal. With the stack up and `.env.local` filled from the provisioning run:

1. `npm run dev` (or `docker compose up -d nextjs`), open `http://localhost:3000`.
2. Sign in. The browser should be redirected to `localhost:8090`, never to `auth.catholicdigitalcommons.org`.
3. Confirm the session carries the expected roles.
4. Sign out via `/api/auth/zitadel-signout`.
5. **The load-bearing check:** with devtools' network tab open across the whole flow, confirm no request reaches `auth.catholicdigitalcommons.org`. Every other step can pass while still silently using production.

- [ ] **Step 7: Commit**

```bash
git add .env.local.example README.md scripts/tests/zitadel_compose.bats
git commit -m "feat(auth): default local development to the local Zitadel

Flips AUTH_ZITADEL_ISSUER from the production instance to localhost:8090 and
adds AUTH_ZITADEL_ORG_ID, which lib/auth.ts already reads but no example
documented.

Defaulting rather than documenting an opt-in is the point: while the example
points at production, developers keep using the localhost:3000 client
registered there, and cdcf-infra #20 can never remove it.

The new bats case reads the port out of the resolved compose config and
requires the env example to agree, so the two cannot drift."
```

---

### Task 3: Correct the stale OIDC plan document

**Files:**

- Modify: `docs/zitadel-oidc-plan.md` (`:14-16`, `:115`, `:158`, `:212-226`)

**Interfaces:**

- Consumes: nothing.
- Produces: nothing. Documentation only.

Its "Production Deployment" section is the reason this is a task rather than a footnote: it instructs standing up Zitadel at `auth.catholicdigitalcommons.org`, an instance that already exists and is managed by `cdcf-infra`. Following it would create a second production identity provider.

- [ ] **Step 1: Add a status banner directly under the `# Zitadel OIDC Integration Plan` heading**

```markdown
> **Status (2026-08-17):** partially superseded. Phase 2.1–2.3 (Auth.js v5) is
> **done** — see `lib/auth.ts` and `app/api/auth/`. Local Zitadel setup is now
> `docs/superpowers/specs/2026-08-17-local-zitadel-stack-design.md`, and OIDC
> app provisioning is automated by `cdcf-infra`'s
> `setup-zitadel.sh --provision-cdcf-website`. The WordPress OIDC/passkey work
> (§1.2) and WordPress bearer validation (§2.4) remain **deferred and
> accurate**. **Do not follow "Production Deployment" below** — see §Production
> Deployment for why.
```

- [ ] **Step 2: Replace the body of `## Production Deployment` (`:212-226`)**

Delete the Plesk/Docker install instructions and the `ZITADEL_EXTERNAL_DOMAIN` block, and replace with:

```markdown
**Superseded — do not follow the previous contents of this section.**

The shared Zitadel at `auth.catholicdigitalcommons.org` already exists and is
owned by [`cdcf-infra`](https://github.com/CatholicOS/cdcf-infra), which
provisions the CDCF Website OIDC apps via
`./setup-zitadel.sh --target production --provision-cdcf-website`. This
section previously described standing up a _second_ instance; following it
would have created a competing production identity provider.

For local development see the "Local Identity Provider (Zitadel)" section of
the README.
```

- [ ] **Step 3: Mark §1.1 and §1.3 superseded in place**

Under `### 1.1 Add Zitadel to Docker Compose`, add:

```markdown
> **Superseded** by `docs/superpowers/specs/2026-08-17-local-zitadel-stack-design.md`.
> The shipped stack differs in three ways that matter: a dedicated
> `zitadel-db` (this stack's `db` is MariaDB, which Zitadel cannot use), a
> pinned `v4.15.0` image rather than `:latest`, and port 8090 rather than 8080.
```

Under `### 1.3 Zitadel Configuration (Manual, Post-Boot)`, add:

```markdown
> **Superseded.** App creation is automated — see the README's "Local Identity
> Provider (Zitadel)" section. Creating the app by hand in the console produces
> a client `cdcf-infra` does not know about and will not converge.
```

- [ ] **Step 4: Mark Phase 2.1–2.3 done**

Under `## Phase 2: Next.js Frontend Auth`, add:

```markdown
> **2.1–2.3 are done.** `next-auth@5.0.0-beta.31` is installed, `lib/auth.ts`
> and `app/api/auth/[...nextauth]` exist, and `app/api/auth/zitadel-signout`
> handles RP-initiated logout. §2.4 (WordPress bearer validation) is still
> outstanding.
```

- [ ] **Step 5: Verify formatting passes the repo's own gates**

```bash
npm run format:md
npm run lint:md
```

Expected: both clean. `format:md` rewrites in place; re-stage afterwards.

- [ ] **Step 6: Commit**

```bash
git add docs/zitadel-oidc-plan.md
git commit -m "docs: correct the stale Zitadel OIDC plan

Its Production Deployment section instructed standing up Zitadel at
auth.catholicdigitalcommons.org. That instance exists and is managed by
cdcf-infra, so following the section would have created a second production
identity provider.

Also marks Phase 2.1-2.3 done (Auth.js v5 shipped), and §1.1/§1.3 superseded by
the local stack design and by automated provisioning. The WordPress OIDC and
bearer-validation phases are genuinely outstanding and are left as they are."
```

---

## Self-Review

**Spec coverage:**

| Spec section                                     | Task                                                                                    |
| ------------------------------------------------ | --------------------------------------------------------------------------------------- |
| §3.1 `zitadel-db`                                | Task 1, Steps 1/3 (test + service)                                                      |
| §3.2 `zitadel` service + all seven failure notes | Task 1, Steps 1/3, each note a comment or a test case                                   |
| §3 stray `zitadel/` directory                    | Task 1, Step 6                                                                          |
| §4 provisioning contract                         | Task 2, Steps 3/5 (env comment + README)                                                |
| §4 "one port, four places"                       | Task 1 Step 1 (EXTERNALPORT override case) + Task 2 Step 1 (env-example agreement case) |
| §5 Next.js environment                           | Task 2, Step 3                                                                          |
| §6 documentation corrections                     | Task 3, all steps — one per table row                                                   |
| §7 verification 1–4                              | Task 1, Step 7                                                                          |
| §7 verification 5–7                              | Task 2, Step 6                                                                          |
| §8 out of scope                                  | No task; nothing here touches `cdcf-infra` or WordPress                                 |

No gaps.

**Placeholder scan:** none. Every step carries the literal YAML, bash, or markdown to apply. The one `<path-to>` is a genuine per-machine absolute path, and it appears in prose the developer fills in, not in code this plan writes.

**Type consistency:** `compose_service_json` is defined once in Task 1 Step 1 and reused by Task 2 Step 1's appended cases in the same file. Service names (`zitadel`, `zitadel-db`), the volume (`zitadel_db_data`), the PAT path (`/zitadel-data/automation-user.pat`), and the port default (`8090`) are identical everywhere they appear across all three tasks.

**One departure from the spec, deliberate:** §7 says verification is manual only, on the grounds that an automated check would need a browser OIDC round-trip. That holds for the _flow_, and Task 1 Step 7 and Task 2 Step 6 keep it manual. It does not hold for the _configuration_ — `docker compose config` resolves interpolation without starting anything, so the port derivation, SSL modes, image pin, and machine-user block are cheaply testable. Given the spec itself flags that four restatements of one port have nothing validating them, leaving that untested would have been the wrong call.
