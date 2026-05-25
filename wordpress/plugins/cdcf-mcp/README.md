# cdcf-mcp

**Prototype.** Exposes CDCF content-management operations as WordPress
[Abilities](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
and serves them over the Model Context Protocol via the
[WordPress MCP adapter](https://github.com/WordPress/mcp-adapter), so editors can
draft and manage content from an MCP client (Claude Desktop, Claude Code,
Cursor, …).

See `docs/wordpress-mcp-evaluation.md` for the full feasibility evaluation,
rationale and caveats.

## Requirements

- WordPress **≥ 6.9** (Abilities API in core) — on 6.8, install the Abilities
  API plugin separately.
- PHP ≥ 8.1.
- `wordpress/mcp-adapter` (Composer; pulled in by `composer install`).

## What it does

Registers a `cdcf` ability category and 20 abilities (see the table in the
evaluation doc). Abilities that map onto an existing `cdcf/v1` REST endpoint
dispatch to it internally via `rest_do_request()`, reusing that endpoint's
sanitisation, validation, permission checks and translation queueing. The rest
(delete, content edits, media sideload, listings, plain page/post creation) call
core WordPress functions directly.

The plugin degrades gracefully:

- The `cdcf` category registers on `wp_abilities_api_categories_init` and the
  abilities on `wp_abilities_api_init` (core's two separate init hooks) whenever
  the Abilities API is present — even without the MCP adapter.
- The MCP server is created only when the adapter fires `mcp_adapter_init`, and
  `includes/server.php` guards against pre-1.0 API drift. The adapter ships as a
  PSR-4 Composer library, so the plugin boots it via `\WP\MCP\Plugin::instance()`.

Each ability is capability-gated (`edit_posts`, `edit_pages`, `delete_posts`,
`upload_files`) and flagged `meta.mcp.public => true` so the adapter exposes it.

## Layout

```text
cdcf-mcp.php            Plugin bootstrap; loads vendor autoload + hooks
includes/abilities.php  Ability registry (single source of truth) + registration
includes/callbacks.php  execute_callback implementations
includes/server.php     MCP server creation on mcp_adapter_init
tests/                  PHPUnit + Brain Monkey + Mockery
```

## Install & run (local docker stack — opt-in)

This plugin needs its `vendor/` at runtime (the adapter), so it is intentionally
**not** wired into `docker-compose.yml` / `wordpress/init.sh`. To pilot it:

1. Install dependencies:

   ```bash
   composer install --working-dir=wordpress/plugins/cdcf-mcp
   ```

2. Mount it into the WordPress container — add to each WP service's `volumes:`
   in `docker-compose.yml`:

   ```yaml
   - ./wordpress/plugins/cdcf-mcp:/var/www/html/wp-content/plugins/cdcf-mcp
   ```

3. Activate it:

   ```bash
   docker compose exec wordpress wp plugin activate cdcf-mcp --allow-root
   ```

4. Connect an MCP client to `/wp-json/cdcf-mcp/mcp`, authenticating with an
   Application Password for a **role-limited** user (see Security).

## Security

- Agents inherit the WP user they authenticate as — use a dedicated **editor**
  (or narrower) bot user, never an administrator.
- `manage_options` operations (`/process-queue`, `/maintenance`,
  `/flush-opcache`) are deliberately **not** exposed as abilities.
- `cdcf/delete-member` is gated on `delete_posts` and trashes (not force-deletes)
  by default.

## Tests

```bash
composer install --working-dir=wordpress/plugins/cdcf-mcp
composer test    --working-dir=wordpress/plugins/cdcf-mcp
```

`tests/AbilityDefinitionsTest.php` checks registry integrity (unique namespaced
names, schema shape, callable callbacks, known capabilities) with no WordPress
runtime. `tests/CallbacksTest.php` asserts callback behaviour (correct dispatch
route/params, append-vs-replace relationship logic, deletion across
translations, draft creation) using Brain Monkey stubs.
