# Zitadel sign-in for the WordPress CMS

**Status:** design, awaiting review. Tracked by
[#291](https://github.com/CatholicOS/cdcf-website/issues/291).

## Problem

Passwordless (passkey) sign-in through Zitadel works on the Next.js frontend.
`wp-login.php` still offers only the native username/password form, so staff
who authenticate with a passkey on the frontend must maintain a separate
WordPress password to reach wp-admin.

## Scope

**Passkeys for existing staff.** Editors and administrators who already hold
WordPress accounts sign in to wp-admin through Zitadel instead of a WordPress
password.

Out of scope:

- **Role mirroring and privilege elevation.** Frontend-registered users are
  auto-provisioned as `subscriber`, and a subscriber reaching wp-admin sees
  only their own profile. Making the CMS usable for them is Phase 6 of the
  role-mirroring work, not this.
- **Retiring the native login form.** It stays as the break-glass path.
  Application Passwords and the REST bearer path are untouched.
- **Provisioning the Zitadel app.** `cdcf-infra` owns that; this document
  records the app shape the two repos must agree on.

## Decisions

Four decisions constrain everything below. They were settled before design and
are the things to push back on first if the design looks wrong.

1. **The login path links; it never creates.** An identity that authenticates
   successfully but resolves to no WordPress user is rejected. This diverges
   deliberately from the REST bearer path, which auto-provisions a Subscriber.
2. **Logout is conditional.** A session that authenticated through Zitadel
   ends the upstream Zitadel session on logout; a session that authenticated
   with a WordPress password gets plain local logout.
3. **`cdcf-infra` provisions the client first.** The WordPress OIDC app is a
   prerequisite, not part of this work.
4. **The flow lives in the theme, with the identity layer extracted.** Not a
   new plugin — but the shared identity code moves into its own file so a
   later move out of the theme is a file move rather than a rewrite.

## Architecture

### File layout

```text
wordpress/themes/cdcf-headless/includes/auth/
  zitadel-config.php     NEW      issuer + endpoint derivation, client config
  zitadel-identity.php   NEW      JWT decode, sub/email resolution, auto-provision
  zitadel-bearer.php     TRIMMED  REST bearer path only; requires the two above
  zitadel-login.php      NEW      wp-login.php authorization-code flow + logout
```

Hook wiring stays in `functions.php` beside the existing `require_once`
statements, matching the note at the foot of `zitadel-bearer.php`: keeping
`add_filter()` out of the include is what lets PHPUnit load these files
without stubbing it.

New files use the single-line `defined('ABSPATH') || exit;` guard. The
multi-line `if` form costs two lines of Codecov patch coverage.

### Identity extraction

Moved out of `zitadel-bearer.php` into `zitadel-identity.php` with no
behaviour change:

- `cdcf_zitadel_decode_jwt_payload()` — renamed from
  `cdcf_zitadel_bearer_decode_jwt_payload()`; both paths decode tokens now
- `cdcf_zitadel_user_by_sub()`
- `cdcf_zitadel_auto_provision_subscriber()`

Decision 1 splits the resolver in two:

```php
// Steps 1-2 only: sub lookup (with email-drift sync) then email fallback
// (which stamps the sub). Returns 0 when no such user exists.
cdcf_zitadel_resolve_existing_user(string $sub, string $email): int

// Steps 1-3: the above, then auto-provision. Behaviour and name unchanged.
cdcf_zitadel_bearer_resolve_user(string $sub, string $email, array $profile): int
```

The login path calls the first and treats `0` as a hard reject. The bearer
path calls the second and keeps today's semantics exactly, so no existing
behaviour changes.

### Configuration

`zitadel-config.php` introduces one new constant and derives the rest,
following the `defined() || define()` plus `wp-config.php` convention the
theme already uses for `CDCF_FRONTEND_URL` and `CDCF_PREVIEW_SECRET`.

| Constant                        | Default                                   | Purpose                                                                                            |
| ------------------------------- | ----------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `CDCF_ZITADEL_ISSUER`           | `https://auth.catholicdigitalcommons.org` | Base for all endpoint URLs                                                                         |
| `CDCF_ZITADEL_WP_CLIENT_ID`     | `''`                                      | The CMS's own Zitadel client                                                                       |
| `CDCF_ZITADEL_WP_CLIENT_SECRET` | `''`                                      | Confidential client secret                                                                         |
| `CDCF_ZITADEL_ORG_ID`           | `''`                                      | Optional; adds the Org-restriction scope, same semantics as `AUTH_ZITADEL_ORG_ID` in `lib/auth.ts` |

Authorize, token, userinfo and end-session URLs are derived from the issuer.
An empty client id or secret disables the login button entirely and makes the
callback refuse — the same fail-closed shape `CDCF_ZITADEL_EXPECTED_AUD`
already has for bearer tokens.

`CDCF_ZITADEL_USERINFO_URL` becomes issuer-derived rather than a hard-coded
`const`. This is an incidental fix the login flow forces: the current value
pins production, so bearer authentication cannot work against a local Zitadel
under any configuration. The default is unchanged, so production is
unaffected.

`CDCF_ZITADEL_EXPECTED_AUD` is deliberately **not** extended with the WordPress
client id. Login-path tokens arrive through an authenticated back-channel
exchange, not an attacker-controllable header, so they need no allow-list.
Adding the id there would let a CMS-minted token be replayed as a REST bearer
for no benefit.

### Zitadel app shape (the `cdcf-infra` prerequisite)

What `setup-zitadel.sh` must create (flag name is `cdcf-infra`'s to choose):

- Web app, **confidential** (client secret), authorization code + PKCE (S256)
- Token-endpoint auth method **`client_secret_basic`**, which the exchange in
  step 4 below assumes
- **JWT access tokens** (`OIDC_TOKEN_TYPE_JWT`) — the bearer validator already
  requires them, and the login path's userinfo round-trip assumes the same
- Redirect URI: `<cms-origin>/wp-login.php?action=cdcf-zitadel-callback`
- Post-logout redirect URI: `<cms-origin>/wp-login.php?loggedout=true` —
  required, since RP-initiated logout will not redirect to an unregistered URI
- **Two apps, production and non-production**, mirroring the existing frontend
  split. The non-production app carries dev mode and the localhost redirect
  URIs for the local stack.
- Handoff emits `CDCF_ZITADEL_WP_CLIENT_ID` and `CDCF_ZITADEL_WP_CLIENT_SECRET`
  for `wp-config.php`

## The flows

Both entry points hang off `do_action( "login_form_{$action}" )`, which
`wp-login.php` fires before its own switch. No rewrite rules are needed, and
the theme's `functions.php` is loaded on the login page.

### Sign-in start — `login_form_cdcf-zitadel`

Redirects back to the login form if the client id or secret is empty.
Otherwise it generates `state`, `nonce` and a PKCE `code_verifier` (all
`wp_generate_password(…, false)`, so alphanumeric and unreserved-safe),
computes the S256 challenge, and then:

- **Transient** `cdcf_zauth_{sha256(state)}` holds `{nonce, verifier,
redirect_to}` with a 10-minute TTL.
- **Cookie** `cdcf_zitadel_state` holds the raw `state` — HttpOnly, `Secure`
  when `is_ssl()`, `SameSite=Lax`. Lax is correct: the callback is a top-level
  GET navigation, so the cookie is sent. This is the login-CSRF defence. The
  transient alone proves _a_ flow started; the cookie proves _this browser_
  started it.

It then calls `wp_redirect()` — not `wp_safe_redirect()`, since the host is
external, but config-derived rather than user-supplied — to `/oauth/v2/authorize`
with `scope=openid profile email`, plus the Org-restriction scope when
`CDCF_ZITADEL_ORG_ID` is set.

### Callback — `login_form_cdcf-zitadel-callback`

| #   | Step                                                                                                                                   | Failure code           |
| --- | -------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| 1   | Provider returned `error=`                                                                                                             | `cdcf_provider_error`  |
| 2   | `state` param and cookie both present and `hash_equals`                                                                                | `cdcf_bad_state`       |
| 3   | Transient loads; deleted immediately (single use), cookie cleared                                                                      | `cdcf_expired`         |
| 4   | Token exchange: `code` + `code_verifier`, `client_secret_basic`, 5s timeout                                                            | `cdcf_exchange_failed` |
| 5   | `id_token` claims: `iss` matches, `aud` contains our client id, `exp` fresh (60s leeway), `nonce` matches the transient, `sub` present | `cdcf_bad_token`       |
| 6   | Userinfo: HTTP 200, `email_verified === true` (strict), non-empty `email`, `sub` equal to the id_token's                               | `cdcf_bad_token`       |
| 7   | `cdcf_zitadel_resolve_existing_user($sub, $email)` returns > 0                                                                         | `cdcf_no_account`      |
| 8   | `wp_set_auth_cookie()`, `do_action('wp_login', …)`, redirect to `wp_validate_redirect($redirect_to, admin_url())`                      | —                      |

**The `id_token` signature is not verified locally, deliberately.** It arrives
over a TLS back-channel exchange authenticated with the client secret — OIDC
Core §3.1.3.7 permits skipping verification there — and step 6 independently
re-validates signature, expiry and revocation server-side. This is the posture
`zitadel-bearer.php` already takes, so the codebase keeps one convention
instead of growing a JWKS cache for a second one.

Step 7 is where decision 1 lands. `resolve_existing` still performs the
email-fallback sub-stamping, so a staff member's first passkey sign-in binds
`cdcf_zitadel_sub` to their existing account. **That first login is the
account-linking event**; every later one takes the sub fast path and survives
a Zitadel email change.

### Logout

Two things are recorded at sign-in:

- `attach_session_information` adds `cdcf_zitadel => 1` to the WordPress
  session record — the durable "how did this session authenticate" flag.
- The `id_token` goes in a transient keyed by a random value held in a
  `cdcf_zitadel_logout` cookie.

It is not kept in the session record because `wp_logout()` destroys the
session _before_ firing `do_action('wp_logout')`, so it would already be gone
when we could read it. It is not kept in the cookie directly because that
hands the browser a replayable JWT for no gain.

On `wp_logout`: read the cookie, load and delete the transient, clear the
cookie. Found means `wp_redirect()` to `/oidc/v1/end_session` with
`id_token_hint`, `client_id` and the registered `post_logout_redirect_uri`,
then `exit`. Not found means do nothing, and WordPress's normal logout
proceeds. That is decision 2: password sessions are untouched, Zitadel
sessions end upstream.

### The button

`login_form` prints an anchor — not a submit button, which would post the
native form — below the standard fields, with a few lines of inline CSS via
`login_enqueue_scripts`. Errors return as `?cdcf_auth_error=<code>` and render
through the `wp_login_errors` filter, so they appear in WordPress's own error
box.

## Error handling

Every failure path logs detail with an `[cdcf-zitadel-login]` prefix and
redirects to `wp_login_url()` with an opaque `cdcf_auth_error` code. Provider
internals never reach the browser. Tokens are never logged.

One code gets a specific user-facing message; the rest collapse to a generic
"Sign-in failed, please try again":

> **`cdcf_no_account`** — "You signed in successfully, but this identity isn't
> linked to a CMS account. Ask an administrator to create one for you."

That is the message a staff member will actually encounter, and it discloses
nothing: they have already authenticated as themselves.

The flow fails closed throughout. Unconfigured constants mean no button
renders _and_ the callback refuses, so a half-configured deploy degrades to
today's behaviour rather than to a broken login page. Token and userinfo calls
each carry the existing 5-second timeout.

**Accepted risk:** the start endpoint is unauthenticated and writes a
transient, so it can be spammed to churn the options table. The 10-minute TTL
and WordPress's expired-transient cleanup bound it. `includes/security.php`
already holds throttling helpers if it ever needs tightening. Not built now.

## Testing

`tests/ZitadelLoginTest.php`, in the same Brain Monkey and Patchwork shape as
`ZitadelBearerTest`. The handlers redirect and `exit`, so `bootstrap.php` gains
a `CdcfRedirect` exception and stubs `wp_redirect` / `wp_safe_redirect` to
throw it carrying the URL — the trick already used for `wp_send_json_*` via
`CdcfAjaxSuccess` and `CdcfAjaxError`.

- **Start:** unconfigured produces no authorize redirect; configured writes the
  transient and cookie, the authorize URL carries every required parameter,
  and the S256 challenge verifies against the stored verifier
- **State:** missing parameter, parameter/cookie mismatch, missing transient;
  and replay — the transient is gone before the token exchange, so a second
  callback with the same state fails
- **Token exchange:** non-200 gives `cdcf_exchange_failed` and sets no auth
  cookie
- **`id_token` claims:** wrong `iss`, wrong `aud`, expired, `nonce` mismatch,
  missing `sub`
- **Userinfo:** non-200, `email_verified` as the string `"true"`, missing
  email, `sub` disagreeing with the id_token
- **Link-only invariant:** resolver returns `0`, so the result is
  `cdcf_no_account` **and `wp_insert_user` is never called**. This test encodes
  decision 1 and is the point of the file.
- **Success:** `wp_set_auth_cookie` with the resolved id, `wp_login` fired,
  redirect honours `redirect_to`, and an off-site `redirect_to` falls back to
  `admin_url()`
- **Logout:** hint present gives an `end_session` redirect with
  `id_token_hint`; hint absent gives no redirect at all
- **`cdcf_zitadel_resolve_existing_user` directly:** sub hit with email-drift
  sync, email hit stamping the sub, both missing returning `0` with nothing
  provisioned

**The extraction's safety net is that `ZitadelBearerTest` passes unchanged.**
If the identity split alters bearer behaviour, those existing tests say so.

Manual verification runs against the local stack: enrol a passkey, link an
existing editor account, then confirm the native login form, Application
Passwords, and the REST bearer path all still work.

## Deployment

Theme changes ship only on a production deploy:

```bash
gh workflow run deploy.yml -f environment=production
```

A default dispatch runs as staging, skips every WordPress theme step, and goes
green having deployed nothing.

Set the `wp-config.php` constants **before** the theme ships. Either order is
safe, because the flow fails closed, but constants-first means the button works
on first load rather than after a follow-up.

## Sequencing

1. `cdcf-infra` provisions the WordPress OIDC apps (production and
   non-production) and emits the client id/secret handoff
2. Identity extraction, with `ZitadelBearerTest` green and unchanged
3. `zitadel-config.php`, including the issuer-derived userinfo URL
4. The sign-in flow, the button, and the error surface
5. Conditional logout
6. Local manual verification, then production deploy

## References

- `wordpress/themes/cdcf-headless/includes/auth/zitadel-bearer.php` — the
  identity resolution and auto-provisioning this reuses
- `docs/zitadel-oidc-plan.md` §1.2 — the original plugin-based plan this
  supersedes. Its `preferred_username` identity key and email-based linking
  contradict the sub-as-primary-key model the bearer validator now enforces.
- `docs/superpowers/specs/2026-08-17-local-zitadel-stack-design.md` — the local
  Zitadel this is developed against
- [#189](https://github.com/CatholicOS/cdcf-website/issues/189) — native login
  UI; related, separate
