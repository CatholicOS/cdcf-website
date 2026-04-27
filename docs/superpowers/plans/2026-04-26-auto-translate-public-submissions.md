# Auto-Translate Public Submissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an admin publishes a `project`, `community_project`, or `local_group` that came from the public submission form, automatically create draft sibling posts for `it / es / fr / pt / de`, link them via Polylang, and enqueue background AI translations. The existing translation worker auto-publishes each translation when its source is `publish`.

**Architecture:** Add one new section to `wordpress/themes/cdcf-headless/functions.php` containing two helper functions and one `transition_post_status` hook (priority 20). No frontend changes, no DB migrations, no new endpoints. Reuses the existing `cdcf_enqueue_translation()` / `cdcf_process_translation` worker pipeline (with WP-Cron fallback) — same pattern used by `/team-member`, `/community-channel`, etc.

**Tech Stack:** WordPress 6.x · PHP 8.x · Polylang Pro · Advanced Custom Fields Pro · existing CDCF translation queue worker (Redis Queue with WP-Cron fallback)

**Spec:** [`docs/superpowers/specs/2026-04-26-auto-translate-public-submissions-design.md`](../specs/2026-04-26-auto-translate-public-submissions-design.md)

**Verification:** No automated tests — the project has no test runner per `CLAUDE.md`. Verification is manual via the running dev stack (`docker compose up`) and the Python API client (`scripts/cdcf_api.py`).

---

## Task 1: Create the feature branch

**Files:** none (git operation only)

- [ ] **Step 1: Confirm working tree is clean**

Run:
```bash
cd /home/johnrdorazio/development/CatholicOS_org/cdcf-website
git status
```

Expected: `nothing to commit, working tree clean` on `main`.

If anything is uncommitted, stop and surface it to the user — don't auto-stash.

- [ ] **Step 2: Create and switch to the feature branch**

Run:
```bash
git checkout -b feature/auto-translate-public-submissions
```

Expected: `Switched to a new branch 'feature/auto-translate-public-submissions'`.

- [ ] **Step 3: Verify branch**

Run:
```bash
git branch --show-current
```

Expected output: `feature/auto-translate-public-submissions`.

---

## Task 2: Add `cdcf_is_public_submission()` helper

**Files:**
- Modify: `wordpress/themes/cdcf-headless/functions.php` — insert new section between line 4312 and line 4314 (between the close of the "Restore Public Submissions to Pending on Untrash" hook and the start of the "Project Submission: Meta Box" section)

- [ ] **Step 1: Open functions.php and locate the insertion point**

Run:
```bash
grep -n "Project Submission: Meta Box" wordpress/themes/cdcf-headless/functions.php
```

Expected: a single match around line 4314. The new section goes immediately above it (after the close of the restore-hook section at line 4312).

- [ ] **Step 2: Insert the section header and the first helper**

Use the Edit tool to insert the following block between line 4312 (the line `}, 10, 3);` closing the restore hook) and the existing line 4314 (`// ─── Project Submission: Meta Box ───`).

Use this exact `old_string` for uniqueness:

```php
}, 10, 3);

// ─── Project Submission: Meta Box ────────────────────────────────────
```

Replace with:

```php
}, 10, 3);

// ─── Auto-Translate Public Submissions on Approval ───────────────────
//
// When an admin publishes a public-submission post (project,
// community_project, or local_group whose source has submitter meta),
// create draft sibling posts in it/es/fr/pt/de, link them via Polylang,
// and enqueue background AI translations. The existing worker
// (cdcf_process_translation) auto-publishes each translation when its
// source is `publish` (see line ~3560).

/**
 * True if the source (EN) post has submitter meta from the public
 * submission/referral form. Works whether called with the EN post ID
 * or a translation's ID — resolves to source via cdcf_get_source_post_id().
 */
function cdcf_is_public_submission(int $post_id): bool {
    $source_id = cdcf_get_source_post_id($post_id);
    return (bool) (
        get_post_meta($source_id, '_submission_submitter_email', true)
        || get_post_meta($source_id, '_referral_submitter_email', true)
    );
}

// ─── Project Submission: Meta Box ────────────────────────────────────
```

- [ ] **Step 3: Verify the insertion is syntactically valid PHP**

Run:
```bash
docker compose exec -T wordpress php -l /var/www/html/wp-content/themes/cdcf-headless/functions.php
```

