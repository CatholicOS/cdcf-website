# Council Review Phase 4 — Decision, Conversion, Admin Override Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Board member records the outcome of a council review (publish as a CDCF project, convert to a community project, or reject). The submitter is emailed the decision, and an administrator publishing in wp-admin is recorded as an override.

**Architecture:**
- **WordPress:** a new `includes/review/decision.php` module applies the outcome (publish / convert / draft) under an in-progress guard and then records the decision meta. `POST /cdcf/v1/review/projects/{id}/decision` wraps it. A `transition_post_status` hook records admin overrides.
- **Next.js:** gains a typed client call, a route handler, a Board-only decision panel with an email preview, and a decision card on decided reviews.

**Tech Stack:** WordPress theme PHP 8.3 (PHPUnit 10 + Brain Monkey), Next.js 16 App Router, next-intl 4, Vitest 5 (happy-dom for components).

**Spec:** `docs/superpowers/specs/2026-10-15-project-review-design.md` — sections "Decision and conversion", "Admin override", "Submitter emails", "Pages" (the Decision panel), "Data model".

## Global Constraints

- Every new PHP include file starts with the one-line guard `defined('ABSPATH') || exit;`.
- Every `cdcf/v1` route declares a `sanitize_callback` per field in its args; handlers do not re-sanitize. Args with an `enum` also set `'validate_callback' => 'rest_validate_request_arg'`. Review route args live in `cdcf_review_route_args()` in `includes/handlers/review.php`.
- Emails go through `cdcf_review_send_submitter_email()` (Redis queue, inline fallback). Never WP-Cron.
- Never log a submitter address, an email body or a token.
- Next.js pages are Server Components. Only composers and panels are `'use client'`. No JSX inside `try/catch` in pages (`react-hooks/error-boundaries`).
- UI strings live under `Review` / `SubmissionStatus` in all six `messages/*.json` files (en, it, es, fr, pt, de).
- Format TS with `npx prettier --single-quote --no-semi --trailing-comma es5 --write <files you created>`. For existing files, change only your own lines and do not reformat unrelated code.
- PHP tests: `cd wordpress/themes/cdcf-headless && php8.3 vendor/bin/phpunit` (fails on warnings). JS: `npx vitest run`, `npx tsc --noEmit -p .`, `npm run lint`.
- Commit trailers on every commit:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k
  ```

## Planning-time verifications (from the spec's "To verify during planning")

- **Categories:** `project_category` is a free-text field in both `group_project` and `group_community_project`, so no mapping table is needed. Conversion must still rewrite ACF's reference meta: `_project_category` → `field_community_project_category`, `_project_url` → `field_community_project_url`, and the new `_project_github_url` → `field_community_project_github_url`.
- **Projects page revalidation:** publishing revalidates only the `sitemap` tag. Pages use the 60-second ISR default, so `/projects` updates in every locale within a minute. No change is needed.
- **Referral linking:** `cdcf_link_referral_on_publish` already acts on any published `community_project` for which `cdcf_is_public_submission()` is true, and a converted submission keeps `_submission_submitter_email`. So the spec's "extend the hook to treat the marker like referral meta" is not needed. The marker `_cdcf_converted_from_submission` is still written, as a record.
- **Tags:** the `project_tag` taxonomy is registered for both `project` and `community_project`, so terms survive `set_post_type()`.
- **Repository URL:** `project_repo_url` already holds the first submitted repository; `_submission_repo_urls` keeps the full JSON list.

## Review Focus

1. **Two Board members decide at once.** The second must get `409 decision_in_progress` or `403 review_locked`, never a second outcome. (Task 2 lock + test.)
2. **Conversion fails half-way** (publish returns an error). The post must come back as a `pending` `project` with every field it had. (Task 1 rollback test.)
3. **The decision endpoint's own publish must not count as an admin override.** (Task 1 guard + Task 3 test.)
4. **A resend-link request after the 90-day expiry** must not email a link that is already dead. (Task 3.)
5. **A decided review is read-only everywhere.** The decision panel is hidden, composers stay hidden, and a second decision returns `403 review_locked`. (Task 2 test, Task 5 page.)

---

### Task 1: Decision core (`includes/review/decision.php`)

**Files:**
- Create: `wordpress/themes/cdcf-headless/includes/review/decision.php`
- Modify: `wordpress/themes/cdcf-headless/functions.php` (require it after `mailer.php`, line ~811)
- Modify: `wordpress/themes/cdcf-headless/tests/bootstrap.php` (require it after `review/mailer.php`, line ~255)
- Test: `wordpress/themes/cdcf-headless/tests/ReviewDecisionTest.php`

**Interfaces:**
- Produces:
  - `const CDCF_REVIEW_DECISION_TOKEN_TTL = 90 * DAY_IN_SECONDS;`
  - `function cdcf_review_deciding(?bool $set = null): bool` — get/set the in-progress flag (static).
  - `function cdcf_review_apply_outcome(int $project_id, string $decision): true|WP_Error` — `cdcf` → publish; `community` → convert and publish; `reject` → draft. Runs with `cdcf_review_deciding(true)` and always resets it to false.
  - `function cdcf_review_convert_to_community(int $project_id): true|WP_Error`.
  - `function cdcf_review_record_decision(int $project_id, string $decision, ?int $user_id, string $rationale, string $summary, bool $admin_override = false): void` — writes `_cdcf_review_decision`, `_cdcf_review_decided_by` (0 when null), `_cdcf_review_decided_at` (`time()`), `_cdcf_review_rationale`, `_cdcf_review_summary`, `_cdcf_review_admin_override` (`'1'` or deleted), stage `decided`, and `CDCF_REVIEW_TOKEN_EXPIRES_META` = decided_at + TTL.
  - `function cdcf_review_present_decision(int $project_id): array{decision: string, rationale: string, summary: string, decided_at: int, decided_by: array{id: int, name: string}|null, admin_override: bool}`.

- [ ] **Step 1: Write the failing tests**

Model the setup on `tests/ReviewQuestionHandlerTest.php`: Brain Monkey, an in-memory `$this->meta` array behind `get_post_meta` / `update_post_meta` / `delete_post_meta` aliases, and `$this->posts` for `get_post_type` / `set_post_type` / `wp_update_post`. Tests:

```php
public function test_cdcf_publishes_the_project(): void
{
    $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
    $this->assertTrue(cdcf_review_apply_outcome(40, 'cdcf'));
    $this->assertSame('publish', $this->posts[40]['post_status']);
    $this->assertSame('project', $this->posts[40]['post_type']);
}

public function test_reject_moves_the_project_to_draft_not_trash(): void
{
    $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
    $this->assertTrue(cdcf_review_apply_outcome(40, 'reject'));
    $this->assertSame('draft', $this->posts[40]['post_status']);
}

public function test_community_converts_fields_type_and_publishes(): void
{
    $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
    $this->meta[40] = [
        'project_url' => 'https://example.org', '_project_url' => 'field_project_url',
        'project_category' => 'Liturgy', '_project_category' => 'field_project_category',
        'project_repo_url' => 'https://github.com/a/b', '_project_repo_url' => 'field_project_repo_url',
        'project_status' => 'incubating', '_project_status' => 'field_project_status',
        'project_license' => 'MIT', '_project_license' => 'field_project_license',
        'project_leads' => [3], '_project_leads' => 'field_project_leads',
        '_submission_submitter_email' => 'sam@example.org',
    ];

    $this->assertTrue(cdcf_review_apply_outcome(40, 'community'));

    $this->assertSame('community_project', $this->posts[40]['post_type']);
    $this->assertSame('publish', $this->posts[40]['post_status']);
    $m = $this->meta[40];
    $this->assertSame('https://github.com/a/b', $m['project_github_url']);
    $this->assertSame('field_community_project_github_url', $m['_project_github_url']);
    $this->assertSame('field_community_project_url', $m['_project_url']);
    $this->assertSame('field_community_project_category', $m['_project_category']);
    $this->assertSame('https://example.org', $m['project_url']);
    $this->assertSame('Liturgy', $m['project_category']);
    foreach (['project_status', 'project_license', 'project_leads', 'project_repo_url'] as $f) {
        $this->assertArrayNotHasKey($f, $m);
        $this->assertArrayNotHasKey('_' . $f, $m);
    }
    $this->assertSame('1', $m['_cdcf_converted_from_submission']);
    $this->assertSame('sam@example.org', $m['_submission_submitter_email']);
}

public function test_failed_conversion_restores_the_project(): void
{
    $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
    $before = [
        'project_url' => 'https://example.org', '_project_url' => 'field_project_url',
        'project_repo_url' => 'https://github.com/a/b', '_project_repo_url' => 'field_project_repo_url',
        'project_leads' => [3], '_project_leads' => 'field_project_leads',
    ];
    $this->meta[40] = $before;
    $this->failPublish = true; // wp_update_post alias returns WP_Error

    $result = cdcf_review_apply_outcome(40, 'community');

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('conversion_failed', $result->get_error_code());
    $this->assertSame(500, $result->get_error_data()['status']);
    $this->assertSame('project', $this->posts[40]['post_type']);
    $this->assertSame('pending', $this->posts[40]['post_status']);
    $this->assertSame($before, $this->meta[40]);
}

