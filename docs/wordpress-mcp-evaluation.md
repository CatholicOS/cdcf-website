# WordPress MCP Adapter — Feasibility Evaluation

**Status:** Evaluation + working prototype (`wordpress/plugins/cdcf-mcp/`)
**Question:** Should content editors / authors be able to draft and manage CDCF
content through an MCP server (e.g. [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter))?
**Verdict:** Feasible and a good fit for this stack, scoped as a phase-2
experiment. The real payoff comes from exposing CDCF's _existing_ domain
operations as abilities — not generic post CRUD.

---

## 1. What the MCP adapter actually is

It is **not** a turnkey "AI writes your posts" plugin. It is a thin bridge:

1. The **WordPress Abilities API** (core since WP 6.9) lets code register named,
   permission-gated functions — "abilities" — each with a JSON input/output
   schema and a `permission_callback`.
2. The **MCP adapter** automatically exposes any ability flagged
   `meta.mcp.public => true` to MCP clients (Claude Desktop, Claude Code,
   Cursor, VS Code) as MCP **tools / resources / prompts**.

An editor connects their AI client to the site, the client discovers the
abilities, and calls them. WordPress runs the same capability checks it always
does. Write operations require an explicit confirmation round-trip.

| Property           | Value                                                                                                   |
| ------------------ | ------------------------------------------------------------------------------------------------------- |
| Distribution       | Composer package (`composer require wordpress/mcp-adapter`) or plugin                                   |
| PHP                | ≥ 7.4                                                                                                   |
| WordPress          | ≥ 6.8 (Abilities API in core from **6.9**; 6.8 needs the API as a separate plugin)                      |
| Latest release     | **v0.5.0** (April 2026), pre-1.0                                                                        |
| Write capabilities | Landed **March 2026** — young                                                                           |
| Runtime dep        | `wordpress/php-mcp-schema`                                                                              |
| Auth model         | Agent authenticates as a real WP user (Application Password) and inherits that user's role/capabilities |
| Ability hook       | `wp_abilities_api_init`                                                                                 |
| Server hook        | `mcp_adapter_init` → `$adapter->create_server(...)`                                                     |

## 2. Compatibility with CDCF

| Requirement                                             | CDCF status                                                                                                                         |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| PHP ≥ 7.4                                               | ✅ PHP **8.4** in docker; `cdcf-redis-translations` already requires 8.3+                                                           |
| WP ≥ 6.8 / 6.9                                          | ✅ dev runs the `6` (latest) tag — production verified **WP 7.0** with the Abilities API live (`wp-abilities/v1` namespace present) |
| Composer plugin tree                                    | ✅ Already the convention (`cdcf-redis-translations`, theme)                                                                        |
| Capability-based auth (`edit_posts` / `manage_options`) | ✅ Exactly the model the adapter inherits                                                                                           |
| Application Passwords                                   | ✅ Already used by `scripts/cdcf_api.py`                                                                                            |

`composer require wordpress/mcp-adapter` resolves cleanly from Packagist
(verified: pulls `wordpress/mcp-adapter v0.5.0` + `wordpress/php-mcp-schema
v0.1.1`). The `create_server()` signature and the
`WP\MCP\Transport\HttpTransport` /
`WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler` /
`WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler` class names
used by the prototype were checked against the installed v0.5.0 source.

## 3. Key design decision: wrap domain operations, not raw posts

A naïve "let the AI create a post" setup is weak for CDCF, because content here
is never just title + body. A blog post or member carries:

- per-template / per-CPT **ACF fields**,
- a **Polylang** translation set (en + it/es/fr/pt/de),
- a translation pipeline (`/translate` → Redis queue → `/deploy-translation`),
- relationship links (council members ↔ About page, project leads ↔ project,
  governance ↔ academic collaboration).

A generic `create-post` ability would produce an untranslated, unlinked draft
with no metadata and break the content model's expectations.

The strong approach — and what the prototype does — is to register CDCF's
**existing, already-tested `cdcf/v1` endpoints** as abilities. The Abilities API
can wrap any PHP callable, so the rich domain logic (auto-translation,
relationship linking) becomes a set of safe, structured AI tools. Where an
endpoint already exists, the ability dispatches to it internally via
`rest_do_request()`, reusing its sanitisation, validation, permission checks and
translation queueing verbatim rather than duplicating them.

## 4. The prototype: `wordpress/plugins/cdcf-mcp/`

A self-contained plugin that registers a `cdcf` ability category and 19
abilities, then (if the adapter is installed) serves them at
`/wp-json/cdcf-mcp/mcp`.