Expected: `No syntax errors detected in /var/www/html/wp-content/themes/cdcf-headless/functions.php`.

If Docker is not running, fall back to:
```bash
php -l wordpress/themes/cdcf-headless/functions.php
```

If neither PHP is available, surface that to the user — do not proceed without a syntax check.

- [ ] **Step 4: Commit**

Run:
```bash
git add wordpress/themes/cdcf-headless/functions.php
git commit -m "$(cat <<'EOF'
feat(translations): add cdcf_is_public_submission() helper

First step toward auto-translating public submissions on approval.
Detects whether a post (or its source EN post if it's a translation)
came from the public submission/referral form by checking for
_submission_submitter_email or _referral_submitter_email meta.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Add `cdcf_enqueue_translations_for_submission()` helper

**Files:**
- Modify: `wordpress/themes/cdcf-headless/functions.php` — append the new function immediately after `cdcf_is_public_submission()` (inside the same "Auto-Translate Public Submissions on Approval" section).

- [ ] **Step 1: Insert the second helper**

Use the Edit tool. Use this exact `old_string` for uniqueness:

```php
function cdcf_is_public_submission(int $post_id): bool {
    $source_id = cdcf_get_source_post_id($post_id);
    return (bool) (
        get_post_meta($source_id, '_submission_submitter_email', true)
        || get_post_meta($source_id, '_referral_submitter_email', true)
    );
}

// ─── Project Submission: Meta Box ────────────────────────────────────
```

Replace with:

```php
function cdcf_is_public_submission(int $post_id): bool {
    $source_id = cdcf_get_source_post_id($post_id);
    return (bool) (
        get_post_meta($source_id, '_submission_submitter_email', true)
        || get_post_meta($source_id, '_referral_submitter_email', true)
    );
}

/**
 * For each target language (it/es/fr/pt/de):
 *   - Skip if a Polylang translation already exists.
 *   - Otherwise create a draft sibling post, link it via Polylang,
 *     and enqueue a background AI translation job.
 *
 * The existing worker (cdcf_process_translation) will auto-publish
 * each translation once its source post is `publish`.
 *
 * @param int    $en_post_id  The English (source) post ID. MUST be the source,
 *                            not a translation — caller should resolve via
 *                            cdcf_get_source_post_id() first.
 * @param string $post_type   The CPT slug (project | community_project | local_group).
 */
function cdcf_enqueue_translations_for_submission(int $en_post_id, string $post_type): void {
    if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        error_log("cdcf_enqueue_translations_for_submission: Polylang not active; skipping post {$en_post_id}.");
        return;
    }

    $en_post = get_post($en_post_id);
    if (!$en_post) {
        error_log("cdcf_enqueue_translations_for_submission: Source post {$en_post_id} not found.");
        return;
    }

    $target_langs = ['it', 'es', 'fr', 'pt', 'de'];

    foreach ($target_langs as $lang) {
        // Skip if a translation already exists for this language.
        $existing_id = function_exists('pll_get_post') ? pll_get_post($en_post_id, $lang) : 0;
        if ($existing_id) {
            continue;
        }

        // Create a draft sibling post; the worker will fill content and auto-publish.
        $trans_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_status' => 'draft',
            'post_title'  => $en_post->post_title,
        ]);

        if (is_wp_error($trans_id) || !$trans_id) {
            error_log("cdcf_enqueue_translations_for_submission: Failed to create {$lang} sibling for post {$en_post_id}.");
            continue;
        }

        pll_set_post_language($trans_id, $lang);

        // Update the Polylang translation map so the new sibling is linked.
        $translations = pll_get_post_translations($en_post_id);
        $translations['en']  = $en_post_id;
        $translations[$lang] = $trans_id;
        pll_save_post_translations($translations);

        // Enqueue background translation: Redis Queue if available, WP-Cron fallback.
        if (function_exists('cdcf_enqueue_translation')) {
            cdcf_enqueue_translation($trans_id, $en_post_id, $lang);
        } else {
            wp_schedule_single_event(time(), 'cdcf_async_translate', [$trans_id, $en_post_id, $lang]);
            spawn_cron();
        }
    }
}

