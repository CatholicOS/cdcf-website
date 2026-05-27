# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Development Commands

```bash
npm run dev               # Start Next.js dev server (localhost:3000)
npm run build             # Production build (catches TypeScript errors)
npm run lint              # ESLint with Next.js rules
npm test                  # Vitest suite over lib/wordpress/* (data layer)
npm run test:watch        # Vitest in watch mode
npm run test:coverage     # Vitest with v8 coverage
npm start                 # Start production server
docker compose up --build # Full stack: WordPress + MariaDB + Next.js + Nginx

# WordPress plugin / theme tests (PHPUnit + Brain Monkey + Mockery).
# The two trees are independent — each has its own composer.json,
# vendor/, and phpunit.xml.dist. vendor/ is gitignored; composer.lock
# + composer.json are checked in.
composer install --working-dir=wordpress/plugins/cdcf-redis-translations
composer test    --working-dir=wordpress/plugins/cdcf-redis-translations
composer install --working-dir=wordpress/themes/cdcf-headless
composer test    --working-dir=wordpress/themes/cdcf-headless

scripts/tests/bats/bin/bats scripts/tests/   # Queue-worker bash unit tests (bats-core)
```

`npm test` covers the Next.js data layer (`lib/wordpress/*` — GraphQL client + per-template `get*` helpers). CI runs it non-blocking on PRs that touch `lib/**`, `package.json`, or `vitest.config.ts`.

`composer test` (per WordPress tree) covers the `/cdcf/v1/*` REST handlers: maintenance + process-queue + the translation-enqueue fallback in the plugin, and the relationship GET/POST endpoints in the theme. CI runs both non-blocking on PRs that touch the respective tree.

`scripts/tests/bats/bin/bats scripts/tests/` covers the queue-worker helpers (`scripts/cdcf_queue_worker.lib.sh`). bats-core is vendored as a git submodule at `scripts/tests/bats/`, so fresh checkouts need `git clone --recurse-submodules` (or `git submodule update --init --recursive` after a plain clone). CI runs it non-blocking on PRs that touch `scripts/cdcf_queue_worker*` or `scripts/tests/**`. See `scripts/tests/README.md` for the shim convention and how to add a new test.

## Architecture Overview

Headless CMS: Next.js 15 (App Router) frontend fetches content from WordPress via WPGraphQL.

- **Development:** Docker Compose runs everything on `localhost` with Nginx reverse-proxying WordPress paths (`/wp-admin`, `/graphql`, `/wp-content`) and everything else to Next.js.
- **Production:** Two subdomains on Plesk (no Docker). `catholicdigitalcommons.org` runs Next.js standalone, `cms.catholicdigitalcommons.org` runs WordPress. Cross-origin GraphQL requests are allowed via CORS headers in the theme.

### Page Rendering Pipeline

1. **Request** hits `app/[lang]/[[...slug]]/page.tsx` (catch-all route)
2. `getPage(slug, locale)` fetches the page from WordPress GraphQL
3. The page's `template.templateName` (Home, About, Projects, Community, Blog, Contact) determines which sections render
4. Additional data (posts, projects, sponsors) is fetched in parallel based on template
5. `PageRenderer` dispatches to template-specific render functions, each producing a fixed section order (Hero → template sections → CTA)

### Data Flow

- `lib/wordpress/client.ts` — `wpQuery<T>()` wraps `fetch()` with ISR (60s default) and draft mode support
- `lib/wordpress/queries.ts` — GraphQL query strings built from fragments
- `lib/wordpress/api.ts` — Typed fetch functions (`getPage`, `getPosts`, `getProjects`, etc.) that map next-intl locale codes to Polylang uppercase codes (`en` → `EN`)
- `lib/wordpress/types.ts` — TypeScript interfaces for all WordPress data

### Dual i18n System

- **UI strings:** `messages/*.json` via next-intl. Use `useTranslations()` (client) or `getTranslations()` (server). Managed externally via Weblate.
- **CMS content:** WordPress + Polylang. Fetched per-locale at render time via WPGraphQL translation queries.
- Locales: en (default), it, es, fr, pt, de. Configured in `src/i18n/routing.ts`.
- Locale prefix is `as-needed` (no `/en/` prefix for default locale).

### WordPress Theme (`wordpress/themes/cdcf-headless/`)

`functions.php` registers everything programmatically:

- **CPTs:** project, team_member, sponsor, community_channel, local_group, stat_item (all with `show_in_graphql: true`, `has_archive: false`)
- **ACF field groups:** Shared (hero, cta) + per-template (homeFields, aboutFields, etc.) + per-CPT
- **Page templates:** Home, About, Projects, Community, Blog, Contact
- **CORS headers** for GraphQL, **preview URL rewriting** to Next.js draft mode, **AI translation** via OpenAI
- **Custom REST endpoints** under `cdcf/v1` namespace (see REST API Endpoints below)

## Key Conventions

- Components are React Server Components by default; only add `'use client'` when hooks/state are needed
- Use `Link` from `@/src/i18n/navigation` for locale-aware navigation, not from `next/link`
- Use `clsx()` for conditional classNames
- WordPress content rendered via `dangerouslySetInnerHTML` with Tailwind `.prose` class
- Brand custom utilities are `cdcf-` prefixed (e.g. `cdcf-section`, `cdcf-btn-primary`, `cdcf-heading`) defined in `css/globals.css`

## Tailwind CSS v4

Uses `@import 'tailwindcss'` (not `@tailwind` directives). Custom utilities via `@utility name { ... }` syntax. Config loaded via `@config` directive in `css/globals.css`.

## WPGraphQL Gotchas

- ACF select fields return `string[]` arrays — access with `?.[0]`
- ACF relationship fields return `AcfContentNodeConnection` — need inline fragments (`... on Project`)
- ACF image fields return `AcfMediaItemConnectionEdge` — need `node { ... }` wrapper
- Polylang `translation()` uses `LanguageCodeEnum!`; `where` filters use `LanguageCodeFilterEnum`
- CPTs must have `has_archive => false` to avoid hijacking page URIs with the same slug

## REST API Endpoints (`cdcf/v1`)

All endpoints require Application Password authentication. Most endpoints require `edit_posts` capability; `/process-queue` and `/maintenance` require `manage_options` (administrator); `/create-user` and `/author-team-member` require the custom `cdcf_create_limited_users` capability — see the row notes where capability differs.

| Method | Route                        | Description                                                                                                                                                                                                                         |
| ------ | ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET`  | `/relationship`              | Read an ACF relationship field (`post_id`, `field`)                                                                                                                                                                                 |
| `POST` | `/relationship`              | Update an ACF relationship field (`post_id`, `field`, `value[]`)                                                                                                                                                                    |
| `POST` | `/translate`                 | Translate a post to a target language via OpenAI (`source_id`, `target_lang`, optional `post_id`)                                                                                                                                   |
| `POST` | `/update-disposable-domains` | Download latest disposable email domain blocklist from GitHub (called daily by Redis worker)                                                                                                                                        |
| `POST` | `/team-member`               | Create a team member with auto-translation and About page linking (see below)                                                                                                                                                       |
| `POST` | `/community-channel`         | Create a community channel with auto-translation and Community page linking (see below)                                                                                                                                             |
| `POST` | `/local-group`               | Create a local group with auto-translation and Community page linking (see below)                                                                                                                                                   |
| `POST` | `/maintenance`               | Pause or resume the cdcf-queue-worker by setting/clearing a Redis flag. Body: `action` is `"begin"` or `"end"`; optional `duration_seconds` is clamped server-side to 60–600. Requires administrator (`manage_options`) capability. |
| `POST` | `/academic-collaboration`    | Create an academic collaboration with auto-translation and Community page linking (see below)                                                                                                                                       |
| `POST` | `/create-user`               | Provision a low-privilege WordPress user (author/contributor/subscriber only) and email a set-password link. Requires the custom `cdcf_create_limited_users` capability, NOT `edit_posts` (see below).                              |
| `POST` | `/author-team-member`        | Link (or unlink) a WordPress user to their `team_member` bio card by writing the `author_team_member` ACF field on the user (`user_id`, `team_member_id`; pass `0` to clear). Requires `cdcf_create_limited_users` (see below).      |

### Sanitization convention

Every `cdcf/v1` route declares its `sanitize_callback` per field in the `args` block of `register_rest_route()` (in `functions.php`). The handlers in `includes/handlers/` trust that the request has already been sanitized by REST dispatch and **do not re-sanitize on entry**. The REST framework runs the permission callback → type validation → each field's `sanitize_callback` → then the handler — so by the time the handler sees `$request`, every declared field is clean.

When adding a new request field, declare its `sanitize_callback` at registration (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_email`, `esc_url_raw`, `absint`, etc.) — not inside the handler body. Validation beyond sanitization (`is_email()`, allowlist checks, format guards) stays in the handler where it can return a contextual `WP_Error`.

