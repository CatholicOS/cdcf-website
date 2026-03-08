# Zitadel OIDC Integration Plan

## Context

The CDCF website currently has no user authentication on the Next.js frontend, and WordPress uses its native username/password login. The goal is to add Zitadel as a centralized OIDC identity provider to:

1. Enable **passkey (WebAuthn) login** to the WordPress admin
2. Prepare the Next.js frontend for **authenticated member features** in the future

This plan covers both phases but **defers implementation** — it documents exactly what to build and where.

---

## Phase 1: Zitadel + WordPress OIDC

### 1.1 Add Zitadel to Docker Compose

**File:** `docker-compose.yml`

Add two new services and one new volume:

```yaml
zitadel-db:
  image: postgres:16-alpine
  restart: unless-stopped
  environment:
    POSTGRES_USER: zitadel
    POSTGRES_PASSWORD: ${ZITADEL_DB_PASSWORD}
    POSTGRES_DB: zitadel
  volumes:
    - zitadel_db_data:/var/lib/postgresql/data
  healthcheck:
    test: ["CMD-SHELL", "pg_isready -U zitadel -d zitadel"]
    interval: 5s
    timeout: 3s
    retries: 5

zitadel:
  image: ghcr.io/zitadel/zitadel:latest
  restart: unless-stopped
  depends_on:
    zitadel-db:
      condition: service_healthy
  command: start-from-init
  environment:
    ZITADEL_DATABASE_POSTGRES_HOST: zitadel-db
    ZITADEL_DATABASE_POSTGRES_PORT: 5432
    ZITADEL_DATABASE_POSTGRES_DATABASE: zitadel
    ZITADEL_DATABASE_POSTGRES_USER_USERNAME: zitadel
    ZITADEL_DATABASE_POSTGRES_USER_PASSWORD: ${ZITADEL_DB_PASSWORD}
    ZITADEL_DATABASE_POSTGRES_USER_SSL_MODE: disable
    ZITADEL_DATABASE_POSTGRES_ADMIN_USERNAME: zitadel
    ZITADEL_DATABASE_POSTGRES_ADMIN_PASSWORD: ${ZITADEL_DB_PASSWORD}
    ZITADEL_DATABASE_POSTGRES_ADMIN_SSL_MODE: disable
    ZITADEL_MASTERKEY: ${ZITADEL_MASTERKEY}
    ZITADEL_EXTERNALDOMAIN: ${ZITADEL_EXTERNAL_DOMAIN:-localhost}
    ZITADEL_EXTERNALPORT: ${ZITADEL_EXTERNAL_PORT:-8085}
    ZITADEL_EXTERNALSECURE: ${ZITADEL_EXTERNAL_SECURE:-false}
    ZITADEL_TLS_ENABLED: "false"
    ZITADEL_FIRSTINSTANCE_ORG_HUMAN_USERNAME: ${ZITADEL_ADMIN_USER:-zitadel-admin}
    ZITADEL_FIRSTINSTANCE_ORG_HUMAN_PASSWORD: ${ZITADEL_ADMIN_PASSWORD:-Password1!}
  ports:
    - "8085:8080"
```

Add `zitadel_db_data:` to the `volumes:` section.

