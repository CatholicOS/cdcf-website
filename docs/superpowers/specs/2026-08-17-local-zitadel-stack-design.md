# Local Zitadel stack for CDCF website development — design

**Date:** 2026-08-17
**Repos touched:** `cdcf-website`; `cdcf-infra` provisions into it, unchanged
**Blocks:** `cdcf-infra` issue #20 (`docs/superpowers/specs/2026-08-17-target-aware-oidc-provisioning-design.md`)
**Status:** design, pending implementation plan

---

## 1. Why

Local development of this repo authenticates against the **production** Zitadel. `.env.local.example:70` sets `AUTH_ZITADEL_ISSUER=https://auth.catholicdigitalcommons.org`, and the sign-in flow uses a `http://localhost:3000` redirect URI registered on the production instance (`cdcf-infra`'s `CDCF_FRONTEND_NONPROD_URLS`).

That localhost client contradicts `CatholicOS/martyrology-api#26`, which settled that local development happens against a separate local Zitadel rather than a localhost client in the production instance — which is why LitCal and Martyrology have none. `cdcf-infra` #20 removes it.

It cannot be removed first. Auth.js v5 is live here (`next-auth 5.0.0-beta.31`, `lib/auth.ts`, `app/api/auth/[...nextauth]`, `app/api/auth/zitadel-signout`), so the localhost client is **in active use**. Dropping it without a replacement would not relocate local sign-in, it would delete it. This stack is that replacement, and #20 waits on it.

## 2. Decision

**Add a local Zitadel to this repo's compose stack, provisioned by `cdcf-infra`'s `setup-zitadel.sh --target local`, and make it the default target for local development.**

Making it the _default_ rather than an opt-in is the point. If `.env.local.example` keeps pointing at production, developers stay on the production client and its origin can never be removed — the problem is deferred, not solved.

`martyrology-api`'s stack is the template: same image, same first-instance PAT convention, same host-run provisioning. One forced divergence, in §3.1.

## 3. What gets added

### 3.1 `zitadel-db` — and why it is not the existing `db`

`martyrology-api` points Zitadel at its stack's existing Postgres. **This repo's `db` is `mariadb:11`, and Zitadel requires PostgreSQL**, so Zitadel gets its own `postgres:16-alpine` service and named volume, with a `pg_isready` healthcheck. This is the only place the Martyrology template cannot be copied, and it matches what `docs/zitadel-oidc-plan.md` §1.1 already proposed.

### 3.2 `zitadel`

`ghcr.io/zitadel/zitadel:v4.15.0` — **pinned**, matching `martyrology-api`. The existing plan doc says `:latest`; that is wrong for a stack whose management API `cdcf-infra/auth/setup-zitadel.sh` calls by versioned path (`/zitadel.application.v2.ApplicationService/…`, `/v2/users/…`). A silent major bump would break provisioning with no change in this repo.

Started with `start-from-init --masterkey`, `depends_on: zitadel-db` healthy.

The load-bearing configuration:

```yaml
ports: ["127.0.0.1:${ZITADEL_PORT:-8090}:8080"]
environment:
  ZITADEL_EXTERNALDOMAIN: localhost
  ZITADEL_EXTERNALPORT: ${ZITADEL_PORT:-8090}
  ZITADEL_EXTERNALSECURE: "false"
  ZITADEL_TLS_ENABLED: "false"
  ZITADEL_FIRSTINSTANCE_PATPATH: /zitadel-data/automation-user.pat
volumes: ["./.zitadel-data:/zitadel-data:delegated"]
```

Two of these are the usual failure points:

- **`ZITADEL_EXTERNALPORT` must track the published host port.** Zitadel mints issuer and discovery URLs from `EXTERNALDOMAIN`/`EXTERNALPORT`; if they disagree with what the browser reaches, sign-in fails at discovery with a URL that looks superficially correct.
- **Port default is 8090, not 8080.** Both `martyrology-api` and `LiturgicalCalendarFrontend` default their local Zitadel to `127.0.0.1:8080`. A third stack on 8080 means only one can run at a time, and the collision presents as an opaque bind failure. `ZITADEL_PORT` keeps it overridable.

`./.zitadel-data/` is already gitignored (`.gitignore:58`). The stray `zitadel/` directory at the repo root is deleted: it is untracked on `main` and contains only an empty, root-owned `nginx.conf/` **directory** — a bind-mount artifact from a compose file that once referenced a file at that path. Two implementation notes follow from that. It is root-owned, so removal needs elevated privileges. And it is not quite historyless: `zitadel/nginx.conf` existed as a real file on the unmerged `feature/zitadel-integration` branch (still present on `origin`), which this deletion does not touch.

## 4. Provisioning contract

The bind-mounted PAT is the entire integration. Zitadel writes `automation-user.pat` into `./.zitadel-data/` on first boot; the **host-run** `cdcf-infra` script reads it. No script is added to this repo, and `cdcf-infra` needs no change — the same shape Martyrology already uses.

From `cdcf-infra/auth`, with `.env.local` carrying:

```bash
ZITADEL_ISSUER=http://localhost:8090
ZITADEL_INTERNAL_URL=http://127.0.0.1:8090
ZITADEL_PAT_FILE=<path-to>/cdcf-website/.zitadel-data/automation-user.pat
```

then:

```bash
./setup-zitadel.sh --target local --create-orgs --provision-cdcf-website
```

`--create-orgs` is required first: `do_provision_cdcf_website` exits 13 if the CDCF Org is absent. The run prints `AUTH_ZITADEL_ID` and `AUTH_ZITADEL_SECRET` for this repo's `.env.local`.

Note this depends on #20's target-aware work for the `local` origin set (`http://localhost:3000`, devMode=true). Until #20 lands, a local run registers the production origins — harmless in a local instance, but not yet correct. The two land in order: this stack, then #20.

## 5. Next.js environment

`.env.local.example`:

- `AUTH_ZITADEL_ISSUER` → `http://localhost:8090` (from the production URL), with a comment pointing at §4's command.
- `AUTH_ZITADEL_ORG_ID` needs the **local** CDCF Org ID, not the production one. It is consumed at `lib/auth.ts:69` and is easy to miss because sign-in fails in a way that looks like a credentials problem; the same provisioning run prints it.
- `AUTH_ZITADEL_ID` / `AUTH_ZITADEL_SECRET` stay blank in the example, filled from the run.

## 6. Documentation corrections

`docs/zitadel-oidc-plan.md` is corrected where it misleads, not rewritten.

| Section                                        | Status                                                                                                                                                                                             | Action                                                                                    |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Production Deployment (`:212-226`)             | **Wrong and dangerous** — instructs standing up Zitadel at `auth.catholicdigitalcommons.org`. That instance exists and is managed by `cdcf-infra`; following this creates a second production IdP. | Replace with a pointer to `cdcf-infra`.                                                   |
| §1.1 Add Zitadel to Docker Compose             | Superseded                                                                                                                                                                                         | Point at this design (separate `zitadel-db`, pinned image, port 8090).                    |
| §1.3 Zitadel Configuration (Manual, Post-Boot) | Superseded                                                                                                                                                                                         | Provisioning is automated by `--provision-cdcf-website`; replace the console walkthrough. |
| §1.2 WordPress OIDC plugin / passkey           | Genuinely undone                                                                                                                                                                                   | Keep, marked deferred.                                                                    |
| Phase 2.1–2.3 Auth.js v5                       | **Done**                                                                                                                                                                                           | Mark done.                                                                                |
| §2.4 WordPress bearer validation               | Genuinely undone                                                                                                                                                                                   | Keep, marked deferred.                                                                    |

The doc's original motivation — passkey login to the WordPress admin — is untouched by this design and stays deferred.

## 7. Verification

This is developer infrastructure, and the honest verification is a documented manual sequence rather than an automated suite. An automated check would need a full browser OIDC round-trip in CI; that is not worth building here, and claiming coverage that does not exist would be worse than saying so.

1. `docker compose up -d zitadel-db zitadel` → both healthy.
2. `./.zitadel-data/automation-user.pat` exists and is readable from the host.
3. The `cdcf-infra` run in §4 completes and prints app credentials.
4. The app exists in the local Zitadel with redirect `http://localhost:3000/api/auth/callback/zitadel` and devMode=true.
5. Sign in end-to-end at `localhost:3000`; confirm the session carries the expected roles.
6. Sign out via `app/api/auth/zitadel-signout`.
7. Confirm nothing in the flow reaches `auth.catholicdigitalcommons.org` — the point of the exercise.

Step 7 is the one that actually proves the goal; the rest can pass while still silently using production.

## 8. Out of scope

- WordPress OIDC and passkey admin login (plan §1.2), and WP bearer validation (§2.4).
- Any change to `cdcf-infra/auth/setup-zitadel.sh`. Its target-aware work is #20, which this unblocks.
- Removing `http://localhost:3000` from the production Zitadel — that is #20's §4.1, and it happens only after developers are verified onto this stack.