This was settled in #111: defense-in-depth re-sanitization inside the handler body was rejected because (a) the REST framework is the canonical and only ingress path for these handlers — they're not hooked into actions/filters or called from other PHP code, (b) re-sanitization would be idempotent under the actual call path and verifiable only by bypassing REST dispatch (which production callers never do), and (c) WordPress's own sinks (`wp_insert_post`, `update_field`, `wp_mail`) apply their own downstream sanitization. The args-block declaration is the authoritative layer; PR review of registration changes is the enforcement mechanism.

### `POST /team-member`

Creates an English `team_member` post, translates it to all 5 languages via OpenAI, and optionally appends each translation to the appropriate relationship field. For `team_members`, `ecclesial_council`, and `technical_council`, members are linked to the About page's council field. For `academic_council`, members are linked to the academic collaboration post's `collab_governance` field (requires `collab_post_id`). When `council` is omitted, the member is created with translations but not linked to any page — use this for project-only members (e.g. project leads) who should then be added to the project's `project_leads` field separately via `update-relationship`.

**Parameters:** `title` (required), `content` (required), `council` (optional — one of `team_members`, `ecclesial_council`, `technical_council`, `academic_council`; omit for project-only members), `member_title` (subheader shown under the name on the bio card — position/affiliation, e.g. "Professor of Theology, University of Notre Dame"; NOT an honorific like "Dr."/"Rev."), `member_role` (currently unused — not displayed anywhere; omit), `member_linkedin_url`, `member_github_url`, `featured_image_id`, `collab_post_id` (required for `academic_council` — the English academic collaboration post ID)

**Returns:** `{ success, en_post_id, translations: { en, it, es, fr, pt, de }, council, errors[] }`

### `POST /community-channel`

Creates an English `community_channel` post, translates it to all 5 languages via OpenAI, and appends each translation to the matching language version of the Community page's `channels` relationship field.

**Parameters:** `title` (required), `channel_description` (required), `channel_url` (required), `channel_icon` (optional — e.g. "discord", "slack", "vinly")

**Returns:** `{ success, en_post_id, translations: { en, it, es, fr, pt, de }, errors[] }`

### `POST /local-group`

Creates an English `local_group` post, translates it to all 5 languages via OpenAI, and appends each translation to the matching language version of the Community page's `local_groups` relationship field.

**Parameters:** `title` (required), `group_description` (required), `group_url` (required), `group_location` (optional — city/region name)

**Returns:** `{ success, en_post_id, translations: { en, it, es, fr, pt, de }, errors[] }`

### `POST /academic-collaboration`

Creates an English `academic_collaboration` post, translates it to all 5 languages via OpenAI, and appends each translation to the matching language version of the Community page's `academic_collaborations` relationship field.

**Parameters:** `title` (required), `collab_description` (required), `collab_university` (required), `collab_department` (optional), `collab_location` (optional — e.g. "Washington D.C., USA"), `collab_website_url` (optional)

**Returns:** `{ success, en_post_id, translations: { en, it, es, fr, pt, de }, errors[] }`

### `POST /create-user`

Provisions a single low-privilege WordPress user (e.g. a blog author) with a server-generated password, then sends the standard WordPress "set your password" email so the human controls the credential. The agent never supplies or receives a password.

Unlike every other `cdcf/v1` endpoint (which sit at the `edit_posts` editor baseline), this one gates on a **custom capability** `cdcf_create_limited_users`, granted (`includes/admin/limited-user-provisioning.php`) to: (1) any user who already holds native `create_users` — i.e. administrators, who can already create users of any role via core, so it just lets them call this endpoint from their own account; and (2) a non-admin account (e.g. an editor bot) opted in via the `cdcf_can_create_users` user-meta flag, set by an administrator through the "Limited user provisioning" checkbox on that account's user-edit screen. Native `create_users` is deliberately **never** granted to a non-admin bot, so core's `POST /wp/v2/users` (which accepts any role, including administrator) stays `403` for it.

Two independent guards prevent privilege escalation: (1) the capability gate above, and (2) a hard-coded role allowlist in the handler — only `author`, `contributor`, `subscriber` are accepted; `editor`, `administrator`, and anything else are rejected with a 400 regardless of caller capability.

