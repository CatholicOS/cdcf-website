# Auto-Translate Public Submissions on Approval

## Problem

Three public submission endpoints — `/cdcf/v1/submit-project`, `/cdcf/v1/refer-community-project`, and `/cdcf/v1/refer-local-group` — create only an English post (status `pending`) and never create translations. When the admin approves a submission by publishing the EN post, no translations are created either. The result: submitted projects, community projects, and local groups appear only on the English site; the IT/ES/FR/PT/DE locales show nothing for those items.

## Goal

When an admin publishes a public-submission post (`project`, `community_project`, or `local_group` whose source has submitter meta), automatically create draft translation siblings in `it`, `es`, `fr`, `pt`, `de`, link them via Polylang, and enqueue background AI translations. The existing translation worker (`cdcf_process_translation`) auto-publishes translations whose source is `publish`, so the translations become visible on the public site as soon as the worker finishes each language.

## Non-Goals

- No changes to the submission endpoints themselves (no eager draft creation at submission time).
- No new admin UI, no new REST endpoint, no changes to ACF or CPT registration.
- No retry mechanism for worker failures — admin can re-trigger translation manually via the existing `/cdcf/v1/translate` endpoint or the wp-admin translation UI.
- No changes to the `/team-member`, `/community-channel`, `/local-group`, `/academic-collaboration` admin endpoints (already handle translation eagerly).

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| When to publish translations | Auto-publish as worker finishes each language | Reuses existing `cdcf_process_translation` behavior (line 3560-3562: source `publish` → translation `publish`) |
| When to create translation drafts | Lazily, at approval (publish transition) | Avoids orphan drafts when admin rejects/trashes submissions |
| Scope | All three CPTs: `project`, `community_project`, `local_group` | Same gap exists in all three submission flows |
| Gating | Only fire for posts with submitter meta | Doesn't surprise admins who manually create posts in wp-admin |

## Architecture

One new section in `wordpress/themes/cdcf-headless/functions.php`, placed near the existing `transition_post_status` hooks (around line 4283).

### Functions

**`cdcf_is_public_submission(int $post_id): bool`**
Returns `true` if the source (EN) post has either `_submission_submitter_email` or `_referral_submitter_email` meta. Uses the existing `cdcf_get_source_post_id()` helper so it works whether called with the EN post ID or a translation's ID.

**`cdcf_enqueue_translations_for_submission(int $en_post_id, string $post_type): void`**
Build the Polylang translation map once before the loop:
```php
$translations = pll_get_post_translations($en_post_id);
$translations['en'] = $en_post_id;
```
Then for each `lang` in `['it', 'es', 'fr', 'pt', 'de']`:
1. If `!empty($translations[$lang])` → skip (translation already linked, possibly from a partial earlier run).
2. Otherwise:
   - `wp_insert_post(['post_type' => $post_type, 'post_status' => 'draft', 'post_title' => <EN title>])`
   - On failure: `error_log` and continue to next language.
   - `pll_set_post_language($trans_id, $lang)`
   - `$translations[$lang] = $trans_id;` then `pll_save_post_translations($translations)` — the map accumulates as new siblings are created, matching the pattern at functions.php lines 570-590 (`cdcf_rest_create_team_member`).
   - `cdcf_enqueue_translation($trans_id, $en_post_id, $lang)` if available; otherwise WP-Cron fallback (`wp_schedule_single_event('cdcf_async_translate', …) + spawn_cron()`), matching the pattern at functions.php lines 593-599.

Dependency guard at function entry: if any of `pll_set_post_language`, `pll_save_post_translations`, or `pll_get_post_translations` is not present, `error_log` and return.

### Hook

```php
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status === $old_status) return;
    if ($new_status !== 'publish') return;
    if (!in_array($post->post_type, ['project', 'community_project', 'local_group'], true)) return;

    // Only process the EN source — when the worker promotes a translation
    // sibling to `publish`, this hook fires again and would otherwise loop
    // through the helper as a no-op for each language.
    $source_id = cdcf_get_source_post_id($post->ID);
    if ($source_id !== $post->ID) return;

    if (!cdcf_is_public_submission($post->ID)) return;

    cdcf_enqueue_translations_for_submission($source_id, $post->post_type);
}, 20, 3);
```

Priority `20` so it runs after all priority-10 `transition_post_status` hooks (sitemap revalidation and the untrash/re-pend hook).

## Data Flow

**Submission (unchanged):** User submits form → Next.js → `POST /cdcf/v1/submit-project` → EN post created with `status=pending`, `lang=en`, submitter meta set, admin notified by email.

**Approval (new):**
1. Admin clicks Publish in wp-admin on the EN post.
2. WordPress fires `transition_post_status` (`pending|draft → publish`).
3. Existing hook (priority 10) notifies Next.js to revalidate the sitemap.
4. New hook (priority 20):
   - Resolves to source EN post ID via `cdcf_get_source_post_id`.
   - For each of 5 target languages: skips if a translation exists; otherwise creates a `draft` sibling, links it via Polylang, and enqueues a translation job.
5. Background worker (existing `cdcf_process_translation`):
   - Calls OpenAI to translate title, content, ACF fields.
   - Sees source `post_status === 'publish'` → `wp_update_post(['post_status' => 'publish'])` on the translation.
   - Triggers its own `transition_post_status` → revalidates sitemap for that locale.

## Edge Cases

| Case | Behavior |
|---|---|
| Admin manually creates a project in wp-admin | Submitter meta absent → gate skips it. No surprise translation. |
| Admin re-publishes after unpublishing | `pll_get_post` returns existing translations → loop skips them all. No duplicate work. |
| Worker fails for one language | That translation stays `draft`. Visible in admin's standard draft list. Admin can re-trigger via existing `/cdcf/v1/translate` endpoint. |
| Source post unpublished while worker is running | Worker's auto-publish check evaluates `$source->post_status === 'publish'` at worker run time — translation correctly stays `draft`. |
| Polylang inactive | Helper logs and returns early. Publish action succeeds; no translations created. |
| Featured image / ACF fields | Already handled by `cdcf_process_translation` — no extra work in the new code. |
| Recursion | Creating draft posts does not fire `* → publish`, so the hook doesn't re-enter. Worker promoting a translation to `publish` is not a public submission (no submitter meta on the translation, but `cdcf_get_source_post_id` resolves to EN which does have meta — however `pll_get_post` will then return all five translations including the one being promoted, so the loop is a no-op). |

## Verification (Manual)

No test runner is configured for this project (per `CLAUDE.md`). Verification steps:

1. Submit a test project via the public form on the dev site.
2. In wp-admin, locate the new pending project → click Publish.
3. Confirm 5 new draft posts appear in the same CPT (one per language), linked via Polylang.
4. Wait for the worker (Redis Queue or WP-Cron) to process them.
5. Confirm each translation transitions to `publish` and is visible at `/it/projects/...`, `/es/projects/...`, etc.
6. Repeat for `community_project` and `local_group`.
7. Negative test: in wp-admin, manually create a `project` (no submission meta). Publish. Confirm no translations are created.

## Out of Scope (Future Work)

- Retry-on-failure for the worker: a separate cron sweep that finds public-submission posts whose translations are still `draft` and re-enqueues them.
- Surfacing translation status in the admin meta box ("3 of 5 translations published").

## Implementation Notes

- All work in a dedicated feature branch (e.g., `feature/auto-translate-public-submissions`).
- Single file changed: `wordpress/themes/cdcf-headless/functions.php`.
- No frontend (Next.js) changes.
- No database migrations.
- No environment variable changes.