public function test_failed_publish_reports_an_error(): void
{
    $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
    $this->failPublish = true;
    $result = cdcf_review_apply_outcome(40, 'cdcf');
    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('outcome_failed', $result->get_error_code());
}

public function test_the_guard_is_on_only_while_the_outcome_runs(): void
{
    $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
    $seen = null;
    $this->onUpdate = function () use (&$seen) { $seen = cdcf_review_deciding(); };
    cdcf_review_apply_outcome(40, 'cdcf');
    $this->assertTrue($seen);
    $this->assertFalse(cdcf_review_deciding());
    $this->failPublish = true;
    cdcf_review_apply_outcome(40, 'cdcf');
    $this->assertFalse(cdcf_review_deciding());
}

public function test_record_decision_writes_meta_stage_and_token_expiry(): void
{
    $before = time();
    cdcf_review_record_decision(40, 'community', 7, 'Better as a community project', 'Thank you!');
    $m = $this->meta[40];
    $this->assertSame('community', $m['_cdcf_review_decision']);
    $this->assertSame(7, (int) $m['_cdcf_review_decided_by']);
    $this->assertGreaterThanOrEqual($before, (int) $m['_cdcf_review_decided_at']);
    $this->assertSame('Better as a community project', $m['_cdcf_review_rationale']);
    $this->assertSame('Thank you!', $m['_cdcf_review_summary']);
    $this->assertSame('decided', $m[CDCF_REVIEW_STAGE_META]);
    $this->assertSame((int) $m['_cdcf_review_decided_at'] + CDCF_REVIEW_DECISION_TOKEN_TTL, (int) $m[CDCF_REVIEW_TOKEN_EXPIRES_META]);
    $this->assertArrayNotHasKey('_cdcf_review_admin_override', $m);
}

public function test_present_decision_names_the_decider(): void
{
    Functions\when('get_userdata')->justReturn((object) ['display_name' => 'Fr. A']);
    cdcf_review_record_decision(40, 'cdcf', null, '', '', true);
    $d = cdcf_review_present_decision(40);
    $this->assertSame('cdcf', $d['decision']);
    $this->assertNull($d['decided_by']);
    $this->assertTrue($d['admin_override']);
    cdcf_review_record_decision(40, 'reject', 7, 'r', 's');
    $this->assertSame(['id' => 7, 'name' => 'Fr. A'], cdcf_review_present_decision(40)['decided_by']);
    $this->assertFalse(cdcf_review_present_decision(40)['admin_override']);
}
```

The `wp_update_post` alias: `function (array $args, bool $wp_error = false) { if ($this->onUpdate) ($this->onUpdate)(); if ($this->failPublish) return new WP_Error('db', 'nope'); $this->posts[$args['ID']]['post_status'] = $args['post_status']; return $args['ID']; }`. `set_post_type` alias: `fn(int $id, string $t) => ($this->posts[$id]['post_type'] = $t) ? 1 : 0`. `get_post_type` alias reads `$this->posts`. `get_post_status` alias reads `$this->posts`. `delete_post_meta` alias unsets the key. `update_post_meta` alias assigns.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd wordpress/themes/cdcf-headless && php8.3 vendor/bin/phpunit tests/ReviewDecisionTest.php`
Expected: errors "Call to undefined function cdcf_review_apply_outcome()" (after the bootstrap require is added, the file is missing → add the require in Step 3).

- [ ] **Step 3: Implement**

`includes/review/decision.php`:

```php
<?php
/**
 * Council review — applying and recording the Board's decision.
 *
 * The outcome (publish, convert to community project, or move to draft) is
 * applied first and the decision is recorded only when it succeeded. While
 * the outcome runs, cdcf_review_deciding() is true so the admin-override
 * hook does not mistake the endpoint's own publish for an override.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_DECISION_TOKEN_TTL = 90 * DAY_IN_SECONDS;

/** Fields only the CDCF project type has; removed on conversion. */
const CDCF_REVIEW_PROJECT_ONLY_FIELDS = ['project_status', 'project_license', 'project_leads', 'project_repo_url'];

/** ACF reference meta for the fields the community type keeps or gains. */
const CDCF_REVIEW_COMMUNITY_FIELD_KEYS = [
    'project_url'        => 'field_community_project_url',
    'project_category'   => 'field_community_project_category',
    'project_github_url' => 'field_community_project_github_url',
];

function cdcf_review_deciding(?bool $set = null): bool {
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/** @return true|WP_Error */
function cdcf_review_apply_outcome(int $project_id, string $decision) {
    cdcf_review_deciding(true);
    try {
        if ($decision === 'community') {
            return cdcf_review_convert_to_community($project_id);
        }
        $result = wp_update_post([
            'ID'          => $project_id,
            'post_status' => $decision === 'cdcf' ? 'publish' : 'draft',
        ], true);
        if (is_wp_error($result) || !$result) {
            return new WP_Error('outcome_failed', 'The decision could not be applied to the project.', ['status' => 500]);
        }
        return true;
    } finally {
        cdcf_review_deciding(false);
    }
}

/** @return true|WP_Error */
function cdcf_review_convert_to_community(int $project_id) {
    $keys = array_merge(
        CDCF_REVIEW_PROJECT_ONLY_FIELDS,
        array_map(static fn($f) => '_' . $f, CDCF_REVIEW_PROJECT_ONLY_FIELDS),
        array_keys(CDCF_REVIEW_COMMUNITY_FIELD_KEYS),
        array_map(static fn($f) => '_' . $f, array_keys(CDCF_REVIEW_COMMUNITY_FIELD_KEYS)),
        ['_cdcf_converted_from_submission']
    );
    $snapshot = [];
    foreach ($keys as $key) {
        $snapshot[$key] = metadata_exists('post', $project_id, $key) ? get_post_meta($project_id, $key, true) : null;
    }
    $type = (string) get_post_type($project_id);

    $repo = (string) get_post_meta($project_id, 'project_repo_url', true);
    if ($repo !== '') {
        update_post_meta($project_id, 'project_github_url', $repo);
    }
    foreach (CDCF_REVIEW_COMMUNITY_FIELD_KEYS as $field => $acf_key) {
        if ($field !== 'project_github_url' || $repo !== '') {
            update_post_meta($project_id, '_' . $field, $acf_key);
        }
    }
    foreach (CDCF_REVIEW_PROJECT_ONLY_FIELDS as $field) {
        delete_post_meta($project_id, $field);
        delete_post_meta($project_id, '_' . $field);
    }
    set_post_type($project_id, 'community_project');
    update_post_meta($project_id, '_cdcf_converted_from_submission', '1');

    $result = wp_update_post(['ID' => $project_id, 'post_status' => 'publish'], true);
    if (!is_wp_error($result) && $result) {
        return true;
    }

    // Roll back: the same project type and fields as before.
    set_post_type($project_id, $type);
    foreach ($snapshot as $key => $value) {
        if ($value === null) {
            delete_post_meta($project_id, $key);
        } else {
            update_post_meta($project_id, $key, $value);
        }
    }
    return new WP_Error('conversion_failed', 'The project could not be converted to a community project.', ['status' => 500]);
}

function cdcf_review_record_decision(
    int $project_id,
    string $decision,
    ?int $user_id,
    string $rationale,
    string $summary,
    bool $admin_override = false
): void {
    $now = time();
    update_post_meta($project_id, '_cdcf_review_decision', $decision);
    update_post_meta($project_id, '_cdcf_review_decided_by', (int) $user_id);
    update_post_meta($project_id, '_cdcf_review_decided_at', $now);
    update_post_meta($project_id, '_cdcf_review_rationale', $rationale);
    update_post_meta($project_id, '_cdcf_review_summary', $summary);
    if ($admin_override) {
        update_post_meta($project_id, '_cdcf_review_admin_override', '1');
    } else {
        delete_post_meta($project_id, '_cdcf_review_admin_override');
    }
    update_post_meta($project_id, CDCF_REVIEW_STAGE_META, 'decided');
    // The submitter's link keeps working for 90 days after the decision.
    update_post_meta($project_id, CDCF_REVIEW_TOKEN_EXPIRES_META, $now + CDCF_REVIEW_DECISION_TOKEN_TTL);
}

/** @return array{decision: string, rationale: string, summary: string, decided_at: int, decided_by: array{id: int, name: string}|null, admin_override: bool} */
function cdcf_review_present_decision(int $project_id): array {
    $uid  = (int) get_post_meta($project_id, '_cdcf_review_decided_by', true);
    $user = $uid > 0 ? get_userdata($uid) : null;
    return [
        'decision'       => (string) get_post_meta($project_id, '_cdcf_review_decision', true),
        'rationale'      => (string) get_post_meta($project_id, '_cdcf_review_rationale', true),
        'summary'        => (string) get_post_meta($project_id, '_cdcf_review_summary', true),
        'decided_at'     => (int) get_post_meta($project_id, '_cdcf_review_decided_at', true),
        'decided_by'     => $uid > 0 ? ['id' => $uid, 'name' => $user ? (string) $user->display_name : 'Former member'] : null,
        'admin_override' => get_post_meta($project_id, '_cdcf_review_admin_override', true) === '1',
    ];
}
```