**Parameters:** `username` (required), `email` (required), `role` (required — one of `author`, `contributor`, `subscriber`), `display_name` (optional — defaults to username), `first_name` (optional), `last_name` (optional), `team_member_id` (optional — link the new author to a `team_member` bio card in one call; best-effort, see below)

**Returns:** `{ success, user_id, username, email, role, team_member_id, linked, link_errors[] }` (never the password). `linked` is `true` only when a `team_member_id` was supplied and the link succeeded; a link failure is non-fatal (the user + set-password email already exist) and is reported in `link_errors[]`.

### `POST /author-team-member`

Links a WordPress user to their `team_member` bio card by writing the `author_team_member` ACF relationship field on the **user** object (ACF target `user_{id}`). Author pages reuse the linked team_member's translated bio, photo, role, and social links.

This needs a dedicated endpoint because neither generic path works for a user-located ACF field: `/relationship` is post-only (it `absint()`s `post_id` and guards with `get_post()`), and **ACF 6.x free does not expose user field groups via the core REST `acf` property** — a `PUT /wp/v2/users/{id}` with `{"acf":{…}}` is silently dropped (value reads back as `[]`, even when set via wp-admin). The handler uses the canonical `update_field('author_team_member', […], "user_{id}")`. Gated on `cdcf_create_limited_users` (same as `create-user`). Idempotent: re-linking the same id is a no-op (`updated: false`).

**Parameters:** `user_id` (required), `team_member_id` (required — a published `team_member` post ID, or `0` to clear the link)

**Returns:** `{ success, user_id, team_member_id, value: [id]|[], updated }`

## Adding a New Language

1. Add locale to `src/i18n/routing.ts` → `locales` array
2. Create `messages/<locale>.json`
3. Add label in `components/LanguageSwitcher.tsx` → `localeLabels`
4. Add mapping in `lib/wordpress/api.ts` → `LOCALE_MAP`
5. Add language in WordPress Polylang settings

## Python API Client (`scripts/cdcf_api.py`)

A Python client library and CLI that wraps all `cdcf/v1` REST endpoints and WPGraphQL queries. It reads credentials from env files internally so secrets are never exposed. Credential loading is target-aware:

- Default / `--target local` — merges `.env` then `.env.local` (localhost docker-compose stack)
- `--target staging` — merges `.env` then `.env.staging` (staging frontend; today shares the production WP backend)
- `--target production` — merges `.env` then `.env.production` (live `cms.catholicdigitalcommons.org`)

Default is `local`. Pass `--target production` (or `--target staging`) to act against the live or staging sites — for example to revalidate a path or fix a content typo. The override files are gitignored; the example templates are `.env.<target>.example`.

**IMPORTANT: NEVER read, cat, or access `.env.local`, `.env.staging`, or `.env.production` directly.** They contain secrets (API keys, passwords). Always use the Python client instead, which loads credentials internally.

**Always use the CLI interface** (`scripts/.venv/bin/python scripts/cdcf_api.py <command>`) — never try to import `cdcf_api` directly in inline Python (`python -c`), as the module is not on `PYTHONPATH` and will fail with `ModuleNotFoundError`. If you need programmatic access beyond what the CLI offers, run: `scripts/.venv/bin/python -c "import sys; sys.path.insert(0, 'scripts'); from cdcf_api import CdcfClient; ..."`

### Setup

```bash
uv venv scripts/.venv
uv pip install -r scripts/requirements.txt --python scripts/.venv/bin/python
```

### CLI Usage