// ─── Project Submission: Meta Box ────────────────────────────────────
```

- [ ] **Step 2: Verify syntax**

Run:
```bash
docker compose exec -T wordpress php -l /var/www/html/wp-content/themes/cdcf-headless/functions.php
```

Expected: `No syntax errors detected ...`.

- [ ] **Step 3: Commit**

Run:
```bash
git add wordpress/themes/cdcf-headless/functions.php
git commit -m "$(cat <<'EOF'
feat(translations): add cdcf_enqueue_translations_for_submission()

For each of it/es/fr/pt/de, creates a draft sibling post (skipping
languages that already have a translation), links it via Polylang,
and enqueues a background translation via the existing worker
(Redis Queue with WP-Cron fallback). The worker auto-publishes
translations whose source is publish.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Add the `transition_post_status` hook that wires it together

**Files:**
- Modify: `wordpress/themes/cdcf-headless/functions.php` — append the hook registration immediately after `cdcf_enqueue_translations_for_submission()`.

- [ ] **Step 1: Insert the hook**

Use the Edit tool. Use this exact `old_string` for uniqueness:

```php
        // Enqueue background translation: Redis Queue if available, WP-Cron fallback.
        if (function_exists('cdcf_enqueue_translation')) {
            cdcf_enqueue_translation($trans_id, $en_post_id, $lang);
        } else {
            wp_schedule_single_event(time(), 'cdcf_async_translate', [$trans_id, $en_post_id, $lang]);
            spawn_cron();
        }
    }
}

// ─── Project Submission: Meta Box ────────────────────────────────────
```

Replace with:

```php
        // Enqueue background translation: Redis Queue if available, WP-Cron fallback.
        if (function_exists('cdcf_enqueue_translation')) {
            cdcf_enqueue_translation($trans_id, $en_post_id, $lang);
        } else {
            wp_schedule_single_event(time(), 'cdcf_async_translate', [$trans_id, $en_post_id, $lang]);
            spawn_cron();
        }
    }
}

/**
 * Fires when an admin publishes a public-submission post.
 * Priority 20 so it runs after the existing sitemap-revalidation
 * hook at priority 10.
 */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status === $old_status) {
        return;
    }
    if ($new_status !== 'publish') {
        return;
    }
    if (!in_array($post->post_type, ['project', 'community_project', 'local_group'], true)) {
        return;
    }
    if (!cdcf_is_public_submission($post->ID)) {
        return;
    }

    $source_id = cdcf_get_source_post_id($post->ID);
    cdcf_enqueue_translations_for_submission($source_id, $post->post_type);
}, 20, 3);

// ─── Project Submission: Meta Box ────────────────────────────────────
```

- [ ] **Step 2: Verify syntax**

Run:
```bash
docker compose exec -T wordpress php -l /var/www/html/wp-content/themes/cdcf-headless/functions.php
```

Expected: `No syntax errors detected ...`.

- [ ] **Step 3: Commit**

Run:
```bash
git add wordpress/themes/cdcf-headless/functions.php
git commit -m "$(cat <<'EOF'
feat(translations): auto-translate public submissions on approval

Adds a transition_post_status hook (priority 20) that fires when an
admin publishes a project / community_project / local_group whose
source post has submitter meta. It calls the new
cdcf_enqueue_translations_for_submission() helper to create draft
siblings in it/es/fr/pt/de, link them via Polylang, and enqueue
background translations.

Closes the gap where publicly submitted posts only appeared on the
English site after admin approval.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Manual verification — happy path (project)

**Prerequisites:**
- Docker stack running: `docker compose up -d` from the repo root.
- Confirm the WordPress container is up: `docker compose ps wordpress` shows `Up`.
- Confirm Polylang and ACF are active in wp-admin.

- [ ] **Step 1: Submit a test project via the public form**

Open `http://localhost` in a browser. Go to the Projects page. Click "Submit a Project". Fill the form with:
- Project Name: `Test Project Auto-Translate`
- Description: `A test project to verify auto-translation on approval.`
- Repository URL: `https://github.com/example/test`
- Your Name: `Verification Bot`
- Your Email: a real email you can read (the form sends a 6-digit code).

Enter the verification code. Submit.

- [ ] **Step 2: Confirm the EN post was created as `pending`**

Run:
```bash
scripts/.venv/bin/python scripts/cdcf_api.py graphql --query '{ projects(first: 5, where: { status: PENDING, language: EN }) { nodes { databaseId title status } } }'
```