The test file aliases `metadata_exists` to `fn($type, $id, $key) => array_key_exists($key, $this->meta[$id] ?? [])`. Add `require_once __DIR__ . '/includes/review/decision.php';` after the mailer require in `functions.php`, and the matching `require_once __DIR__ . '/../includes/review/decision.php';` in `tests/bootstrap.php`.

- [ ] **Step 4: Run the tests and the suite**

Run: `php8.3 vendor/bin/phpunit tests/ReviewDecisionTest.php` → PASS. Then `php8.3 vendor/bin/phpunit` → OK.

- [ ] **Step 5: Commit**

```bash
git add wordpress/themes/cdcf-headless/includes/review/decision.php wordpress/themes/cdcf-headless/functions.php wordpress/themes/cdcf-headless/tests/bootstrap.php wordpress/themes/cdcf-headless/tests/ReviewDecisionTest.php
git commit -m "feat(review): apply and record the Board's decision"
```

---

### Task 2: Decision endpoint, decision email, workspace fields

**Files:**
- Modify: `wordpress/themes/cdcf-headless/includes/handlers/review.php` (new permission callback, handler, args; GET project fields)
- Modify: `wordpress/themes/cdcf-headless/functions.php` (route table, line ~844)
- Modify: `wordpress/themes/cdcf-headless/includes/review/email-strings.php` (template `decision`, outcome labels, `{outcome}` `{summary}`)
- Test: `wordpress/themes/cdcf-headless/tests/ReviewDecisionHandlerTest.php`; extend `tests/ReviewEmailStringsTest.php` and `tests/ReviewReadHandlerTest.php`

**Interfaces:**
- Consumes (Task 1): `cdcf_review_apply_outcome`, `cdcf_review_record_decision`, `cdcf_review_present_decision`.
- Produces:
  - `POST /cdcf/v1/review/projects/{id}/decision` with body `{decision: 'cdcf'|'community'|'reject', rationale: string, submitter_summary: string}`.
    - `200` response: `{id, stage: 'decided', decision, post_type, post_status, warnings: string[]}`.
    - Errors:
      - `400 summary_required`, `400 rationale_required`, `400 body_too_long`;
      - `403 review_locked`;
      - `404 review_not_found`;
      - `409 decision_in_progress`;
      - `500` from the outcome.
  - `function cdcf_rest_review_permission_decide(WP_REST_Request $request)`.
  - `function cdcf_review_outcome_labels(): array<string, array<string, string>>` (lang → outcome → label).
  - `function cdcf_review_decision_email_preview(int $project_id): array{language: string, subject: string, body: string, outcomes: array<string, string>}` — body and subject keep literal `{summary}`, `{outcome}` and `{link}`.
  - GET `/review/projects/{id}` gains `decision` (`cdcf_review_present_decision()` when stage is `decided`, else `null`) and `decision_email` (the preview, only when the caller can decide and the stage is not `decided`, else `null`).
  - Constant `CDCF_REVIEW_SUMMARY_MAX = 10000`.

- [ ] **Step 1: Write the failing tests**

`ReviewDecisionHandlerTest.php` (model on `ReviewQuestionHandlerTest.php`; stub `cdcf_review_apply_outcome`, `cdcf_review_record_decision`, `cdcf_review_record_event`, `cdcf_review_send_submitter_email`, `cdcf_review_tallies`, `cdcf_review_get_reviewable_project`, `get_current_user_id` → 9; in-memory meta with stage `in_review`):

```php
private function decide(array $params): mixed
{
    $r = new WP_REST_Request();
    foreach (['id' => 40, 'rationale' => '', 'submitter_summary' => 'Thank you.'] + $params as $k => $v) {
        $r->set_param($k, $v);
    }
    return cdcf_rest_review_post_decision($r);
}

public function test_following_the_plurality_needs_no_rationale(): void
{
    $this->tally = ['cdcf' => 2, 'community' => 1, 'reject' => 0];
    $res = $this->decide(['decision' => 'cdcf']);
    $this->assertSame(200, $res->get_status());
    $this->assertSame(['id' => 40, 'stage' => 'decided', 'decision' => 'cdcf', 'post_type' => 'project', 'post_status' => 'publish', 'warnings' => []], $res->get_data());
    $this->assertSame([[40, 'cdcf']], $this->applied);
    $this->assertSame([[40, 'cdcf', 9, '', 'Thank you.']], $this->recorded);
    $this->assertSame('decision_recorded', $this->events[0][1]);
    $this->assertSame([[40, 'decision', ['outcome' => 'cdcf', 'summary' => 'Thank you.']]], $this->emails);
}

public function test_overriding_the_plurality_requires_a_rationale(): void
{
    $this->tally = ['cdcf' => 2, 'community' => 0, 'reject' => 0];
    $res = $this->decide(['decision' => 'reject']);
    $this->assertSame('rationale_required', $res->get_error_code());
    $this->assertSame(400, $res->get_error_data()['status']);
    $this->assertSame([], $this->applied);
}

public function test_no_votes_or_a_tie_requires_a_rationale(): void
{
    $this->tally = ['cdcf' => 1, 'community' => 1, 'reject' => 0];
    $this->assertSame('rationale_required', $this->decide(['decision' => 'cdcf'])->get_error_code());
    $this->tally = ['cdcf' => 0, 'community' => 0, 'reject' => 0];
    $this->assertSame('rationale_required', $this->decide(['decision' => 'cdcf'])->get_error_code());
    $this->assertSame(200, $this->decide(['decision' => 'cdcf', 'rationale' => 'Clear case.'])->get_status());
}

public function test_a_summary_is_required(): void
{
    $this->tally = ['cdcf' => 1, 'community' => 0, 'reject' => 0];
    $this->assertSame('summary_required', $this->decide(['decision' => 'cdcf', 'submitter_summary' => '  '])->get_error_code());
}

public function test_overlong_texts_are_rejected(): void
{
    $this->tally = ['cdcf' => 1, 'community' => 0, 'reject' => 0];
    $this->assertSame('body_too_long', $this->decide(['decision' => 'cdcf', 'submitter_summary' => str_repeat('a', 10001)])->get_error_code());
    $this->assertSame('body_too_long', $this->decide(['decision' => 'cdcf', 'rationale' => str_repeat('a', 50001)])->get_error_code());
}

public function test_a_decided_review_is_locked(): void
{
    $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
    $this->assertSame('review_locked', $this->decide(['decision' => 'cdcf', 'rationale' => 'x'])->get_error_code());
}

public function test_a_concurrent_decision_is_refused(): void
{
    $this->meta[40]['_cdcf_review_deciding'] = (string) time();
    $res = $this->decide(['decision' => 'cdcf', 'rationale' => 'x']);
    $this->assertSame('decision_in_progress', $res->get_error_code());
    $this->assertSame(409, $res->get_error_data()['status']);
    $this->assertSame([], $this->applied);
}

public function test_a_stale_lock_is_taken_over(): void
{
    $this->meta[40]['_cdcf_review_deciding'] = (string) (time() - 600);
    $this->assertSame(200, $this->decide(['decision' => 'cdcf', 'rationale' => 'x'])->get_status());
    $this->assertArrayNotHasKey('_cdcf_review_deciding', $this->meta[40]);
}

public function test_a_failed_outcome_records_nothing_and_releases_the_lock(): void
{
    $this->applyResult = new WP_Error('conversion_failed', 'no', ['status' => 500]);
    $res = $this->decide(['decision' => 'community', 'rationale' => 'x']);
    $this->assertSame('conversion_failed', $res->get_error_code());
    $this->assertSame([], $this->recorded);
    $this->assertSame([], $this->emails);
    $this->assertArrayNotHasKey('_cdcf_review_deciding', $this->meta[40]);
}

public function test_an_email_failure_is_a_warning_not_an_error(): void
{
    $this->tally = ['cdcf' => 1, 'community' => 0, 'reject' => 0];
    $this->emailResult = 'failed';
    $res = $this->decide(['decision' => 'cdcf']);
    $this->assertSame(200, $res->get_status());
    $this->assertSame(['email_failed'], $res->get_data()['warnings']);
}

public function test_only_board_members_may_decide(): void
{
    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('cdcf_review_can')->alias(fn(int $uid, string $p) => $p !== 'decide');
    $this->assertSame('rest_forbidden', cdcf_rest_review_permission_decide(new WP_REST_Request())->get_error_code());
}

public function test_decision_args_validate_the_outcome(): void
{
    $args = cdcf_review_route_args()['/review/projects/(?P<id>\d+)/decision'];
    $this->assertSame(CDCF_REVIEW_VOTES, $args['decision']['enum']);
    $this->assertSame('rest_validate_request_arg', $args['decision']['validate_callback']);
    $this->assertSame('sanitize_textarea_field', $args['rationale']['sanitize_callback']);
    $this->assertSame('sanitize_textarea_field', $args['submitter_summary']['sanitize_callback']);
}
```

