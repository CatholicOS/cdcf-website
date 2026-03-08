# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Development Commands

```bash
npm run dev          # Start Next.js dev server (localhost:3000)
npm run build        # Production build (catches TypeScript errors)
npm run lint         # ESLint with Next.js rules
npm start            # Start production server
docker compose up --build   # Full stack: WordPress + MariaDB + Next.js + Nginx
```

No test runner is configured. Use `npm run build` to verify type-correctness.

## Architecture Overview

Headless CMS: Next.js 15 (App Router) frontend fetches content from WordPress via WPGraphQL.

- **Development:** Docker Compose runs everything on `localhost` with Nginx reverse-proxying WordPress paths (`/wp-admin`, `/graphql`, `/wp-content`) and everything else to Next.js.
- **Production:** Two subdomains on Plesk (no Docker). `staging.catholicdigitalcommons.org` runs Next.js standalone, `cms.catholicdigitalcommons.org` runs WordPress. Cross-origin GraphQL requests are allowed via CORS headers in the theme.

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

All endpoints require Application Password authentication (`edit_posts` capability).

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/relationship` | Read an ACF relationship field (`post_id`, `field`) |
| `POST` | `/relationship` | Update an ACF relationship field (`post_id`, `field`, `value[]`) |
| `POST` | `/translate` | Translate a post to a target language via OpenAI (`source_id`, `target_lang`, optional `post_id`) |
| `POST` | `/team-member` | Create a team member with auto-translation and About page linking (see below) |
| `POST` | `/community-channel` | Create a community channel with auto-translation and Community page linking (see below) |
| `POST` | `/local-group` | Create a local group with auto-translation and Community page linking (see below) |
| `POST` | `/academic-collaboration` | Create an academic collaboration with auto-translation and Community page linking (see below) |

### `POST /team-member`

Creates an English `team_member` post, translates it to all 5 languages via OpenAI, and appends each translation to the appropriate relationship field. For `team_members`, `ecclesial_council`, and `technical_council`, members are linked to the About page's council field. For `academic_council`, members are linked to the academic collaboration post's `collab_governance` field (requires `collab_post_id`).

**Parameters:** `title` (required), `content` (required), `council` (required — one of `team_members`, `ecclesial_council`, `technical_council`, `academic_council`), `member_title`, `member_role`, `member_linkedin_url`, `member_github_url`, `featured_image_id`, `collab_post_id` (required for `academic_council` — the English academic collaboration post ID)

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

## Adding a New Language

1. Add locale to `src/i18n/routing.ts` → `locales` array
2. Create `messages/<locale>.json`
3. Add label in `components/LanguageSwitcher.tsx` → `localeLabels`
4. Add mapping in `lib/wordpress/api.ts` → `LOCALE_MAP`
5. Add language in WordPress Polylang settings

## Python API Client (`scripts/cdcf_api.py`)

A Python client library and CLI that wraps all `cdcf/v1` REST endpoints and WPGraphQL queries. It reads credentials from `.env.local` and `.env` internally so secrets are never exposed.

**IMPORTANT: NEVER read, cat, or access `.env.local` directly.** This file contains secrets (API keys, passwords). Always use the Python client instead, which loads credentials internally.

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
scripts/.venv/bin/python scripts/cdcf_api.py create-academic-collaboration --title "University Name" --collab-description "Description" --collab-university "University"
scripts/.venv/bin/python scripts/cdcf_api.py translate-post --source-id 255 --target-lang it

# GraphQL queries
scripts/.venv/bin/python scripts/cdcf_api.py get-translation-ids --post-id 5
scripts/.venv/bin/python scripts/cdcf_api.py get-post-language --post-id 12 --post-type project
scripts/.venv/bin/python scripts/cdcf_api.py graphql --query '{ pages(first: 5) { nodes { databaseId title } } }'

# Cache revalidation
scripts/.venv/bin/python scripts/cdcf_api.py revalidate --path /about
```

See `docs/python-api-client.md` for full documentation of all commands and library usage.

## Environment Variables

**NEVER read `.env.local` directly — it contains secrets.** Use the Python API client (`scripts/cdcf_api.py`) to interact with the CMS, which loads credentials internally.

Required in `.env.local` (Next.js) or `.env` (Docker Compose):
- `WP_GRAPHQL_URL` — GraphQL endpoint (e.g. `http://wordpress/graphql`)
- `WP_REST_URL` — WordPress REST base URL (used by the Python client)
- `WP_APP_USERNAME`, `WP_APP_PASSWORD` — WordPress Application Password (used by the Python client)
- `WP_PREVIEW_SECRET` — Shared secret for preview + revalidation
- `WP_DB_ROOT_PASSWORD`, `WP_DB_NAME`, `WP_DB_USER`, `WP_DB_PASSWORD` — Database config
- Docker Compose reads `.env` not `.env.local` for variable substitution

## Deployment

Deploys to production via GitHub Actions (SSH tar + scp). Automatic on new release; otherwise trigger manually:

```bash
gh workflow run deploy.yml      # after pushing to main
```
