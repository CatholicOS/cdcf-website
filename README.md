# Catholic Digital Commons Foundation — Website CMS

The official website for the **Catholic Digital Commons Foundation (CDCF)**, built with Next.js and headless WordPress. This site serves as the public-facing portal for the foundation, showcasing projects, community resources, news, and governance information.

## Tech Stack

- **Framework:** Next.js 15 (App Router, React Server Components)
- **CMS:** WordPress (headless) with WPGraphQL + ACF + Polylang
- **Styling:** Tailwind CSS v4 + custom CDCF brand system
- **i18n:** next-intl (UI chrome) + Polylang (CMS content)
- **Translation Management:** Weblate (for `messages/*.json` UI strings)
- **Development:** Docker Compose (WordPress + MariaDB + Next.js + Nginx)
- **Production:** Native on Plesk (WordPress + Next.js standalone) with GitHub Actions CI/CD

## Getting Started

### Prerequisites

- Node.js 22+
- npm 10+
- Docker & Docker Compose (for local development)

### Installation

```bash
# Clone the repository
git clone https://github.com/CatholicOS-org/cdcf-website.git
cd cdcf-website

# Install frontend dependencies
npm install

# Copy environment template
cp .env.local.example .env.local
```

### Environment Variables

Edit `.env.local`:

| Variable | Description |
|----------|-------------|
| `WORDPRESS_GRAPHQL_URL` | WordPress GraphQL endpoint (e.g. `http://localhost/graphql` or `http://wordpress/graphql` in Docker) |
| `WORDPRESS_PREVIEW_SECRET` | Shared secret for Next.js draft mode preview |
| `WP_DB_ROOT_PASSWORD` | MariaDB root password |
| `WP_DB_NAME` | WordPress database name (default: `wordpress`) |
| `WP_DB_USER` | WordPress database user (default: `wordpress`) |
| `WP_DB_PASSWORD` | WordPress database password |

### Development

#### Full Stack (Docker)

The recommended way to develop is with Docker, which starts WordPress, MariaDB, Next.js, and Nginx together:

```bash
docker compose up --build
```