Lock semantics for the test doubles: `add_post_meta($id, $key, $value, true)` alias returns `false` when the key exists in `$this->meta[$id]`, otherwise stores it and returns an int. The handler reads `post_type` and `post_status` after the outcome with `get_post_type` / `get_post_status` (aliases return `'project'` and `'publish'`).

Extend `ReviewEmailStringsTest.php`:

```php
public function test_decision_email_names_the_outcome_and_carries_the_summary_in_every_language(): void
{
    foreach (['en', 'it', 'es', 'fr', 'pt', 'de'] as $lang) {
        $mail = cdcf_review_render_email('decision', $lang, [
            'title' => 'Liturgy Helper', 'link' => 'https://x/l', 'outcome' => 'community', 'summary' => 'Well done.',
        ]);
        $this->assertStringContainsString('Liturgy Helper', $mail['subject'] . $mail['body'], $lang);
        $this->assertStringContainsString(cdcf_review_outcome_labels()[$lang]['community'], $mail['body'], $lang);
        $this->assertStringContainsString('Well done.', $mail['body'], $lang);
        $this->assertStringContainsString('https://x/l', $mail['body'], $lang);
    }
}

public function test_outcome_labels_match_the_status_page(): void
{
    foreach (['en', 'it', 'es', 'fr', 'pt', 'de'] as $lang) {
        $messages = json_decode(file_get_contents(__DIR__ . "/../../../../messages/{$lang}.json"), true);
        $this->assertSame($messages['SubmissionStatus']['outcome'], cdcf_review_outcome_labels()[$lang], $lang);
    }
}
```

Extend `ReviewReadHandlerTest.php`. In both tests, `decision` is `null` while undecided and `decision_email` is `null` for a non-decider:

```php
public function test_board_members_get_a_decision_email_preview_while_undecided(): void
{
    // caller can decide; stage in_review; post language 'it'
    $data = cdcf_rest_review_get_project($this->request(40))->get_data();
    $this->assertNull($data['decision']);
    $this->assertSame('it', $data['decision_email']['language']);
    $this->assertStringContainsString('{summary}', $data['decision_email']['body']);
    $this->assertStringContainsString('{outcome}', $data['decision_email']['body']);
    $this->assertSame(cdcf_review_outcome_labels()['it'], $data['decision_email']['outcomes']);
}

public function test_a_decided_project_shows_the_decision_and_no_preview(): void
{
    // stage decided, decision meta present
    $data = cdcf_rest_review_get_project($this->request(40))->get_data();
    $this->assertSame('community', $data['decision']['decision']);
    $this->assertNull($data['decision_email']);
}
```

Adapt the arrange part of these two tests to that file's existing fixtures (read the file first and reuse its `request()` / membership stubs).

- [ ] **Step 2: Run them to verify they fail**

Run: `php8.3 vendor/bin/phpunit --filter 'Decision|decision|outcome_labels'`
Expected: undefined function `cdcf_rest_review_post_decision` / `cdcf_review_outcome_labels`, and missing `decision` keys.

- [ ] **Step 3: Implement**

In `email-strings.php`:
- Update the header comment's placeholder list to `{title}, {link}, {question}, {outcome}, {summary}`.
- Add the `decision` template to every language as shown below.
- Add `cdcf_review_outcome_labels()`.
- Make `cdcf_review_render_email()` map `{outcome}` to the label, falling back to the raw value so a literal `{outcome}` survives for the preview, and map `{summary}`.

```php
// en
'decision' => [
    'subject' => 'Decision on your project: {title}',
    'body'    => "The review of \"{title}\" is complete.\n\nDecision: {outcome}\n\n{summary}\n\nYou can see the decision on your submission's status page:\n\n{link}\n\nThis link replaces any earlier one and works for 90 days." . $sig,
],
// it
'decision' => [
    'subject' => 'Decisione sul tuo progetto: {title}',
    'body'    => "La revisione di \"{title}\" è conclusa.\n\nDecisione: {outcome}\n\n{summary}\n\nPuoi vedere la decisione nella pagina di stato della tua proposta:\n\n{link}\n\nQuesto link sostituisce quelli precedenti e resta valido per 90 giorni." . $sig,
],
// es
'decision' => [
    'subject' => 'Decisión sobre tu proyecto: {title}',
    'body'    => "La revisión de \"{title}\" ha concluido.\n\nDecisión: {outcome}\n\n{summary}\n\nPuedes ver la decisión en la página de estado de tu propuesta:\n\n{link}\n\nEste enlace sustituye a los anteriores y es válido durante 90 días." . $sig,
],
// fr
'decision' => [
    'subject' => 'Décision concernant votre projet : {title}',
    'body'    => "L'examen de « {title} » est terminé.\n\nDécision : {outcome}\n\n{summary}\n\nVous pouvez consulter la décision sur la page de suivi de votre proposition :\n\n{link}\n\nCe lien remplace les précédents et reste valable 90 jours." . $sig,
],
// pt
'decision' => [
    'subject' => 'Decisão sobre o seu projeto: {title}',
    'body'    => "A avaliação de \"{title}\" está concluída.\n\nDecisão: {outcome}\n\n{summary}\n\nPode ver a decisão na página de estado da sua proposta:\n\n{link}\n\nEste link substitui os anteriores e é válido durante 90 dias." . $sig,
],
// de
'decision' => [
    'subject' => 'Entscheidung zu Ihrem Projekt: {title}',
    'body'    => "Die Prüfung von „{title}“ ist abgeschlossen.\n\nEntscheidung: {outcome}\n\n{summary}\n\nDie Entscheidung finden Sie auf der Statusseite Ihres Vorschlags:\n\n{link}\n\nDieser Link ersetzt alle früheren und ist 90 Tage gültig." . $sig,
],
```

Before adding the FR and DE templates, check that file's existing FR/DE quoting style and match it, so the new templates read the same as the existing ones.

```php
/** Same labels as SubmissionStatus.outcome in messages/*.json (a test keeps them in step). */
function cdcf_review_outcome_labels(): array {
    return [
        'en' => ['cdcf' => 'Accepted as a CDCF project', 'community' => 'Accepted as a community project', 'reject' => 'Not accepted'],
        'it' => ['cdcf' => 'Accettato come progetto CDCF', 'community' => 'Accettato come progetto della comunità', 'reject' => 'Non accettato'],
        'es' => ['cdcf' => 'Aceptado como proyecto de la CDCF', 'community' => 'Aceptado como proyecto comunitario', 'reject' => 'No aceptado'],
        'fr' => ['cdcf' => 'Accepté comme projet de la CDCF', 'community' => 'Accepté comme projet communautaire', 'reject' => 'Non retenu'],
        'pt' => ['cdcf' => 'Aceite como projeto CDCF', 'community' => 'Aceite como projeto da comunidade', 'reject' => 'Não aceite'],
        'de' => ['cdcf' => 'Als CDCF-Projekt angenommen', 'community' => 'Als Community-Projekt angenommen', 'reject' => 'Nicht angenommen'],
    ];
}

function cdcf_review_render_email(string $template, string $lang, array $vars): array {
    $all    = cdcf_review_email_strings();
    $raw    = $all[$lang][$template] ?? $all['en'][$template];
    $labels = cdcf_review_outcome_labels()[$lang] ?? cdcf_review_outcome_labels()['en'];
    $map    = [];
    foreach (['title', 'link', 'question', 'summary'] as $key) {
        $map['{' . $key . '}'] = (string) ($vars[$key] ?? '');
    }
    $outcome          = (string) ($vars['outcome'] ?? '');
    $map['{outcome}'] = $labels[$outcome] ?? $outcome;
    return ['subject' => strtr($raw['subject'], $map), 'body' => strtr($raw['body'], $map)];
}
```

In `includes/handlers/review.php`:
- Add `const CDCF_REVIEW_SUMMARY_MAX = 10000;` near the top (after the guard).
- Add `cdcf_rest_review_permission_decide()` next to the other permission callbacks: `unset($request); return cdcf_review_check_permission('decide');`.
- Add `POST /review/projects/{id}/decision` to the docblock list at the top of the file.