```bash
# Post meta / ACF fields
scripts/.venv/bin/python scripts/cdcf_api.py get-post --post-id 702 --post-type team_member
scripts/.venv/bin/python scripts/cdcf_api.py get-meta --post-id 702 --post-type team_member --field member_title
scripts/.venv/bin/python scripts/cdcf_api.py update-meta --post-id 702 --post-type team_member --fields '{"member_title": "AI Specialist"}'

# REST API calls
scripts/.venv/bin/python scripts/cdcf_api.py get-relationship --post-id 5 --field team_members
scripts/.venv/bin/python scripts/cdcf_api.py create-team-member --title "Name" --content "<p>Bio</p>" --council technical_council
scripts/.venv/bin/python scripts/cdcf_api.py create-team-member --title "Name" --content "<p>Bio</p>"  # project-only member, no council
scripts/.venv/bin/python scripts/cdcf_api.py create-academic-collaboration --title "University Name" --collab-description "Description" --collab-university "University"
scripts/.venv/bin/python scripts/cdcf_api.py translate-post --source-id 255 --target-lang it

# GraphQL queries
scripts/.venv/bin/python scripts/cdcf_api.py get-translation-ids --post-id 5
scripts/.venv/bin/python scripts/cdcf_api.py get-post-language --post-id 12 --post-type project
scripts/.venv/bin/python scripts/cdcf_api.py graphql --query '{ pages(first: 5) { nodes { databaseId title } } }'

# Generic REST calls (any endpoint)
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/update-disposable-domains
scripts/.venv/bin/python scripts/cdcf_api.py rest-post cdcf/v1/flush-opcache
scripts/.venv/bin/python scripts/cdcf_api.py rest-get wp/v2/posts --params '{"per_page": 5}'

# Cache revalidation (local stack)
scripts/.venv/bin/python scripts/cdcf_api.py revalidate --path /about

# Cache revalidation against the live production site
scripts/.venv/bin/python scripts/cdcf_api.py --target production revalidate --path /it/simbolismo-del-logo

# Pause/resume the queue worker (used by the deploy workflow)
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action begin --duration 300
scripts/.venv/bin/python scripts/cdcf_api.py maintenance --action end
```

See `docs/python-api-client.md` for full documentation of all commands and library usage.

## Environment Variables

**NEVER read `.env.local` directly — it contains secrets.** Use the Python API client (`scripts/cdcf_api.py`) to interact with the CMS, which loads credentials internally.

Required in `.env.local` (Next.js) or `.env` (Docker Compose):

- `WP_GRAPHQL_URL` — GraphQL endpoint, host-perspective (e.g. `http://localhost:8000/graphql` in dev). The dockerized `nextjs` service in `docker-compose.yml` overrides this to `http://wordpress/graphql` for its own runtime when `--profile production` is active.
- `WP_REST_URL` — WordPress REST base URL, same host-perspective convention as above (used by `npm run dev` on the host and by `scripts/cdcf_api.py`)
- `WP_APP_USERNAME`, `WP_APP_PASSWORD` — WordPress Application Password (used by the Python client)
- `WP_PREVIEW_SECRET` — Shared secret for preview + revalidation
- `WP_DB_ROOT_PASSWORD`, `WP_DB_NAME`, `WP_DB_USER`, `WP_DB_PASSWORD` — Database config
- Docker Compose reads `.env` not `.env.local` for variable substitution

## Deployment

Deploys via GitHub Actions (`deploy.yml`, SSH tar + scp). A published GitHub **release** always deploys to **production**. A manual `workflow_dispatch` takes an `environment` input (`production` or `staging`) that **defaults to `staging`**.

**The target controls what ships:**

- **`production`** — ships **both** the Next.js frontend **and** the WordPress backend (theme + plugin tarballs, plugin activation, OPcache flush). Use this for any change under `wordpress/themes/**` or `wordpress/plugins/**` — `functions.php`, REST handlers/routes, CPT/ACF registration, etc.
- **`staging`** — ships **only** the Next.js frontend. All WordPress theme/plugin steps are gated on `env.ENVIRONMENT == 'production'` and are **skipped** on staging (staging shares the production WP backend, so it must never push theme/plugin from a staging deploy).

> ⚠️ A bare `gh workflow run deploy.yml` runs as **staging**, so backend changes are **not** deployed — yet the run still succeeds (the frontend ships and smoke tests pass). Symptom: a new `cdcf/v1` route 404s or theme behaviour is unchanged after a "green" deploy. Always pass `-f environment=production` for backend changes.

```bash
# Deploy frontend + backend (theme/plugin changes) — after pushing to main:
gh workflow run deploy.yml -f environment=production

# Deploy frontend only (Next.js):
gh workflow run deploy.yml -f environment=staging   # or just: gh workflow run deploy.yml

# Confirm the WP theme/plugin steps actually ran (must be `success`, not `skipped`):
gh run view <run-id> --json jobs \
  -q '.jobs[].steps[] | select(.name|test("WP theme|OPcache|plugins")) | "\(.conclusion)\t\(.name)"'
```

The scp upload step occasionally fails transiently with `kex_exchange_identification: read: Connection reset by peer` (VPS SSH rate-limit after back-to-back deploys) — just re-run.