- **Next.js frontend:** [http://localhost](http://localhost) (via Nginx) or [http://localhost:3000](http://localhost:3000) (direct)
- **WordPress admin:** [http://localhost/wp-admin](http://localhost/wp-admin)
- **GraphQL endpoint:** [http://localhost/graphql](http://localhost/graphql)

#### Frontend Only

If WordPress is already running elsewhere (e.g. a staging server), you can run just the Next.js frontend:

```bash
# Set WORDPRESS_GRAPHQL_URL in .env.local to point at your WordPress instance
npm run dev
```

Open [http://localhost:3000](http://localhost:3000).

### WordPress Setup (First Run)

**Using Docker (automatic):** The `wp-init` service in `docker-compose.yml` runs `wordpress/init.sh` on first boot, which automatically:
- Installs WordPress core with admin credentials from env vars
- Installs and activates all required plugins (WPGraphQL, ACF, WPGraphQL for ACF, Polylang, WPGraphQL Polylang)
- Activates the `cdcf-headless` theme
- Configures all 6 Polylang languages
- Creates all pages (Home, About, Projects, Community, Blog, Contact) with correct templates
- Seeds ACF field content and sample CPT entries (projects, team members, stat items, etc.)
- Optionally bulk-translates all content if `OPENAI_API_KEY` is set in `.env`

The script is idempotent — if WordPress is already installed, it skips everything.

**Manual setup (without Docker):** If installing WordPress natively (e.g. via Plesk), you need to:

1. **Install and activate required plugins:**
   - [WPGraphQL](https://wordpress.org/plugins/wp-graphql/)
   - [Advanced Custom Fields (ACF)](https://wordpress.org/plugins/advanced-custom-fields/)
   - [WPGraphQL for ACF](https://github.com/wp-graphql/wpgraphql-acf) (download from GitHub releases)
   - [Polylang](https://wordpress.org/plugins/polylang/)
   - [WPGraphQL Polylang](https://github.com/valu-digital/wp-graphql-polylang) (download from GitHub releases)

2. **Activate the headless theme:** copy `wordpress/themes/cdcf-headless/` into `wp-content/themes/` and activate **CDCF Headless** in Appearance > Themes

3. **Configure Polylang languages:** go to Languages > Settings and add: English (default), Italian, Spanish, French, Portuguese, German

4. **Create pages with templates:**
   - Create pages for Home, About, Projects, Community, Blog, Contact
   - Assign the corresponding page template to each (e.g. Home page → "Home" template)
   - Fill in the ACF fields (hero section, CTA, etc.) that appear for each template

5. **Create content:**
   - Add projects, team members, sponsors, community channels, and stat items as CPT entries
   - Link them to pages via the relationship fields in each page template's ACF group

### Production Build (standalone)

```bash
npm run build
npm start
```

## Project Structure

```
cdcf-website/
├── app/
│   ├── [lang]/                    # i18n dynamic segment
│   │   ├── layout.tsx             # Root layout with providers
│   │   └── [[...slug]]/           # Catch-all page renderer
│   │       └── page.tsx
│   └── api/
│       ├── preview/route.ts       # Draft mode endpoint for WP previews
│       └── revalidate/route.ts    # On-demand ISR webhook
├── components/
│   ├── Header.tsx                 # Site header with nav + language switcher
│   ├── Footer.tsx                 # Multi-column footer
│   ├── Logo.tsx                   # SVG logo wrapper
│   ├── LanguageSwitcher.tsx       # Locale dropdown
│   └── sections/                  # Page section components
│       ├── PageRenderer.tsx       # Template-based section orchestrator
│       ├── HeroBanner.tsx         # Full-width hero section
│       ├── TextSection.tsx        # Text block with heading + body
│       ├── RichContent.tsx        # Two-column text + image layout
│       ├── CallToAction.tsx       # CTA banner / card / inline
│       ├── StatsBar.tsx           # Statistics counter row
│       ├── ProjectGrid.tsx        # Project card grid
│       ├── CommunitySection.tsx   # Community channel cards
│       ├── GovernanceSection.tsx   # Team member grid
│       ├── BlogFeed.tsx           # Blog post listing
│       └── SponsorGrid.tsx        # Sponsor logos grid
├── lib/
│   └── wordpress/
│       ├── client.ts              # wpQuery() GraphQL fetch wrapper
│       ├── queries.ts             # GraphQL query strings
│       ├── types.ts               # TypeScript interfaces for WP data
│       └── api.ts                 # Typed API functions (getPage, getPosts, etc.)
├── src/
│   └── i18n/
│       ├── routing.ts             # Locale list + routing config
│       ├── request.ts             # Per-request message loading
│       └── navigation.ts          # Typed navigation helpers
├── messages/                      # UI translation strings (managed via Weblate)
│   ├── en.json                    # English (source)
│   ├── it.json                    # Italian
│   ├── es.json                    # Spanish
│   ├── fr.json                    # French
│   ├── pt.json                    # Portuguese
│   └── de.json                    # German
├── css/
│   └── globals.css                # Tailwind imports + brand utilities
├── public/
│   └── logo.svg                   # CDCF globe/cross logo
├── wordpress/
│   └── themes/
│       └── cdcf-headless/         # Headless WordPress theme
│           ├── style.css          # Theme metadata
│           ├── index.php          # Redirect to Next.js frontend
│           └── functions.php      # CPTs, ACF fields, Polylang, CORS, preview
├── nginx/
│   └── default.conf               # Nginx reverse proxy (Next.js + WordPress)
├── .github/
│   └── workflows/
│       └── deploy.yml             # CI/CD pipeline
├── Dockerfile                     # Multi-stage Next.js Docker build
├── docker-compose.yml             # WordPress + MariaDB + Next.js + Nginx
├── tailwind.config.ts             # Brand colors + fonts
├── next.config.ts                 # Next.js configuration
└── package.json
```

## Architecture

### How It Works

1. **WordPress** manages all CMS content — pages, posts, projects, team members, sponsors, etc.
2. **ACF field groups** are registered programmatically in `functions.php` and provide structured fields for each page template (hero section, CTA, relationships to CPTs).
3. **WPGraphQL** exposes all content (including ACF fields and Polylang translations) via a `/graphql` endpoint.
4. **Next.js** fetches content from the GraphQL API at build/request time using the `lib/wordpress/` client library.
5. **PageRenderer** maps page templates to fixed section layouts — each template renders its sections in a predetermined order using data from ACF fields and related CPTs.
6. In **development**, Nginx routes requests on a single `localhost` domain: WordPress paths (`/wp-admin`, `/graphql`, `/wp-content`) go to WordPress; everything else goes to Next.js. In **production**, WordPress and Next.js run on separate subdomains managed by Plesk.

### Content Model

| Page Template | Sections (fixed order) |
|---------------|----------------------|
| Home | Hero, Stats, Featured Projects, Sponsors, CTA |
| About | Hero, Content, Team/Governance, CTA |
| Projects | Hero, Project Grid, CTA |
| Community | Hero, Channels, Team/Governance, CTA |
| Blog | Hero, Blog Feed |
| Contact | Hero, Content, CTA |

### Custom Post Types

| CPT | Purpose | Key ACF Fields |
|-----|---------|---------------|
| `project` | Foundation projects | status, repoUrl, projectUrl, license, category |
| `team_member` | Team/governance members | role, title, linkedinUrl, githubUrl |
| `sponsor` | Sponsors and partners | tier, sponsorUrl |
| `community_channel` | Community platforms | icon, channelUrl, description |
| `stat_item` | Statistics counters | icon, number, label |

## CMS Editing Guide

### For Content Editors

1. Navigate to `/wp-admin` and log in with your WordPress credentials
2. **Edit pages:** Go to Pages, select a page, and fill in the ACF fields (hero, CTA, relationships)
3. **Create projects:** Go to Projects > Add New, fill in title, description, featured image, and ACF fields (status, repo URL, etc.)
4. **Manage team:** Go to Team Members > Add New, fill in name, bio, photo, and role/social links
5. **Publish:** Save/publish in WordPress. Changes appear on the frontend after ISR revalidation (default: 60 seconds) or immediately via the revalidation webhook.

### Creating Translations

1. Install and configure Polylang in WordPress
2. When editing any page or CPT entry, use the Polylang language meta box to create translations
3. Each translation is a separate WordPress post linked to the original
4. The Next.js frontend automatically fetches the correct translation based on the URL locale

## i18n / Translation Workflow

This project uses a **dual i18n system**:

### UI Strings (next-intl + Weblate)

- **Source files:** `messages/*.json`
- **Source language:** English (`messages/en.json`)
- **Workflow:**
  1. Developers modify `messages/en.json` and push to `main`
  2. Weblate watches the repo and picks up new/changed strings
  3. Translators translate via the Weblate web UI
  4. Weblate pushes translations to the `l10n-weblate` branch
  5. PR from `l10n-weblate` → `main` for review
  6. Merge triggers rebuild and deploy

### CMS Content (WordPress + Polylang)

- Content translations are managed in WordPress using Polylang
- Each page/post/CPT can have independent translations per locale
- Translations are fetched at render time via WPGraphQL Polylang based on the URL locale

## Preview & Revalidation

### Draft Preview

WordPress is configured to redirect preview links to the Next.js draft mode endpoint:

```
GET /api/preview?secret=YOUR_SECRET&slug=about&type=page
```

This enables Next.js draft mode, which fetches the latest revision from WordPress (bypassing ISR cache).

### On-Demand Revalidation

When content is published in WordPress, a webhook can trigger immediate cache invalidation:

```bash
curl -X POST http://localhost:3000/api/revalidate \
  -H "Content-Type: application/json" \
  -d '{"secret": "YOUR_SECRET", "path": "/about"}'
```

You can set this up as a WordPress publish hook (e.g. via the WP Webhooks plugin or a custom `save_post` action).

## Adding a New Language

1. Add the locale code to `src/i18n/routing.ts` in the `locales` array
2. Create `messages/<locale>.json` (copy from `messages/en.json`)
3. Add the locale label in `components/LanguageSwitcher.tsx` → `localeLabels`
4. Add the locale mapping in `lib/wordpress/api.ts` → `LOCALE_MAP`
5. Add the language in WordPress via Polylang settings
6. Configure the new language in Weblate for UI string translation

## Deployment

### Production Architecture

Production runs natively on a Plesk-managed server (no Docker) with two subdomains:

- **`staging.catholicdigitalcommons.org`** — Next.js frontend (standalone build running via Node.js)
- **`cms.catholicdigitalcommons.org`** — WordPress admin backend (PHP-FPM managed by Plesk)

WordPress and Next.js share the same MariaDB instance already running on the server. Plesk manages Nginx, SSL certificates, and PHP-FPM.

The Next.js app fetches content from WordPress via `WORDPRESS_GRAPHQL_URL=https://cms.catholicdigitalcommons.org/graphql`. The WordPress theme's CORS headers (registered in `functions.php`) allow cross-origin GraphQL requests from the frontend subdomain.

### GitHub Actions CI/CD

The deploy workflow (`.github/workflows/deploy.yml`) triggers when a GitHub release is published. It builds the Next.js standalone output in CI, then SCPs the artifacts to the VPS (no repo clone needed on the server).

**Required GitHub Secrets:**

| Secret | Description |
|--------|-------------|
| `WORDPRESS_GRAPHQL_URL` | WordPress GraphQL endpoint (e.g. `https://cms.catholicdigitalcommons.org/graphql`) |
| `WORDPRESS_PREVIEW_SECRET` | Shared secret for preview/revalidation |
| `VPS_HOST` | VPS IP address or hostname |
| `VPS_USERNAME` | SSH username |
| `VPS_SSH_KEY` | SSH private key for deployment |
| `VPS_APP_DIR` | Directory on the VPS where the Next.js standalone app runs |
| `WP_THEME_DIR` | WordPress theme directory (e.g. `/var/www/vhosts/.../wp-content/themes/cdcf-headless`) |

### Docker (Local Development Only)

Docker Compose is used for local development to run the full stack:

```bash
# Build and run all services
docker compose up --build -d

# View logs
docker compose logs -f

# Stop all services
docker compose down
```

Data is persisted in Docker named volumes (`db_data` for MariaDB, `wordpress_data` for WordPress uploads/plugins).

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Make your changes
4. Test locally with `npm run dev` and `npm run build`
5. Submit a pull request

## License

All rights reserved. Copyright Catholic Digital Commons Foundation.