```php
/** Lock against two Board members deciding at once; stale after 5 minutes. */
function cdcf_review_take_decision_lock(int $project_id): bool {
    if (add_post_meta($project_id, '_cdcf_review_deciding', (string) time(), true)) {
        return true;
    }
    $since = (int) get_post_meta($project_id, '_cdcf_review_deciding', true);
    if ($since > 0 && time() - $since > 300) {
        delete_post_meta($project_id, '_cdcf_review_deciding');
        return (bool) add_post_meta($project_id, '_cdcf_review_deciding', (string) time(), true);
    }
    return false;
}

function cdcf_rest_review_post_decision(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $post = cdcf_review_get_reviewable_project($pid);
    if (is_wp_error($post)) {
        return $post;
    }
    $open = cdcf_review_assert_open($pid);
    if (is_wp_error($open)) {
        return $open;
    }
    $decision  = (string) $request['decision'];
    $rationale = trim((string) $request['rationale']);
    $summary   = trim((string) $request['submitter_summary']);
    if ($summary === '') {
        return new WP_Error('summary_required', 'Write a summary for the submitter.', ['status' => 400]);
    }
    if (mb_strlen($summary) > CDCF_REVIEW_SUMMARY_MAX || mb_strlen($rationale) > CDCF_REVIEW_BODY_MAX) {
        return new WP_Error('body_too_long', 'This text is too long.', ['status' => 400]);
    }
    $tally     = cdcf_review_tallies([$pid])[$pid] ?? cdcf_review_empty_tally();
    $plurality = cdcf_review_plurality($tally);
    if ($decision !== $plurality && $rationale === '') {
        return new WP_Error('rationale_required', 'Explain why the decision differs from the councils\' plurality vote.', ['status' => 400]);
    }
    if (!cdcf_review_take_decision_lock($pid)) {
        return new WP_Error('decision_in_progress', 'Another decision on this project is being recorded.', ['status' => 409]);
    }
    try {
        $applied = cdcf_review_apply_outcome($pid, $decision);
        if (is_wp_error($applied)) {
            return $applied;
        }
        $uid = get_current_user_id();
        cdcf_review_record_decision($pid, $decision, $uid, $rationale, $summary);
        cdcf_review_record_event($pid, 'decision_recorded', $uid, null, ['decision' => $decision, 'plurality' => $plurality]);
    } finally {
        delete_post_meta($pid, '_cdcf_review_deciding');
    }

    $email = cdcf_review_send_submitter_email($pid, 'decision', ['outcome' => $decision, 'summary' => $summary]);
    return rest_ensure_response([
        'id'          => $pid,
        'stage'       => 'decided',
        'decision'    => $decision,
        'post_type'   => (string) get_post_type($pid),
        'post_status' => (string) get_post_status($pid),
        'warnings'    => in_array($email, ['failed', 'no_email'], true) ? ['email_failed'] : [],
    ]);
}

/** @return array{language: string, subject: string, body: string, outcomes: array<string, string>} */
function cdcf_review_decision_email_preview(int $project_id): array {
    $lang = cdcf_review_post_language($project_id);
    $mail = cdcf_review_render_email('decision', $lang, [
        'title'   => (string) get_post_field('post_title', $project_id),
        'link'    => '{link}',
        'outcome' => '{outcome}',
        'summary' => '{summary}',
    ]);
    $labels = cdcf_review_outcome_labels();
    return $mail + ['language' => $lang, 'outcomes' => $labels[$lang] ?? $labels['en']];
}
```

Note the order: `cdcf_review_record_decision` writes stage `decided` before the lock is released, so a second caller blocked by the lock is then refused by `cdcf_review_assert_open`.

In `cdcf_rest_review_get_project()`, compute `$stage` once and add these two keys to the response array, after `'questions'`:

```php
'decision'       => $stage === 'decided' ? cdcf_review_present_decision($pid) : null,
'decision_email' => $can_decide && $stage !== 'decided' ? cdcf_review_decision_email_preview($pid) : null,
```

In `cdcf_review_route_args()`, add:

```php
'/review/projects/(?P<id>\d+)/decision' => [
    'id'                => $id,
    'decision'          => [
        'required'          => true,
        'type'              => 'string',
        'enum'              => CDCF_REVIEW_VOTES,
        'sanitize_callback' => 'sanitize_key',
        'validate_callback' => 'rest_validate_request_arg',
    ],
    'rationale'         => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field'],
    'submitter_summary' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
],
```

In `functions.php`, add a row to the review route table after the question route:

```php
        '/review/projects/(?P<id>\d+)/decision'  => ['POST', 'cdcf_rest_review_post_decision', 'cdcf_rest_review_permission_decide'],
```

- [ ] **Step 4: Run the tests and the suite**

Run: `php8.3 vendor/bin/phpunit` → OK.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(review): decision endpoint and decision email" # plus git add for the new test file
```

---

### Task 3: Admin override, edit-screen notice, resend after expiry, community listing link

**Files:**
- Modify: `wordpress/themes/cdcf-headless/includes/review/decision.php` (override hook, notice)
- Modify: `wordpress/themes/cdcf-headless/functions.php` (register the hooks next to the other review hooks, line ~834)
- Modify: `wordpress/themes/cdcf-headless/includes/handlers/submission-status.php` (resend after expiry; public URL for community projects)
- Test: `tests/ReviewAdminOverrideTest.php`; extend `tests/SubmissionStatusHandlerTest.php`

**Interfaces:**
- Consumes (Task 1): `cdcf_review_deciding`, `cdcf_review_record_decision`.
- Produces:
  - `function cdcf_review_admin_override_on_publish($new_status, $old_status, $post): void`, hooked as `add_action('transition_post_status', 'cdcf_review_admin_override_on_publish', 15, 3);`
  - `function cdcf_review_edit_screen_notice(): void`, hooked on `admin_notices`
  - `function cdcf_review_projects_page_path(string $lang): ?string`

- [ ] **Step 1: Write the failing tests**

`ReviewAdminOverrideTest.php`:

```php
public function test_publishing_an_undecided_review_records_an_override(): void
{
    $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'in_review'];
    Functions\when('get_current_user_id')->justReturn(1);
    cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 40, 'post_type' => 'project']);
    $this->assertSame('decided', $this->meta[40][CDCF_REVIEW_STAGE_META]);
    $this->assertSame('cdcf', $this->meta[40]['_cdcf_review_decision']);
    $this->assertSame('1', $this->meta[40]['_cdcf_review_admin_override']);
    $this->assertSame([[40, 'admin_override', 1]], $this->events);
    $this->assertSame([], $this->emails); // no submitter email
}

public function test_the_decision_endpoints_own_publish_is_not_an_override(): void
{
    $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'in_review'];
    cdcf_review_deciding(true);
    cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 40, 'post_type' => 'project']);
    cdcf_review_deciding(false);
    $this->assertSame('in_review', $this->meta[40][CDCF_REVIEW_STAGE_META]);
    $this->assertSame([], $this->events);
}

public function test_other_transitions_and_posts_are_ignored(): void
{
    $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'decided'];
    $this->meta[41] = [];
    $this->meta[42] = [CDCF_REVIEW_STAGE_META => 'in_review'];
    cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 40, 'post_type' => 'project']);
    cdcf_review_admin_override_on_publish('publish', 'pending', (object) ['ID' => 41, 'post_type' => 'project']);
    cdcf_review_admin_override_on_publish('publish', 'publish', (object) ['ID' => 42, 'post_type' => 'project']);
    cdcf_review_admin_override_on_publish('draft', 'pending', (object) ['ID' => 42, 'post_type' => 'project']);
    $this->assertSame([], $this->events);
}

public function test_the_edit_screen_shows_a_notice_while_under_review(): void
{
    $this->meta[40] = [CDCF_REVIEW_STAGE_META => 'awaiting_submitter'];
    Functions\when('get_current_screen')->justReturn((object) ['base' => 'post']);
    $GLOBALS['post'] = (object) ['ID' => 40, 'post_type' => 'project'];
    ob_start();
    cdcf_review_edit_screen_notice();
    $html = ob_get_clean();
    unset($GLOBALS['post']);
    $this->assertStringContainsString('Under council review', $html);
    $this->assertStringContainsString('/review/40', $html);
}
```

Use `Functions\when('esc_url')->returnArg()`, `esc_html` → returnArg, `esc_html__`/`__` not used. `cdcf_review_send_submitter_email` stub records into `$this->emails`.

Extend `SubmissionStatusHandlerTest.php`:

```php
public function test_no_link_is_sent_once_the_decided_review_has_expired(): void
{
    $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
    $this->meta[40][CDCF_REVIEW_TOKEN_EXPIRES_META] = time() - 1;
    $res = cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'sam@example.org'], null));
    $this->assertSame(202, $res->get_status());
    $this->assertSame([], $this->emails);
}