| Ability                                  | Backing                                                    | Capability     |
| ---------------------------------------- | ---------------------------------------------------------- | -------------- |
| `cdcf/create-board-member`               | POST `/cdcf/v1/team-member` (council=`team_members`)       | `edit_posts`   |
| `cdcf/create-ecclesial-council-member`   | …council=`ecclesial_council`                               | `edit_posts`   |
| `cdcf/create-technical-council-member`   | …council=`technical_council`                               | `edit_posts`   |
| `cdcf/create-academic-liaison`           | …council=`academic_council` (needs `collab_post_id`)       | `edit_posts`   |
| `cdcf/create-academic-collaboration`     | POST `/cdcf/v1/academic-collaboration`                     | `edit_posts`   |
| `cdcf/create-community-channel`          | POST `/cdcf/v1/community-channel`                          | `edit_posts`   |
| `cdcf/create-local-group`                | POST `/cdcf/v1/local-group`                                | `edit_posts`   |
| `cdcf/update-member-bio`                 | `wp_update_post` + ACF + optional re-translate             | `edit_posts`   |
| `cdcf/delete-member`                     | trash/delete member + all translations                     | `delete_posts` |
| `cdcf/update-member-relationship`        | POST `/cdcf/v1/relationship` (replace)                     | `edit_posts`   |
| `cdcf/add-project-lead`                  | GET+POST `/cdcf/v1/relationship` (append)                  | `edit_posts`   |
| `cdcf/update-project-description`        | `wp_update_post` + optional re-translate                   | `edit_posts`   |
| `cdcf/update-project-status`             | POST `/cdcf/v1/project-status`                             | `edit_posts`   |
| `cdcf/set-project-repos`                 | ACF `project_repo_url` / `project_url` across translations | `edit_posts`   |
| `cdcf/set-featured-image`                | `set_post_thumbnail` (any post type)                       | `edit_posts`   |
| `cdcf/list-submitted-projects`           | `project` listing (incl. drafts/pending)                   | `edit_posts`   |
| `cdcf/list-submitted-community-projects` | `community_project` listing                                | `edit_posts`   |
| `cdcf/create-page`                       | `wp_insert_post` (page, template + language)               | `edit_pages`   |
| `cdcf/create-post`                       | `wp_insert_post` (blog post draft)                         | `edit_posts`   |

Each ability declares a JSON input schema and a capability gate; the adapter
exposes only abilities flagged `meta.mcp.public => true`. The plugin degrades
gracefully — abilities still register without the adapter installed; the MCP
server is only created when `mcp_adapter_init` fires.

Two integration details (surfaced and fixed during the local pilot, below)
matter when wiring this against the real core API:

- The `cdcf` ability **category** is registered on its own hook
  (`wp_abilities_api_categories_init`), separate from the abilities hook
  (`wp_abilities_api_init`). Core rejects any ability whose category isn't
  already registered, so getting this wrong silently drops every ability.
- The MCP adapter is a Composer library with PSR-4-only autoloading, so its
  plugin entry point never runs; the plugin boots it explicitly with
  `\WP\MCP\Plugin::instance()` so `mcp_adapter_init` fires.

Tests (PHPUnit + Brain Monkey + Mockery, matching the `cdcf-redis-translations`
convention) cover the registry structure and callback behaviour: 21 tests / 300
assertions, all green. See `wordpress/plugins/cdcf-mcp/README.md` for how to
install, activate and connect a client.

## 5. Caveats to weigh before adopting in production

1. **Production WordPress version (the former gating item — now cleared).** The
   Abilities API requires WP **6.9** in core (or the API plugin on 6.8).
   Verified: production `cms.catholicdigitalcommons.org` runs **WP 7.0** with the
   Abilities API live (the `wp-abilities/v1` REST namespace is registered). The
   MCP **adapter** itself is still not deployed there — it ships with this
   plugin's `vendor/` (see caveat 4).
2. **Pre-1.0 dependency.** v0.5.0 with ~2-month-old write support — the API may
   shift, and this exposes a new authenticated endpoint surface on the CMS
   subdomain. The prototype's `server.php` guards against the most likely API
   drift (missing method/transport class) but a version bump should be reviewed.
3. **Security surface.** Mitigations: scope Application Passwords, run agents as
   a **role-limited bot user** (not an administrator), keep destructive
   abilities (`delete-member`) on stricter capabilities, and rely on the
   adapter's write-confirmation gate. Never expose `manage_options`-level
   operations (`/process-queue`, `/maintenance`, `/flush-opcache`) as abilities.
4. **Not wired into the shared dev stack.** Unlike `cdcf-redis-translations`
   (dev-only composer deps), this plugin needs its `vendor/` at runtime. To keep
   the prototype non-invasive it is _not_ added to `docker-compose.yml` or
   `wordpress/init.sh`; opt-in steps are in the plugin README.