Expected: a node with `title: "Test Project Auto-Translate"` and `status: "pending"`. Capture the `databaseId` — call it `EN_ID`.

- [ ] **Step 3: Confirm no translations exist yet**

Run (substitute `<EN_ID>`):
```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-translation-ids --post-id <EN_ID>
```

Expected: only an `en` entry; no `it/es/fr/pt/de` entries.

- [ ] **Step 4: Approve the post (publish in wp-admin)**

In wp-admin (`http://localhost/wp-admin`), open the pending project and click **Publish**.

- [ ] **Step 5: Confirm 5 draft translations were created and linked**

Within ~2 seconds of approval (before the worker runs), re-check translations:
```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-translation-ids --post-id <EN_ID>
```

Expected: entries for all 6 languages (`en, it, es, fr, pt, de`). The 5 new translations should exist as draft posts. Capture each translation ID.

For each non-EN translation ID, verify it's a draft of the right CPT:
```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-post --post-id <TRANS_ID> --post-type project
```

Expected: `post_status: "draft"`, `post_type: "project"`, language matches.

- [ ] **Step 6: Wait for the worker and confirm auto-publish**

Wait ~30 seconds (or until your queue worker reports completion). Re-check:
```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-post --post-id <TRANS_ID> --post-type project
```

Expected: `post_status: "publish"` and content has been translated (not just empty / source title).

If a translation is stuck in `draft` after several minutes, check the worker logs:
```bash
docker compose logs --tail=200 wordpress | grep cdcf_process_translation
```

- [ ] **Step 7: Confirm translated pages render on the public site**

Visit each locale URL (substitute the actual slug):
- `http://localhost/it/projects/test-project-auto-translate`
- `http://localhost/es/projects/test-project-auto-translate`
- `http://localhost/fr/projects/test-project-auto-translate`
- `http://localhost/pt/projects/test-project-auto-translate`
- `http://localhost/de/projects/test-project-auto-translate`

Expected: each page renders with translated title and description.

---

## Task 6: Manual verification — community_project and local_group

- [ ] **Step 1: Repeat Task 5 for `community_project`**

Use the "Refer a Community Project" button instead. Replace `projects` with `communityProjects` in the GraphQL query (use the actual root field — confirm with `scripts/.venv/bin/python scripts/cdcf_api.py graphql --query '{ __schema { queryType { fields { name } } } }' | grep -i communit` if unsure).

For `get-post` calls, use `--post-type community_project`.

Public URLs: `http://localhost/<lang>/community-projects/<slug>` (or whatever the front-end route resolves to — confirm by visiting `http://localhost/community-projects/<slug>` for the EN version first).

Expected: same end state as Task 5 — five published translations.

- [ ] **Step 2: Repeat Task 5 for `local_group`**

Use the "Refer a Local Group" button (or the appropriate referral entry point on the Community page). Replace post-type with `local_group`.

Expected: same end state as Task 5 — five published translations.

---

## Task 7: Negative test — admin-created post must NOT be auto-translated

- [ ] **Step 1: Manually create a project in wp-admin (no submission flow)**

In wp-admin → Projects → Add New. Title: `Admin-Created No Translate Test`. Add a one-line description. Save as Draft.

- [ ] **Step 2: Confirm no submitter meta exists**

```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-meta --post-id <NEW_ID> --post-type project --field _submission_submitter_email
```

Expected: empty / null.

- [ ] **Step 3: Publish the post**

In wp-admin, click **Publish**.

- [ ] **Step 4: Confirm NO translations were created**

Wait ~5 seconds. Run:
```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-translation-ids --post-id <NEW_ID>
```

Expected: only the `en` entry — no `it/es/fr/pt/de` siblings.

- [ ] **Step 5: Clean up**

In wp-admin, trash the test post.

---

## Task 8: Negative test — re-publishing must not duplicate translations

- [ ] **Step 1: Take an already-translated submission post from Task 5**

Use the `EN_ID` from Task 5 (the `Test Project Auto-Translate` post). Confirm it has 5 translations:
```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-translation-ids --post-id <EN_ID>
```

Expected: 6 entries (en + 5 translations).

- [ ] **Step 2: Unpublish then re-publish the EN post**

In wp-admin, switch the EN post to `Draft`, save. Then switch back to `Published`, save.