public function test_a_published_community_project_links_to_its_languages_projects_page(): void
{
    // stage decided, post_type community_project, status publish, language 'it'
    // stub get_pages → [(object)['ID' => 5]], pll_get_post_translations → ['en' => 5, 'it' => 6],
    // get_post(6) → page object, cdcf_frontend_path_for(page 6) → '/it/progetti'
    $out = cdcf_rest_submission_status_get($this->request(['id' => 40]))->get_data();
    $this->assertSame('http://localhost:3000/it/progetti', $out['public_url']);
}
```

The test file currently stubs `cdcf_frontend_path_for` to return null. In this test, alias it to return `'/it/progetti'` for the page object (ID 6) and null otherwise. Make `cdcf_review_get_reviewable_project` return a `community_project` / `publish` object for id 40.

- [ ] **Step 2: Run them to verify they fail**

Run: `php8.3 vendor/bin/phpunit --filter 'AdminOverride|expired|community_project_links'` → FAIL / undefined functions.

- [ ] **Step 3: Implement**

Append to `includes/review/decision.php`:

```php
/**
 * An administrator publishing a submission still under review in wp-admin
 * decides it as accepted. The decision endpoint's own publish is skipped
 * (cdcf_review_deciding()). No submitter email is sent.
 */
function cdcf_review_admin_override_on_publish($new_status, $old_status, $post): void {
    if ($new_status !== 'publish' || $old_status === 'publish' || cdcf_review_deciding()) {
        return;
    }
    $pid = (int) $post->ID;
    if (!in_array((string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true), ['in_review', 'awaiting_submitter'], true)) {
        return;
    }
    $uid      = (int) get_current_user_id();
    $decision = $post->post_type === 'community_project' ? 'community' : 'cdcf';
    cdcf_review_record_decision($pid, $decision, $uid ?: null, '', '', true);
    cdcf_review_record_event($pid, 'admin_override', $uid ?: null, null, ['decision' => $decision]);
}

/** On the edit screen of a submission under review: decide it in /review. */
function cdcf_review_edit_screen_notice(): void {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $post   = $GLOBALS['post'] ?? null;
    if (!$screen || $screen->base !== 'post' || !is_object($post)) {
        return;
    }
    $stage = (string) get_post_meta((int) $post->ID, CDCF_REVIEW_STAGE_META, true);
    if (!in_array($stage, ['in_review', 'awaiting_submitter'], true)) {
        return;
    }
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    $url      = rtrim($frontend, '/') . '/review/' . (int) $post->ID;
    printf(
        '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
        esc_html('Under council review — the Board decides in the review workspace. Publishing here records an administrator override.'),
        esc_url($url),
        esc_html('Open the review')
    );
}
```

`functions.php`, next to the review hooks:

```php
add_action('transition_post_status', 'cdcf_review_admin_override_on_publish', 15, 3);
add_action('admin_notices', 'cdcf_review_edit_screen_notice');
```

`submission-status.php`:
- In `cdcf_rest_submission_status_resend_link()`, after the reviewable-project check:

```php
    // After the decision the link lives 90 days; past that, a new link
    // would already be dead, so none is sent.
    $expires = (int) get_post_meta($pid, CDCF_REVIEW_TOKEN_EXPIRES_META, true);
    if ($expires > 0 && time() > $expires) {
        return $accepted;
    }
```

- Replace `cdcf_submission_status_public_url()` with:

```php
/** The Projects page path in `$lang` (where community projects are listed). */
function cdcf_review_projects_page_path(string $lang): ?string {
    $pages = get_pages(['meta_key' => '_wp_page_template', 'meta_value' => 'templates/projects.php', 'number' => 1]);
    if (empty($pages)) {
        return null;
    }
    $id = (int) $pages[0]->ID;
    if (function_exists('pll_get_post_translations')) {
        $id = (int) (pll_get_post_translations($id)[$lang] ?? $id);
    }
    $page = get_post($id);
    return $page && function_exists('cdcf_frontend_path_for') ? cdcf_frontend_path_for($page) : null;
}

function cdcf_submission_status_public_url(object $project): ?string {
    if ($project->post_type === 'community_project') {
        // Community projects have no page of their own; they are listed on Projects.
        $path = $project->post_status === 'publish'
            ? cdcf_review_projects_page_path(cdcf_review_post_language((int) $project->ID))
            : null;
    } else {
        $path = function_exists('cdcf_frontend_path_for') ? cdcf_frontend_path_for($project) : null;
    }
    if (!is_string($path) || $path === '') {
        return null;
    }
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    return rtrim($frontend, '/') . $path;
}
```

- [ ] **Step 4: Run the suite**

Run: `php8.3 vendor/bin/phpunit` → OK.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(review): admin override, edit-screen notice, expired-link and community listing" # git add the new test file first
```

---

### Task 4: Next.js data layer and route handler

**Files:**
- Modify: `lib/review-types.ts`, `lib/review-api.ts`, `lib/review-ui.ts`
- Create: `app/api/review/projects/[id]/decision/route.ts`
- Test: `app/api/review/projects/[id]/decision/route.test.ts` (model on `app/api/review/projects/[id]/question/route.test.ts`); extend `lib/review-api.test.ts`

**Interfaces:**
- Consumes (Task 2): the endpoint and the GET fields.
- Produces:

```ts
// lib/review-types.ts
export interface ReviewDecisionRecord {
  decision: ReviewVote
  rationale: string
  summary: string
  decided_at: number
  decided_by: { id: number; name: string } | null
  admin_override: boolean
}
export interface ReviewDecisionEmail {
  language: string
  subject: string
  body: string
  outcomes: Record<ReviewVote, string>
}
export interface ReviewDecisionPayload {
  decision: ReviewVote
  rationale: string
  submitter_summary: string
}
export interface ReviewDecisionResult {
  id: number
  stage: 'decided'
  decision: ReviewVote
  post_type: string
  post_status: string
  warnings: string[]
}
export const DECISION_SUMMARY_MAX = 10000
// ReviewProject gains:
//   decision: ReviewDecisionRecord | null
//   decision_email: ReviewDecisionEmail | null
```

```ts
// lib/review-api.ts
export async function decideProject(
  session: Session | null,
  projectId: number,
  payload: ReviewDecisionPayload
): Promise<ReviewDecisionResult>
```

`lib/review-ui.ts`: the `ReviewErrorKey` union gains `'rationaleRequired' | 'summaryRequired' | 'decisionInProgress'`. `CODE_KEYS` gains `rationale_required: 'rationaleRequired'`, `summary_required: 'summaryRequired'`, `decision_in_progress: 'decisionInProgress'`.

- [ ] **Step 1: Write the failing tests**

Route test cases:
- 401 without a session;
- 400 `invalid_id`;
- 400 for a `decision` outside `REVIEW_VOTES` (`invalid_field`, `decision`);
- 400 for a non-string `rationale` / `submitter_summary`;
- 400 `body_too_long` when the summary exceeds 10,000 or the rationale exceeds 50,000 characters;
- forwards `{decision, rationale, submitter_summary}` to a mocked `decideProject` and returns its result with status 200;
- maps a `ReviewApiError(409, 'decision_in_progress')` through `reviewErrorResponse`.

In `lib/review-api.test.ts`, check that `decideProject` POSTs JSON to `/cdcf/v1/review/projects/40/decision` with the bearer header (copy the `askSubmitter` test).

In `lib/review-ui.test.ts`, add `reviewErrorKey(400, 'rationale_required') === 'rationaleRequired'`, and the same for the other two codes.

- [ ] **Step 2: Run them to verify they fail**

Run: `npx vitest run "app/api/review/projects/[id]/decision" lib/review-api.test.ts lib/review-ui.test.ts` → FAIL.

- [ ] **Step 3: Implement**

`decideProject` mirrors `askSubmitter`: `assertId(projectId)` then `wpRequest<ReviewDecisionResult>(session, \`/cdcf/v1/review/projects/${projectId}/decision\`, { method: 'POST', json: payload })`.

Route handler:

```ts
import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { decideProject } from '@/lib/review-api'
import {
  badRequest,
  parseId,
  readLimitedJsonObject,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'
import { DECISION_SUMMARY_MAX, REVIEW_VOTES, type ReviewVote } from '@/lib/review-types'

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()
  // Room for both texts at their limits, even at 4 bytes a character.
  const read = await readLimitedJsonObject(request, 256 * 1024)
  if (!read.ok) return badRequest(read.error)
  const { decision, rationale, submitter_summary } = read.value
  if (!REVIEW_VOTES.includes(decision as ReviewVote))
    return badRequest('invalid_field', 'decision')
  if (typeof rationale !== 'string') return badRequest('invalid_field', 'rationale')
  if (typeof submitter_summary !== 'string')
    return badRequest('invalid_field', 'submitter_summary')
  if (rationale.length > 50000 || submitter_summary.length > DECISION_SUMMARY_MAX)
    return badRequest('body_too_long')
  try {
    return NextResponse.json(
      await decideProject(session, id, {
        decision: decision as ReviewVote,
        rationale,
        submitter_summary,
      })
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
```

Check `badRequest`'s signature in `lib/review-route.ts` before using it, and match how the question route calls it.

- [ ] **Step 4: Run the tests, `tsc` and lint**

Run: `npx vitest run && npx tsc --noEmit -p . && npm run lint` → all green. Every `ReviewProject` fixture in existing tests needs `decision: null, decision_email: null`; fix them where `tsc` complains.