## 6. Recommendation / next steps

Worth pursuing as a phase-2 experiment, in this order:

1. ✅ **Verify** production WP ≥ 6.9 — done: production is **WP 7.0** with the
   Abilities API in core (`wp-abilities/v1` namespace live).
2. ✅ **Pilot locally** — done: mounted via `docker-compose.override.yml`,
   activated, and connected over MCP. An authenticated `initialize` +
   `tools/list` handshake returned all 20 abilities as tools. This surfaced and
   fixed two integration bugs (see §4): the category hook and the explicit
   adapter boot. The prototype had only been exercised against test stubs
   before, so neither showed up until it ran against real core.
3. ✅ **Exercise** the translation-aware abilities end-to-end — done: driving
   `cdcf-create-board-member` over MCP created the English post + 5 Polylang
   translation drafts and linked the member into the About page and all 5 of its
   translations. This surfaced a dev-stack bug (the `redis-worker` entrypoint
   called the wrong method and never drained the queue), fixed separately.
   Generating the translated _content_ additionally needs `OPENAI_API_KEY` in
   the local stack.
4. ✅ **Decide** on production exposure — done; see §7. Verdict: **not on
   production by default yet** — staging-ready, production-eligible once the
   §7 conditions are met.

The translation-aware domain abilities are the differentiator; generic post CRUD
alone would not justify the added dependency and attack surface.

## 7. Security review + production-exposure decision

Reviewed against the live local stack (auth, authorization, injection/SSRF,
CORS). What holds up:

- **Authentication is enforced.** Anonymous requests to `/wp-json/cdcf-mcp/mcp`
  get `401 rest_forbidden` (both discovery and execution).
- **Authorization is per-ability.** A subscriber can open a session but
  `tools/call` returns `Permission denied` — each ability's
  `current_user_can()` gate fires. Discovery (`tools/list`) is open to any
  authenticated user (tool names/schemas only; not sensitive).
- **`manage_options` operations are excluded.** The abilities only reach
  `edit_posts`-level `cdcf/v1` endpoints; `/process-queue`, `/maintenance`,
  `/flush-opcache` are not exposed.
- **Inputs are sanitized.** Dispatch abilities reuse the `cdcf/v1` endpoints'
  args-block sanitization (#111); direct callbacks use `wp_kses_post`,
  `sanitize_text_field`, `absint`, `esc_url_raw`, and validate attachment types.
  `delete-member` trashes by default (force is opt-in).

Risks to address **before** production:

1. **SSRF in `upload-media` (medium).** `download_url()` fetches an
   agent-supplied URL with no host/scheme allowlist or internal-IP guard — an
   authenticated `upload_files` user could make the CMS fetch internal/metadata
   endpoints. Comparable to core "insert from URL," but it's the most
   plugin-specific risk. Add a host allowlist / reject-internal guard, or drop
   `upload-media` from the production ability set.
2. **Pre-1.0 dependency (medium).** `wordpress/mcp-adapter` v0.5.0 exposes a new
   authenticated write surface on the CMS subdomain; pin the version and
   re-review on every bump.
3. **Bot role (low–med).** All write abilities need
   `edit_posts`/`edit_pages`/`delete_posts`/`upload_files`, which an `editor`
   already has. Use a dedicated bot user with a **custom minimal role**, never
   `editor`/`administrator`.
4. **Application Password handling (operational).** It grants the bot user's
   full capabilities over REST + MCP — scope it, rotate it, store it in a secret
   manager.
5. **No server-side write confirmation (low).** The MCP write-confirmation
   round-trip is client-side UX, not a server control — don't treat it as one.
6. **Inherited REST CORS (low).** The endpoint reflects `Origin` with
   `Access-Control-Allow-Credentials: true`, but this is WordPress core's
   default for every REST route (verified identical on `/wp/v2/*`) and is
   mitigated by the nonce requirement for cookie-auth writes and by app
   passwords not being sent ambiently cross-origin. Not introduced here.

**Decision.** The model is fundamentally sound, but production exposure is
**deferred**: keep it local/staging-only for now. It becomes production-eligible
once (a) the adapter reaches a more stable (≥1.0) release **or** the pre-1.0 risk
is explicitly accepted with a pinned version, (b) `upload-media` is hardened or
dropped, (c) a dedicated bot user with a custom minimal role is provisioned with
a scoped/rotated Application Password, and (d) the deploy wires the plugin's
`vendor/` + activation behind a feature flag / kill switch, keeping
`manage_options` operations excluded.