- [ ] **Step 3: Confirm no duplicates were created**

```bash
scripts/.venv/bin/python scripts/cdcf_api.py get-translation-ids --post-id <EN_ID>
```

Expected: still exactly 6 entries — same IDs as before. No new draft siblings.

Cross-check by listing all `project` posts created in the last 5 minutes:
```bash
scripts/.venv/bin/python scripts/cdcf_api.py graphql --query '{ projects(first: 20, where: { language: EN }) { nodes { databaseId title date } } }'
```

Expected: no fresh duplicates of the test title.

---

## Task 9: Push branch and open PR

- [ ] **Step 1: Push the branch**

Run:
```bash
git push -u origin feature/auto-translate-public-submissions
```

Expected: branch pushed and tracking set up.

- [ ] **Step 2: Open a pull request**

Run:
```bash
gh pr create --title "feat: auto-translate public submissions on approval" --body "$(cat <<'EOF'
## Summary
- When an admin publishes a `project`, `community_project`, or `local_group` that came from the public submission form, automatically create draft sibling posts in `it / es / fr / pt / de`, link them via Polylang, and enqueue background AI translations.
- The existing worker (`cdcf_process_translation`) auto-publishes each translation when its source is `publish`, so no manual per-language approval is needed.
- Single-file change: `wordpress/themes/cdcf-headless/functions.php`. No frontend, DB, or env changes.

## Spec & Plan
- Spec: `docs/superpowers/specs/2026-04-26-auto-translate-public-submissions-design.md`
- Plan: `docs/superpowers/plans/2026-04-26-auto-translate-public-submissions.md`

## Test plan
- [ ] Submit a project via the public "Submit a Project" form, then publish in wp-admin → confirm 5 translation drafts appear, linked via Polylang
- [ ] Wait for worker → confirm each translation transitions to `publish` and renders on `/<lang>/projects/<slug>`
- [ ] Repeat for `community_project` (Refer a Community Project)
- [ ] Repeat for `local_group` (Refer a Local Group)
- [ ] Negative: admin-created project (no submitter meta) does NOT trigger auto-translation
- [ ] Negative: unpublish/re-publish a submission post does NOT create duplicate translations

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR URL printed. Surface it to the user.

---

## Self-Review

**Spec coverage:**
- Architecture: helper functions + hook → ✅ Tasks 2, 3, 4
- Data flow on approval (publish transition fires both hooks) → ✅ Task 4 (hook registered at priority 20, after the existing priority-10 sitemap hook)
- Worker auto-publish behavior → ✅ leveraged in Task 4 design comment, verified in Task 5 step 6
- Idempotency (re-publish doesn't duplicate) → ✅ Task 8
- Gating on submitter meta → ✅ Task 2 (helper) + Task 4 (hook), verified in Task 7
- All three CPTs covered → ✅ Tasks 5 + 6
- Polylang/ACF dependency guards → ✅ Task 3 step 1 (early return + error_log)
- WP-Cron fallback → ✅ Task 3 step 1 (matches existing pattern at functions.php:593-599)
- Feature branch → ✅ Task 1
- No frontend changes / no DB / no new endpoints → ✅ confirmed in plan header

**Placeholder scan:** No "TBD", "TODO", "implement later", or "similar to Task N" without the actual code. Every code-changing step has a complete code block. Every command has expected output. Manual-verification tasks have explicit step-by-step commands using the existing `scripts/cdcf_api.py` CLI.

**Type/name consistency:**
- `cdcf_is_public_submission(int $post_id): bool` — defined in Task 2, called in Task 4 with `$post->ID` (int). ✅
- `cdcf_enqueue_translations_for_submission(int $en_post_id, string $post_type): void` — defined in Task 3, called in Task 4 with `$source_id` and `$post->post_type`. ✅
- `cdcf_get_source_post_id()` — pre-existing (functions.php:4144), used in Task 2 and Task 4. ✅
- `cdcf_enqueue_translation()` / `cdcf_async_translate` — pre-existing, used in Task 3 with the documented WP-Cron fallback. ✅
- Polylang functions (`pll_set_post_language`, `pll_save_post_translations`, `pll_get_post`, `pll_get_post_translations`) — match the names used in the existing `/team-member` endpoint at functions.php:546-590. ✅

No issues found.