- [ ] **Step 5: Commit**

```bash
git add lib app/api/review/projects && git commit -m "feat(review): decision API client and route"
```

---

### Task 5: Decision panel, decision card, strings

**Files:**
- Create: `components/review/DecisionPanel.tsx` (client), `components/review/DecisionCard.tsx` (server-compatible, no hooks except `useTranslations`/`useFormatter`), `components/review/DecisionPanel.test.tsx`
- Modify: `app/[lang]/review/[id]/page.tsx`, `app/[lang]/submission-status/[id]/page.tsx`, `messages/{en,it,es,fr,pt,de}.json`

**Interfaces:**
- Consumes (Task 4): `ReviewDecisionEmail`, `ReviewDecisionRecord`, `ReviewDecisionResult`, `REVIEW_VOTES`, the error keys, and `reviewFetch`/`jsonInit` from `lib/review-client.ts`, as `AskSubmitter.tsx` does.
- Produces: `<DecisionPanel projectId plurality email />` and `<DecisionCard decision />`.

**Behaviour:**

`DecisionPanel` (shown when `project.me.can_decide && !locked && project.decision_email`):
1. **Outcome:** a radio group over `REVIEW_VOTES`, labelled with `t('vote.<v>')`, default selection = `plurality ?? null`. Above it, the hint `t('decision.plurality', {vote})` or `t('decision.noPlurality')`.
2. **Rationale:** a textarea labelled `t('decision.rationaleLabel')`. When the selected outcome differs from the plurality (or there is none), the label shows `t('decision.rationaleRequired')` and submit is disabled while the rationale is blank. Otherwise it's labelled optional.
3. **Summary:** a textarea labelled `t('decision.summaryLabel', {language})`, where `language` is `new Intl.DisplayNames([locale], {type: 'language'}).of(email.language)` and `locale` comes from `useLocale()`. Its `maxLength` is `DECISION_SUMMARY_MAX`, and it is required.
4. **Preview:** a `<details open>` labelled `t('decision.preview')`. It contains a `<pre className="whitespace-pre-wrap">` of the subject line plus a blank line plus the body, with these substitutions:
   - `{outcome}` → `email.outcomes[selected]`, or `…` when nothing is selected;
   - `{summary}` → the typed summary, or `t('decision.summaryPlaceholder')`;
   - `{link}` → `t('decision.linkPlaceholder')`.
5. **Submit:** a `t('decision.submit')` button, `cdcf-btn-primary`. The button needs a confirmation step: the first click switches it to `t('decision.confirm')`, and the second click POSTs `/api/review/projects/{id}/decision`. While busy it shows `t('decision.submitting')`.
   - **On error:** show `ComposerStatus` with the error key.
   - **On success:** if `warnings` includes `email_failed`, set a flag that renders `t('decision.emailFailed')`; then `router.refresh()`.

`DecisionCard` (shown when `project.decision`):
- the heading `t('decision.heading')`;
- the outcome label `t('vote.<decision>')`;
- by whom and when: `t('decision.decidedBy', {name, date})`, or `t('decision.adminOverride', {date})` when `admin_override`. The date is `decided_at * 1000`, formatted with `format.dateTime(…, {dateStyle: 'medium'})`;
- the rationale (if any) under `t('decision.rationaleHeading')`, rendered with `MarkdownView`;
- the summary sent to the submitter (if any) under `t('decision.summaryHeading')`, as plain text in a `whitespace-pre-wrap` paragraph.

Workspace page placement (after the tally section, replacing the `locked` paragraph block position):

```tsx
{project.decision && <DecisionCard decision={project.decision} />}
{locked && (
  <p className="rounded-md bg-gray-100 p-3 text-sm text-gray-700">{t('locked')}</p>
)}
{project.me.can_decide && !locked && project.decision_email && (
  <DecisionPanel
    projectId={project.id}
    plurality={project.plurality}
    email={project.decision_email}
  />
)}
```

Status page: where it links `status.public_url` with `t('viewPublic')`, use `t('viewListing')` when `status.decision?.outcome === 'community'`.

**Strings.** Add under `Review.decision` in each language, and the three error keys under `Review.errors`. Add `SubmissionStatus.viewListing`.

en:
```json
"decision": {
  "heading": "Decision",
  "panelHeading": "Record the decision",
  "plurality": "The councils' plurality vote: {vote}.",
  "noPlurality": "The councils have no plurality vote, so a rationale is required.",
  "outcomeLabel": "Outcome",
  "rationaleLabel": "Rationale (for council and Board members only)",
  "rationaleOptional": "optional",
  "rationaleRequired": "required, because the decision differs from the plurality vote",
  "summaryLabel": "Summary for the submitter, in {language}",
  "summaryPlaceholder": "[your summary]",
  "linkPlaceholder": "[link to the status page]",
  "preview": "Preview of the email to the submitter",
  "submit": "Record decision",
  "confirm": "Click again to confirm — this cannot be undone",
  "submitting": "Recording…",
  "emailFailed": "The decision was recorded, but the email to the submitter could not be sent.",
  "decidedBy": "Decided by {name} on {date}",
  "adminOverride": "Published by an administrator in WordPress on {date}",
  "rationaleHeading": "Rationale",
  "summaryHeading": "Summary sent to the submitter"
},
"errors": { "rationaleRequired": "Explain why the decision differs from the plurality vote.", "summaryRequired": "Write a summary for the submitter.", "decisionInProgress": "Another Board member is recording a decision. Reload in a moment." }
```
`SubmissionStatus.viewListing`: "See it among the community projects".

it:
```json
"decision": {
  "heading": "Decisione",
  "panelHeading": "Registra la decisione",
  "plurality": "Voto di maggioranza relativa dei consigli: {vote}.",
  "noPlurality": "I consigli non hanno una maggioranza relativa, quindi la motivazione è obbligatoria.",
  "outcomeLabel": "Esito",
  "rationaleLabel": "Motivazione (solo per i membri dei consigli e del Consiglio direttivo)",
  "rationaleOptional": "facoltativa",
  "rationaleRequired": "obbligatoria, perché la decisione è diversa dal voto di maggioranza",
  "summaryLabel": "Sintesi per chi ha proposto il progetto, in {language}",
  "summaryPlaceholder": "[la tua sintesi]",
  "linkPlaceholder": "[link alla pagina di stato]",
  "preview": "Anteprima dell'email a chi ha proposto il progetto",
  "submit": "Registra la decisione",
  "confirm": "Fai clic di nuovo per confermare: l'operazione non si può annullare",
  "submitting": "Registrazione…",
  "emailFailed": "La decisione è stata registrata, ma non è stato possibile inviare l'email a chi ha proposto il progetto.",
  "decidedBy": "Decisione di {name} del {date}",
  "adminOverride": "Pubblicato da un amministratore in WordPress il {date}",
  "rationaleHeading": "Motivazione",
  "summaryHeading": "Sintesi inviata a chi ha proposto il progetto"
},
"errors": { "rationaleRequired": "Spiega perché la decisione è diversa dal voto di maggioranza.", "summaryRequired": "Scrivi una sintesi per chi ha proposto il progetto.", "decisionInProgress": "Un altro membro del Consiglio direttivo sta registrando una decisione. Ricarica tra un momento." }
```
`viewListing`: "Vedilo tra i progetti della comunità".

es:
```json
"decision": {
  "heading": "Decisión",
  "panelHeading": "Registrar la decisión",
  "plurality": "Voto mayoritario de los consejos: {vote}.",
  "noPlurality": "Los consejos no tienen un voto mayoritario, así que la justificación es obligatoria.",
  "outcomeLabel": "Resultado",
  "rationaleLabel": "Justificación (solo para miembros de los consejos y de la Junta)",
  "rationaleOptional": "opcional",
  "rationaleRequired": "obligatoria, porque la decisión difiere del voto mayoritario",
  "summaryLabel": "Resumen para quien presentó el proyecto, en {language}",
  "summaryPlaceholder": "[tu resumen]",
  "linkPlaceholder": "[enlace a la página de estado]",
  "preview": "Vista previa del correo para quien presentó el proyecto",
  "submit": "Registrar la decisión",
  "confirm": "Haz clic de nuevo para confirmar: no se puede deshacer",
  "submitting": "Registrando…",
  "emailFailed": "La decisión se registró, pero no se pudo enviar el correo a quien presentó el proyecto.",
  "decidedBy": "Decidido por {name} el {date}",
  "adminOverride": "Publicado por un administrador en WordPress el {date}",
  "rationaleHeading": "Justificación",
  "summaryHeading": "Resumen enviado a quien presentó el proyecto"
},
"errors": { "rationaleRequired": "Explica por qué la decisión difiere del voto mayoritario.", "summaryRequired": "Escribe un resumen para quien presentó el proyecto.", "decisionInProgress": "Otro miembro de la Junta está registrando una decisión. Recarga en un momento." }
```
`viewListing`: "Verlo entre los proyectos comunitarios".