**Design notes:**
- Port 8085 avoids conflicts with Nginx (80) and Next.js dev (3000)
- `start-from-init` is idempotent — initializes on first run, starts normally after
- Zitadel requires PostgreSQL (can't share the existing MariaDB)
- No Nginx proxying needed for dev — browser hits `localhost:8085` directly, WordPress reaches `zitadel:8080` on the Docker network

### 1.2 WordPress OIDC Plugin

**Plugin:** [`openid-connect-generic`](https://wordpress.org/plugins/daggerhart-openid-connect-generic/) by daggerhart

**Why this plugin:**
- Most widely used open-source WP OIDC client, actively maintained
- Configurable via `wp-config.php` constants (works with Docker env-driven config)
- Adds a "Login with OpenID Connect" button alongside the standard WP login form — doesn't replace it
- `OIDC_LINK_EXISTING_USERS` links OIDC logins to existing WP accounts by email match
- Application Passwords continue working (they bypass OIDC entirely)

**File:** `wordpress/init.sh` — add after existing plugin installs (line ~68):

```bash
wp plugin install daggerhart-openid-connect-generic --activate --allow-root
```

**File:** `docker-compose.yml` — add OIDC constants to the `wordpress` service's `WORDPRESS_CONFIG_EXTRA`:

```php
define('OIDC_LOGIN_TYPE', 'button');
define('OIDC_CLIENT_ID', '${ZITADEL_WP_CLIENT_ID}');
define('OIDC_CLIENT_SECRET', '${ZITADEL_WP_CLIENT_SECRET}');
define('OIDC_ENDPOINT_LOGIN_URL', 'http://localhost:8085/oauth/v2/authorize');
define('OIDC_ENDPOINT_USERINFO_URL', 'http://zitadel:8080/oidc/v1/userinfo');
define('OIDC_ENDPOINT_TOKEN_URL', 'http://zitadel:8080/oauth/v2/token');
define('OIDC_ENDPOINT_LOGOUT_URL', 'http://localhost:8085/oidc/v1/end_session');
define('OIDC_CLIENT_SCOPE', 'openid email profile');
define('OIDC_IDENTITY_KEY', 'preferred_username');
define('OIDC_NICKNAME_KEY', 'preferred_username');
define('OIDC_EMAIL_FORMAT', '{email}');
define('OIDC_DISPLAYNAME_FORMAT', '{given_name} {family_name}');
define('OIDC_CREATE_IF_DOES_NOT_EXIST', true);
define('OIDC_LINK_EXISTING_USERS', true);
define('OIDC_REDIRECT_USER_BACK', true);
```

**Note:** The login and logout endpoints use `localhost:8085` (browser-facing), while token and userinfo endpoints use `zitadel:8080` (server-to-server via Docker network).

### 1.3 Zitadel Configuration (Manual, Post-Boot)

After `docker compose up`, access `http://localhost:8085/ui/console` and:

1. **Create project** "CDCF"
2. **Create WordPress app** (Web, client_secret_post)
   - Redirect URI: `http://localhost/wp-admin/admin-ajax.php?action=openid-connect-authorize`
   - Post-logout URI: `http://localhost/wp-login.php`
3. **Create Next.js app** (Web, client_secret_post) — for Phase 2
   - Redirect URI: `http://localhost:3000/api/auth/callback/zitadel`
   - Post-logout URI: `http://localhost:3000`
   - Enable Dev Mode (allows HTTP redirects)
4. **Enable passkeys:** Settings > Login Behavior > Passwordless Type = "Allowed"
5. **Define roles** in the CDCF project: `admin`, `editor`, `member`
6. **Create user accounts** matching existing WordPress admin emails

### 1.4 Environment Variables

**File:** `.env.local.example` — add:

```bash
# ─── Zitadel (Identity Provider) ──────────────────────────────────
ZITADEL_DB_PASSWORD=
ZITADEL_MASTERKEY=                    # exactly 32 chars, generate once, never change
ZITADEL_EXTERNAL_DOMAIN=localhost
ZITADEL_EXTERNAL_PORT=8085
ZITADEL_EXTERNAL_SECURE=false
ZITADEL_ADMIN_USER=zitadel-admin
ZITADEL_ADMIN_PASSWORD=
ZITADEL_ISSUER_URL=http://localhost:8085

# WordPress OIDC (from Zitadel console after creating WP app)
ZITADEL_WP_CLIENT_ID=
ZITADEL_WP_CLIENT_SECRET=

# Next.js Auth.js (from Zitadel console after creating Next.js app)
AUTH_ZITADEL_ID=
AUTH_ZITADEL_SECRET=
AUTH_SECRET=                          # openssl rand -base64 32
```

---

## Phase 2: Next.js Frontend Auth

### 2.1 Install Auth.js v5

```bash
npm install next-auth@beta
```

### 2.2 New Files to Create

| File | Purpose |
|------|---------|
| `lib/auth.ts` | Auth.js config — Zitadel OIDC provider, JWT session strategy, role extraction from `urn:zitadel:iam:org:project:roles` claim |
| `app/api/auth/[...nextauth]/route.ts` | Auth.js route handler (`export { GET, POST } from handlers`) |
| `types/next-auth.d.ts` | TypeScript augmentation — add `accessToken` and `roles` to Session/JWT types |
| `components/AuthButton.tsx` | Client component — sign in/out button using `useSession()` |
| `lib/auth-utils.ts` | Server helpers — `requireAuth()`, `requireRole(role)`, `hasRole(session, role)` |

### 2.3 Modify Existing Files

**`middleware.ts`** — Compose Auth.js `auth()` wrapper with existing next-intl middleware:
- Wrap the export with `auth()` to populate `req.auth` on every request
- Check protected paths (e.g. `/dashboard`, `/profile`) and redirect unauthenticated users to `/login`
- Fall through to the existing `createMiddleware(routing)` for all other routes
- Add `api/auth` to the matcher exclusion list

**`app/[lang]/layout.tsx`** — Wrap children with `<SessionProvider>`:
- Import `SessionProvider` from `next-auth/react`
- Call `auth()` server-side to get the session
- Wrap `NextIntlClientProvider` children with `<SessionProvider session={session}>`

**`components/Header.tsx`** — Add `<AuthButton />` to the header navigation

**`messages/en.json`** (and other locales) — Add `Auth.signIn` and `Auth.signOut` keys

### 2.4 WordPress Bearer Token Validation

**File:** `wordpress/themes/cdcf-headless/functions.php`

Add a `determine_current_user` filter (priority 20) that:
1. Checks for a `Bearer` token in the `Authorization` header
2. Validates it against Zitadel's `/oidc/v1/userinfo` endpoint
3. Looks up the WordPress user by the email from the userinfo response
4. Returns the WP user ID (or falls through if validation fails)

This allows Next.js to make authenticated WordPress REST API calls using the Zitadel access token from the user's Auth.js session — no separate WordPress login needed.

Existing auth methods (cookies, Application Passwords) are checked first and remain unaffected.

---

## Production Deployment

| Component | Domain | Notes |
|-----------|--------|-------|
| Zitadel | `auth.catholicdigitalcommons.org` | Docker or binary install on Plesk, TLS via Let's Encrypt |
| WordPress | `cms.catholicdigitalcommons.org` | Install OIDC plugin, configure endpoints to `auth.*` domain |
| Next.js | `staging.catholicdigitalcommons.org` | Set `AUTH_*` env vars, register callback URI in Zitadel |

Production env overrides:
```
ZITADEL_EXTERNAL_DOMAIN=auth.catholicdigitalcommons.org
ZITADEL_EXTERNAL_PORT=443
ZITADEL_EXTERNAL_SECURE=true
ZITADEL_ISSUER_URL=https://auth.catholicdigitalcommons.org
```

**Migration:** Create Zitadel users with matching emails for existing WP admins. The OIDC plugin's `OIDC_LINK_EXISTING_USERS` links them on first login. Application Passwords are unaffected.

---

## Verification Steps

### Phase 1
1. `docker compose up` — verify Zitadel boots (`curl http://localhost:8085/debug/ready`)
2. Access Zitadel console, create project + apps, note client IDs/secrets
3. Set env vars, restart WordPress
4. Visit `http://localhost/wp-login.php` — verify "Login with OpenID Connect" button appears
5. Click it, authenticate via Zitadel, verify redirect to wp-admin
6. Register a passkey in Zitadel, verify passkey login works
7. Verify Python CLI still works: `scripts/.venv/bin/python scripts/cdcf_api.py get-relationship --post-id 5 --field team_members`

### Phase 2
1. `npm run build` — verify no TypeScript errors
2. `curl http://localhost:3000/api/auth/providers` — verify Zitadel provider listed
3. Click "Sign In" in header, verify redirect to Zitadel, authenticate, verify redirect back
4. Access a protected route while logged out — verify redirect to login
5. Verify role claims appear in `session.user.roles`

---

## Files Summary

### New files (Phase 2)
- `lib/auth.ts`
- `app/api/auth/[...nextauth]/route.ts`
- `types/next-auth.d.ts`
- `components/AuthButton.tsx`
- `lib/auth-utils.ts`

### Modified files
- `docker-compose.yml` — Zitadel services + WP OIDC config (Phase 1)
- `wordpress/init.sh` — plugin install (Phase 1)
- `.env.local.example` — new env vars (Phase 1)
- `wordpress/themes/cdcf-headless/functions.php` — Bearer token filter (Phase 2)
- `middleware.ts` — Auth.js + next-intl composition (Phase 2)
- `app/[lang]/layout.tsx` — SessionProvider wrapper (Phase 2)
- `components/Header.tsx` — AuthButton (Phase 2)
- `messages/*.json` — Auth translation keys (Phase 2)
- `package.json` — next-auth dependency (Phase 2)
