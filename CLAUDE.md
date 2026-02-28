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
- **CPTs:** project, team_member, sponsor, community_channel, stat_item (all with `show_in_graphql: true`, `has_archive: false`)
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

### `POST /team-member`

Creates an English `team_member` post, translates it to all 5 languages via OpenAI, and appends each translation to the matching language version of the About page's council relationship field.

**Parameters:** `title` (required), `content` (required), `council` (required — one of `team_members`, `ecclesial_council`, `technical_council`), `member_title`, `member_role`, `member_linkedin_url`, `member_github_url`, `featured_image_id`

**Returns:** `{ success, en_post_id, translations: { en, it, es, fr, pt, de }, council, errors[] }`

## Adding a New Language

1. Add locale to `src/i18n/routing.ts` → `locales` array
2. Create `messages/<locale>.json`
3. Add label in `components/LanguageSwitcher.tsx` → `localeLabels`
4. Add mapping in `lib/wordpress/api.ts` → `LOCALE_MAP`
5. Add language in WordPress Polylang settings

## Environment Variables

Required in `.env.local` (Next.js) or `.env` (Docker Compose):
- `WORDPRESS_GRAPHQL_URL` — GraphQL endpoint (e.g. `http://wordpress/graphql`)
- `WORDPRESS_PREVIEW_SECRET` — Shared secret for preview + revalidation
- `WP_DB_ROOT_PASSWORD`, `WP_DB_NAME`, `WP_DB_USER`, `WP_DB_PASSWORD` — Database config
- Docker Compose reads `.env` not `.env.local` for variable substitution