fr:
```json
"decision": {
  "heading": "Décision",
  "panelHeading": "Enregistrer la décision",
  "plurality": "Vote majoritaire des conseils : {vote}.",
  "noPlurality": "Les conseils n'ont pas de vote majoritaire ; une justification est donc obligatoire.",
  "outcomeLabel": "Issue",
  "rationaleLabel": "Justification (réservée aux membres des conseils et du Conseil d'administration)",
  "rationaleOptional": "facultative",
  "rationaleRequired": "obligatoire, car la décision diffère du vote majoritaire",
  "summaryLabel": "Résumé pour l'auteur de la proposition, en {language}",
  "summaryPlaceholder": "[votre résumé]",
  "linkPlaceholder": "[lien vers la page de suivi]",
  "preview": "Aperçu de l'e-mail à l'auteur de la proposition",
  "submit": "Enregistrer la décision",
  "confirm": "Cliquez à nouveau pour confirmer : cette action est irréversible",
  "submitting": "Enregistrement…",
  "emailFailed": "La décision a été enregistrée, mais l'e-mail à l'auteur de la proposition n'a pas pu être envoyé.",
  "decidedBy": "Décidé par {name} le {date}",
  "adminOverride": "Publié par un administrateur dans WordPress le {date}",
  "rationaleHeading": "Justification",
  "summaryHeading": "Résumé envoyé à l'auteur de la proposition"
},
"errors": { "rationaleRequired": "Expliquez pourquoi la décision diffère du vote majoritaire.", "summaryRequired": "Rédigez un résumé pour l'auteur de la proposition.", "decisionInProgress": "Un autre membre du Conseil d'administration enregistre une décision. Rechargez dans un instant." }
```
`viewListing`: "Le voir parmi les projets communautaires".

pt:
```json
"decision": {
  "heading": "Decisão",
  "panelHeading": "Registar a decisão",
  "plurality": "Voto maioritário dos conselhos: {vote}.",
  "noPlurality": "Os conselhos não têm um voto maioritário, por isso a justificação é obrigatória.",
  "outcomeLabel": "Resultado",
  "rationaleLabel": "Justificação (apenas para membros dos conselhos e da Direção)",
  "rationaleOptional": "opcional",
  "rationaleRequired": "obrigatória, porque a decisão difere do voto maioritário",
  "summaryLabel": "Resumo para quem submeteu o projeto, em {language}",
  "summaryPlaceholder": "[o seu resumo]",
  "linkPlaceholder": "[link para a página de estado]",
  "preview": "Pré-visualização do email para quem submeteu o projeto",
  "submit": "Registar a decisão",
  "confirm": "Clique novamente para confirmar: não pode ser desfeito",
  "submitting": "A registar…",
  "emailFailed": "A decisão foi registada, mas não foi possível enviar o email a quem submeteu o projeto.",
  "decidedBy": "Decidido por {name} em {date}",
  "adminOverride": "Publicado por um administrador no WordPress em {date}",
  "rationaleHeading": "Justificação",
  "summaryHeading": "Resumo enviado a quem submeteu o projeto"
},
"errors": { "rationaleRequired": "Explique porque é que a decisão difere do voto maioritário.", "summaryRequired": "Escreva um resumo para quem submeteu o projeto.", "decisionInProgress": "Outro membro da Direção está a registar uma decisão. Recarregue daqui a pouco." }
```
`viewListing`: "Vê-lo entre os projetos da comunidade".

de:
```json
"decision": {
  "heading": "Entscheidung",
  "panelHeading": "Entscheidung festhalten",
  "plurality": "Mehrheitsvotum der Räte: {vote}.",
  "noPlurality": "Die Räte haben kein Mehrheitsvotum, daher ist eine Begründung erforderlich.",
  "outcomeLabel": "Ergebnis",
  "rationaleLabel": "Begründung (nur für Mitglieder der Räte und des Vorstands)",
  "rationaleOptional": "optional",
  "rationaleRequired": "erforderlich, weil die Entscheidung vom Mehrheitsvotum abweicht",
  "summaryLabel": "Zusammenfassung für die einreichende Person, auf {language}",
  "summaryPlaceholder": "[Ihre Zusammenfassung]",
  "linkPlaceholder": "[Link zur Statusseite]",
  "preview": "Vorschau der E-Mail an die einreichende Person",
  "submit": "Entscheidung festhalten",
  "confirm": "Zum Bestätigen erneut klicken – dies kann nicht rückgängig gemacht werden",
  "submitting": "Wird festgehalten…",
  "emailFailed": "Die Entscheidung wurde festgehalten, aber die E-Mail an die einreichende Person konnte nicht gesendet werden.",
  "decidedBy": "Entschieden von {name} am {date}",
  "adminOverride": "Von einer Administratorin oder einem Administrator in WordPress veröffentlicht am {date}",
  "rationaleHeading": "Begründung",
  "summaryHeading": "An die einreichende Person gesendete Zusammenfassung"
},
"errors": { "rationaleRequired": "Erläutern Sie, warum die Entscheidung vom Mehrheitsvotum abweicht.", "summaryRequired": "Schreiben Sie eine Zusammenfassung für die einreichende Person.", "decisionInProgress": "Ein anderes Vorstandsmitglied hält gerade eine Entscheidung fest. Laden Sie die Seite gleich neu." }
```
`viewListing`: "Unter den Community-Projekten ansehen".

Merge the `errors` keys into the existing `Review.errors` objects; do not replace them.

- [ ] **Step 1: Write the failing component test** (`DecisionPanel.test.tsx`, happy-dom, modelled on `AskSubmitter.test.tsx` including its `next-intl` and router mocks). Tests:
  - With plurality `cdcf`, `cdcf` is preselected and submit is enabled once a summary is typed.
  - Choosing `reject` disables submit until a rationale is typed.
  - The preview shows the typed summary and the selected outcome label in place of `{summary}` and `{outcome}`.
  - The first click does not call fetch, and the second click POSTs `{decision, rationale, submitter_summary}` to `/api/review/projects/40/decision`.
  - An `email_failed` warning shows the warning text.
  - The language label uses `Intl.DisplayNames` (e.g. `it` → "Italian" under locale `en`).
- [ ] **Step 2: Run it to verify it fails** — `npx vitest run components/review/DecisionPanel.test.tsx` → module not found.
- [ ] **Step 3: Implement the components, page changes and strings** as specified above.
- [ ] **Step 4: Run the full checks** — `npx vitest run && npx tsc --noEmit -p . && npm run lint && npm run build`. All must be green. Also verify each `messages/*.json` parses, and that every key used exists in all six (`node -e` script comparing `Review.decision` key sets).
- [ ] **Step 5: Commit**

```bash
git add components/review app messages && git commit -m "feat(review): Board decision panel and decision card"
```

---

### Task 6: Documentation

**Files:**
- Modify: `AGENTS.md` (`CLAUDE.md` is a symlink to it)

- [ ] **Step 1:** Add a row to the REST table, after `/review/projects/{id}/question`:
  `| \`POST\` | \`/review/projects/{id}/decision\` | Board members only. \`decision\` (\`cdcf\` · \`community\` · \`reject\`), \`submitter_summary\` (required, ≤ 10,000 chars), \`rationale\` (required when the decision differs from the councils' plurality vote, or there is none). Publishes, converts to a \`community_project\`, or moves to draft; then stage \`decided\`, the submitter's link expires 90 days later, and the submitter is emailed. \`409 decision_in_progress\` while another decision is being recorded. |`
- [ ] **Step 2:** In "Council review (`/review/*`)", add a paragraph after the submitter paragraph:
  > A Board member decides in the workspace. `includes/review/decision.php` applies the outcome first and records the decision only if that succeeds. A community-project conversion keeps the post id, maps the fields to the `community_project` field group, and rolls back if publishing fails. An administrator who publishes a submission still under review in wp-admin records an `admin_override` decision (no submitter email). The edit screen shows a notice pointing to the workspace.
- [ ] **Step 3:** Run `npm run format:md` and `npm run lint:md`. If `format:md` reformats unrelated tables, revert those hunks and keep only your lines.
- [ ] **Step 4:** Commit: `git commit -am "docs(review): decision endpoint and admin override"`.

---

## Self-review notes

- **Spec coverage:**
  - decision endpoint validation (decide, not decided, summary, rationale rule) — Task 2;
  - outcomes, conversion and rollback — Task 1;
  - decision meta, stage, token expiry — Task 1;
  - `decision_recorded` event — Task 2;
  - submitter decision email — Task 2;
  - warnings — Task 2;
  - admin override and notice — Task 3;
  - Decision panel with plurality, selector, rationale rule, language-labelled summary and email preview — Task 5;
  - status page link — Task 3 + Task 5.
- **Deferred to Phase 5, by the spec's delivery plan:** member emails for `decision_recorded` and `admin_override`. The events are recorded now, and the `cdcf_review_event_recorded` action is already fired for Phase 5 to hook.
