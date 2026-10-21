# Council Review of Community Project Referrals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route community project referrals through the existing council
review (members notified, Accept/Reject votes, Board decision, referrer
treated like a submitter with "the project you referred" wording).

**Architecture:** No new tables or endpoints. A referral is a
`community_project` carrying `_referral_submitter_email`; one helper
identifies it and every branch point (vote/decision rules, email template
choice, REST `kind` field, UI) asks that helper. An Accept vote is stored as
the existing `community` value, so tallies and plurality are unchanged.

**Tech Stack:** WordPress theme PHP (PHPUnit + Brain Monkey + `FakeWpdb`),
Next.js 15 App Router + next-intl (Vitest + Testing Library).

**Spec:** `docs/superpowers/specs/2026-10-21-community-referral-review-design.md`

## Global Constraints

- Branch: `feat/community-referral-review` (already created off `main`, spec
  committed).
- PHP: new include files start with `defined('ABSPATH') || exit;` (single
  line); register each in both `functions.php` (after line 811, with the other
  `includes/review/` requires) and `tests/bootstrap.php` (after line 260).
- Sanitization stays in REST `args` blocks; handlers never re-sanitize.
- Async email only through the existing review mailer
  (`cdcf_review_send_submitter_email` / `cdcf_review_send_member_email`), never
  WP-Cron.
- Six languages everywhere: `en`, `it`, `es`, `fr`, `pt`, `de` — PHP email
  strings and `messages/*.json`.
- Referrer-facing copy never says "your project"/"your submission"; it says
  "the project you referred".
- Outcome labels stay as they are ("Accepted as a community project" /
  "Not accepted").
- JS/TS formatted with Prettier (`npm run format:check`), Markdown with
  `npm run format:md` and `npm run lint:md`.
- WordPress changes ship only with `gh workflow run deploy.yml -f
environment=production` (note it in the PR; do not deploy).

## Review Focus

1. A **converted submission** (a `project` turned `community_project` by a
   Board decision) must never be treated as a referral — its submitter keeps
   "your project" wording and three-way history. Test in Task 1.
2. A **new referral arriving while the v4 backfill runs** already has
   `submission_received`; the backfill must not announce it again (members
   would be emailed twice). Test in Task 5.
3. A **legacy referral with a missing or invalid referrer email** enters
   review and is announced to members; the confirmation is skipped
   (`no_email`), nothing throws. Test in Task 5.
4. A member **clearing the body of an existing referral review** (vote only)
   saves, records `review_edited`, and keeps the vote. Test in Task 2.
5. A **tie between Accept and Reject** on a referral gives no plurality, so
   the Board must give a rationale for either outcome. Test in Task 2.

---

### Task 1: Referral identity and submitter accessors

**Files:**

- Create: `wordpress/themes/cdcf-headless/includes/review/referral.php`
- Modify: `wordpress/themes/cdcf-headless/functions.php` (require),
  `wordpress/themes/cdcf-headless/tests/bootstrap.php` (require)
- Modify: `includes/review/mailer.php:68,86`,
  `includes/handlers/submission-status.php:207`,
  `includes/handlers/review.php:261-262` (read through the accessors)
- Test: `wordpress/themes/cdcf-headless/tests/ReviewReferralTest.php`

**Interfaces:**

- Produces:
  - `cdcf_review_is_referral(int $project_id): bool` — true when
    `get_post_type() === 'community_project'` and
    `_referral_submitter_email` meta is non-empty.
  - `cdcf_review_kind(int $project_id): string` — `'referral'` or
    `'submission'`.
  - `cdcf_review_submitter_email(int $project_id): string` —
    `_submission_submitter_email`, falling back to `_referral_submitter_email`.
  - `cdcf_review_submitter_name(int $project_id): string` — same fallback
    with `_submission_submitter_name` / `_referral_submitter_name`.

- [ ] **Step 1: Write the failing tests** in `ReviewReferralTest`:
  - `test_community_project_with_referrer_email_is_a_referral` — post type
    `community_project`, `_referral_submitter_email` = `'r@example.org'` →
    `true`, kind `'referral'`.
  - `test_converted_submission_is_not_a_referral` — post type
    `community_project`, `_referral_submitter_email` = `''`,
    `_submission_submitter_email` = `'s@example.org'` → `false`, kind
    `'submission'`.
  - `test_project_is_not_a_referral` — post type `project` → `false`.
  - `test_submitter_email_prefers_submission_key_then_referral_key` — both
    set → submission value; only referral set → referral value; neither →
    `''`.
  - `test_submitter_name_falls_back_the_same_way`.
- [ ] **Step 2: Run** `composer test --working-dir=wordpress/themes/cdcf-headless -- --filter ReviewReferralTest`
      — expected: FAIL, functions undefined.
- [ ] **Step 3: Implement** the four functions in `referral.php`; register
      the file in `functions.php` and `tests/bootstrap.php`. Replace the direct
      `get_post_meta(..., '_submission_submitter_email'|'_submission_submitter_name', true)`
      reads in `mailer.php` (both functions), the resend-link handler, and the
      project detail response with the accessors.
- [ ] **Step 4: Run** the whole theme suite
      (`composer test --working-dir=wordpress/themes/cdcf-headless`) — expected:
      PASS. Existing mailer/status/read-handler tests that stub `get_post_meta`
      keep passing because the submission key is read first; fix any test that
      asserted the exact meta-key call order.
- [ ] **Step 5: Commit** — `feat(review): identify referrals and read the referrer's address`

### Task 2: Referral votes, optional review body, decision, and `kind` in responses

**Files:**

- Modify: `wordpress/themes/cdcf-headless/includes/handlers/review.php`
  (`cdcf_review_validate_body`, `cdcf_review_guard_write`,
  `cdcf_rest_review_put_my_review`, `cdcf_rest_review_post_decision`,
  `cdcf_rest_review_list_projects`, `cdcf_rest_review_get_project`,
  `cdcf_review_submission_fields`)
- Modify: `wordpress/themes/cdcf-headless/includes/review/decision.php`
  (`cdcf_review_apply_outcome`)
- Test: `tests/ReviewWriteHandlerTest.php`, `tests/ReviewDecisionHandlerTest.php`,
  `tests/ReviewDecisionTest.php`, `tests/ReviewReadHandlerTest.php`

**Interfaces:**

- Consumes: `cdcf_review_is_referral`, `cdcf_review_kind` (Task 1).
- Produces:
  - `const CDCF_REVIEW_REFERRAL_VOTES = ['community', 'reject'];` in
    `handlers/review.php`.
  - `cdcf_review_validate_body(string $body, bool $allow_empty = false)` and
    `cdcf_review_guard_write(int $project_id, string $body, bool $allow_empty = false)`
    — the empty check is skipped when `$allow_empty`; the length limit never
    is.
  - List items and the project detail gain `'kind' => cdcf_review_kind($pid)`.

- [ ] **Step 1: Write the failing tests**:
  - Write handler: `test_referral_rejects_a_cdcf_vote` →
    `WP_Error` `invalid_vote`, status 400, message
    `'Vote must be community or reject.'`;
    `test_referral_review_may_have_an_empty_body` → 201 with
    `body_md` `''`; `test_submission_review_still_requires_a_body` →
    `empty_body`; `test_clearing_a_referral_review_body_keeps_the_vote`
    (existing review with body and vote `community`, PUT body `''` vote
    `community` + version → 200, one `review_edited` event, no
    `vote_changed`).
  - Decision handler: `test_referral_decision_cannot_be_cdcf` →
    `invalid_decision`, 400, message `'A referral can be accepted or rejected.'`;
    `test_referral_tie_requires_a_rationale` (tally `community` 1, `reject` 1,
    decision `community`, empty rationale → `rationale_required`).
  - Decision module: `test_accepting_a_referral_publishes_it_in_place` —
    post type `community_project`, decision `community` →
    `wp_update_post(['ID' => id, 'post_status' => 'publish'], true)` called,
    `set_post_type` never called, `_cdcf_converted_from_submission` never
    written.
  - Read handler: list items and detail carry `kind`; a referral's
    `fields.repo_urls` falls back to `project_github_url`.
- [ ] **Step 2: Run** the four test classes with `--filter` — expected: FAIL.
- [ ] **Step 3: Implement**:
  - `put_my_review`: compute `$referral = cdcf_review_is_referral($pid)`;
    pass it as `$allow_empty` to `guard_write`; validate the vote against
    `CDCF_REVIEW_REFERRAL_VOTES` when `$referral`, else `CDCF_REVIEW_VOTES`.
  - `post_decision`: reject `cdcf` on a referral before the plurality check.
  - `apply_outcome`: for `community`, when the post type is already
    `community_project`, publish in place instead of converting.
  - `cdcf_review_submission_fields`: after `_submission_repo_urls` and
    `project_repo_url`, fall back to `get_field('project_github_url', $id)`.
  - Add `kind` to both responses.
- [ ] **Step 4: Run** the theme suite — expected: PASS.
- [ ] **Step 5: Commit** — `feat(review): accept/reject votes and decisions on referrals`

### Task 3: Referrer and member email templates

**Files:**

- Modify: `includes/review/email-strings.php`,
  `includes/review/mailer.php` (`cdcf_review_deliver_submitter_email`),
  `includes/handlers/review.php` (`cdcf_review_decision_email_preview`),
  `includes/review/member-email-strings.php`,
  `includes/review/member-emails.php` (`cdcf_review_deliver_member_email`)
- Test: `tests/ReviewEmailStringsTest.php`, `tests/ReviewMailerTest.php`,
  `tests/ReviewMemberEmailStringsTest.php`, `tests/ReviewMemberEmailsTest.php`

**Interfaces:**

- Consumes: `cdcf_review_is_referral` (Task 1).
- Produces: `cdcf_review_submitter_template(int $project_id, string $template): string`
  in `mailer.php` — returns `'referral_' . $template` for a referral, else
  `$template`. Submitter templates `referral_confirmation`,
  `referral_question`, `referral_link`, `referral_decision`; member template
  `referral_received`.

- [ ] **Step 1: Write the failing tests**:
  - `ReviewEmailStringsTest`: extend
    `test_every_language_has_every_template_with_its_placeholders` to the four
    `referral_*` templates (same placeholders as their counterparts);
    `test_referral_templates_never_say_your_project` — in English, no
    `referral_*` subject or body contains `your project` or `your submission`
    (case-insensitive).
  - `ReviewMailerTest`: `test_referral_email_uses_the_referral_template`
    (subject equals the rendered `referral_confirmation` subject).
  - `ReviewMemberEmailStringsTest`: every language has `referral_received`
    with `{title}` and `{link}`.
  - `ReviewMemberEmailsTest`:
    `test_submission_received_on_a_referral_uses_the_referral_wording`;
    recipients unchanged (still everyone but the actor).
- [ ] **Step 2: Run** the four classes — expected: FAIL.
- [ ] **Step 3: Implement.** English copy, exact (translate the other five
      in the tone of their existing templates):
  - `referral_confirmation` — subject `We received your referral: {title}`;
    body `Thank you for referring "{title}" to the Catholic Digital Commons Foundation.\n\nOur review committee will look at the project you referred and may write to you with questions. You can follow the review, and answer any questions, on this page:\n\n{link}\n\nKeep this link private: anyone who has it can see the status of your referral. Each email we send contains a new link, and only the most recent one works.`
  - `referral_question` — subject `A question about the project you referred: {title}`;
    body as `question`, with `Please answer on your referral's status page:`.
  - `referral_link` — subject `Your link to follow the project you referred: {title}`;
    body as `link`.
  - `referral_decision` — subject `Decision on the project you referred: {title}`;
    body as `decision`, with `on your referral's status page`.
  - Member `referral_received` — subject
    `New community project referral to review: {title}`; body
    `A community project, "{title}", has been referred for council review.\n\nOpen it in the review workspace:\n\n{link}`.
  - `deliver_submitter_email` and `decision_email_preview` render
    `cdcf_review_submitter_template($pid, $template)`.
  - `deliver_member_email` renders `referral_received` when the event type is
    `submission_received` and the project is a referral.
- [ ] **Step 4: Run** the theme suite — expected: PASS.
- [ ] **Step 5: Commit** — `feat(review): referral wording for referrer and member emails`

### Task 4: New referrals enter review; admin email and dashboard widget point to it

**Files:**

- Modify: `includes/handlers/refer-community-project.php`,
  `wordpress/themes/cdcf-headless/functions.php`
  (`cdcf_render_pending_community_projects_widget` and its
  `wp_add_dashboard_widget` title, ~lines 3131-3175)
- Test: `tests/ReferCommunityProjectHandlerTest.php`

**Interfaces:**

- Consumes: `cdcf_review_record_event`, `cdcf_review_send_submitter_email`,
  `cdcf_review_workspace_url(int $project_id, string $lang, ?int $entry_id = null)`
  (existing), `CDCF_REVIEW_STAGE_META`.

- [ ] **Step 1: Write the failing tests** in
      `ReferCommunityProjectHandlerTest`:
  - `test_referral_enters_council_review` — after a valid referral:
    `_cdcf_review_stage` = `in_review`; `cdcf_review_record_event` called once
    with `(post_id, 'submission_received', null)`;
    `cdcf_review_send_submitter_email` called with `(post_id, 'confirmation')`,
    in that order (event, then confirmation, then admin email).
  - `test_admin_email_points_to_the_review_workspace` — body contains
    `under council review` and the workspace URL
    (`cdcf_review_workspace_url(post_id, 'en')`), and does not contain
    `post.php?post=`.
  - `test_spam_referral_never_enters_review` — the silent-success spam path
    records no event and sends no confirmation.
- [ ] **Step 2: Run** with `--filter ReferCommunityProjectHandlerTest` — expected: FAIL.
- [ ] **Step 3: Implement** in the handler, after the submitter meta is
      stored: set the stage, record the event, send the confirmation, then the
      admin email. Admin email body: replace `"A new community project referral has been submitted for review.\n\n"` with
      `"A new community project referral has been submitted and is now under council review.\n\n"`
      and the closing `"Review and approve it here:\n%s"` with
      `"Follow the review here:\n%s"` filled by
      `cdcf_review_workspace_url($post_id, 'en')`. Widget: title
      `Community Project Referrals Under Council Review (%d)`; first line inside the
      widget `<p>These referrals are under council review. Publishing one here closes its review.</p>`;
      button label `Open review`, `href` = `esc_url(cdcf_review_workspace_url($post->ID, 'en'))`,
      `target="_blank" rel="noopener"`. No unit test for the widget (it lives in
      `functions.php`, which the suite does not load); verify with `php -l`.
      The wp-admin edit-screen notice (`cdcf_review_edit_screen_notice`) keys on
      the review stage, so it already appears on referrals: no change.
- [ ] **Step 4: Run** the theme suite and `php -l wordpress/themes/cdcf-headless/functions.php` — expected: PASS / `No syntax errors`.
- [ ] **Step 5: Commit** — `feat(review): send new community referrals to council review`

### Task 5: Backfill pending referrals (schema version 4)

**Files:**

- Modify: `includes/review/schema.php` (`CDCF_REVIEW_DB_VERSION`,
  `cdcf_review_maybe_upgrade`, new backfill)
- Test: `tests/ReviewSchemaTest.php`

**Interfaces:**

- Consumes: Task 1 accessors, Task 3 template choice (through
  `cdcf_review_send_submitter_email`).
- Produces: `cdcf_review_backfill_referrals(): int` — number announced.

- [ ] **Step 1: Write the failing tests** in `ReviewSchemaTest`:
  - `test_db_version_is_bumped_for_referrals` — `CDCF_REVIEW_DB_VERSION === '4'`
    (replace the existing `..._for_the_inbox_index` assertion).
  - `test_referral_backfill_puts_pending_referrals_in_review_and_announces_them`
    — `get_posts` query: `post_type` `community_project`, `post_status`
    `pending`, `lang` `''`, meta `_referral_submitter_email` `EXISTS`; each
    without a stage gets `in_review`; each gets one `submission_received` event
    with `['migrated' => true]` and one `confirmation` email.
  - `test_referral_backfill_skips_referrals_already_announced` — a referral
    with an existing `submission_received` event gets no second event and no
    email (Review Focus 2).
  - `test_referral_backfill_without_a_valid_email_still_announces` —
    `cdcf_review_send_submitter_email` returns `'no_email'`; the event is
    still recorded, the count includes it, nothing throws (Review Focus 3).
  - `test_referral_backfill_leaves_the_work_to_the_request_holding_the_lock`
    — `GET_LOCK` returns `'0'` → no stage writes, no events, returns 0.
  - `test_maybe_upgrade_runs_the_referral_backfill_before_storing_the_version`.
- [ ] **Step 2: Run** with `--filter ReviewSchemaTest` — expected: FAIL.
- [ ] **Step 3: Implement** `cdcf_review_backfill_referrals()` following
      `cdcf_review_backfill_submission_received()`: query, return 0 with no lock
      when empty, take named lock `cdcf_review_referral_backfill`, set missing
      stages, read announced project ids from the events table, announce the
      rest, release the lock in `finally`. Call it in `cdcf_review_maybe_upgrade()`
      after the v3 backfill; bump the version to `'4'`. Update the docblock above
      `maybe_upgrade` to mention version 4.
- [ ] **Step 4: Run** the theme suite — expected: PASS.
- [ ] **Step 5: Commit** — `feat(review): bring pending community referrals into review`

### Task 6: `kind` on inbox items and the submission-status endpoint

**Files:**

- Modify: `includes/review/inbox.php` (`cdcf_review_present_notification`),
  `includes/handlers/submission-status.php` (`cdcf_rest_submission_status_get`)
- Test: `tests/ReviewInboxTest.php`, `tests/SubmissionStatusHandlerTest.php`

**Interfaces:**

- Consumes: `cdcf_review_kind` (Task 1).
- Produces: inbox items gain `'kind' => 'referral'|'submission'`; the status
  response gains `'kind'`.

- [ ] **Step 1: Write the failing tests** — `test_presents_a_notification`
      expects `kind`; `test_status_reports_the_kind` for a referral and a
      submission.
- [ ] **Step 2: Run** both classes — expected: FAIL.
- [ ] **Step 3: Implement** both fields.
- [ ] **Step 4: Run** the theme suite — expected: PASS.
- [ ] **Step 5: Commit** — `feat(review): expose referral kind to the inbox and status page`

### Task 7: Review workspace, list and inbox UI for referrals

**Files:**

- Modify: `lib/review-types.ts`, `lib/review-ui.ts`,
  `components/review/{TallyBar,ProjectCard,MyReviewComposer,DecisionPanel,SubmissionCard,InboxItemSummary}.tsx`,
  and the pages under `app/[lang]/review` that pass project data to them
- Modify: `messages/{en,it,es,fr,pt,de}.json` (`Review` namespace)
- Test: `lib/review-ui.test.ts`,
  `components/review/DecisionPanel.test.tsx`,
  `components/review/MyReviewComposer.test.tsx` (create)

**Interfaces:**

- Consumes: `kind` on list items, project detail and inbox items (Tasks 2, 6).
- Produces:
  - `export type ReviewKind = 'submission' | 'referral'` in
    `lib/review-types.ts`; `kind: ReviewKind` on `ReviewProjectSummary`,
    `ReviewProject` and the inbox item type.
  - `export function votesFor(kind: ReviewKind): readonly ReviewVote[]` in
    `lib/review-ui.ts` — `['community', 'reject']` for referrals, else
    `REVIEW_VOTES`.
  - `export function voteLabelKey(kind: ReviewKind, vote: ReviewVote): string`
    — `referralVote.${vote}` for referrals, else `vote.${vote}`.
  - `tallySegments(tally, pending, kind: ReviewKind = 'submission')` — omits
    the `cdcf` segment for referrals.
  - Components that show votes take a `kind: ReviewKind` prop.
- New `Review` messages (English; translate the other five):
  `referralVote.community` `Accept`, `referralVote.reject` `Reject`,
  `referralBadge` `Community referral`, `submission.referrer` `Referred by`,
  `inbox.event.referral_received` `New community referral: {title}`.

- [ ] **Step 1: Write the failing tests**:
  - `votesFor('referral')` → `['community', 'reject']`;
    `votesFor('submission')` → `REVIEW_VOTES`.
  - `tallySegments(tally, 2, 'referral')` keys → `['community', 'reject', 'pending']`.
  - `DecisionPanel` with `kind="referral"` renders exactly two outcome radios
    labelled `Accept` and `Reject`.
  - `MyReviewComposer` with `kind="referral"`: save enabled with an empty body
    once a vote is chosen; with `kind="submission"`: save disabled while the
    body is empty.
- [ ] **Step 2: Run** `npx vitest run lib/review-ui.test.ts components/review` — expected: FAIL.
- [ ] **Step 3: Implement** the helpers, props and messages; `ProjectCard`
      shows the badge, `SubmissionCard` swaps the submitter label,
      `InboxItemSummary` uses `event.referral_received` for
      `submission_received` on a referral.
- [ ] **Step 4: Run** `npx vitest run`, `npm run lint`, `npm run format:check`,
      `npx tsc --noEmit` — expected: all pass.
- [ ] **Step 5: Commit** — `feat(review): accept/reject UI and badges for community referrals`

### Task 8: Status page wording for referrers, docs

**Files:**

- Modify: `lib/submission-status-types.ts` (`kind`),
  `app/[lang]/submission-status/[id]/page.tsx` and the
  `components/submission-status/*` it renders
- Modify: `messages/{en,it,es,fr,pt,de}.json` (`SubmissionStatus` namespace)
- Modify: `AGENTS.md` (Council review section, `/submission-status/{id}` row,
  `/review/projects` rows), spec status line → `implemented`
- Test: `lib/submission-status-ui.test.ts`

**Interfaces:**

- Consumes: `kind` on the status response (Task 6).
- New `SubmissionStatus` messages (English): `referralTitle`
  `Referral status`, `referredOn` `Referred on {date}`. Change `resendIntro`
  in all six languages to neutral wording, since the resend form shows when no
  status (and so no kind) is available: `Enter the email address you used when
you submitted or referred the project, and we will send a new link to it.`;
  `resendDone`: `If that address matches, a new link is on its way.`

- [ ] **Step 1: Write the failing test** — a pure helper
      `statusHeadingKeys(kind)` in `lib/submission-status-ui.ts` returns
      `{ title: 'referralTitle', submittedOn: 'referredOn' }` for referrals and
      `{ title: 'title', submittedOn: 'submittedOn' }` otherwise.
- [ ] **Step 2: Run** `npx vitest run lib/submission-status-ui.test.ts` — expected: FAIL.
- [ ] **Step 3: Implement** the helper, use it on the page, update messages
      and docs (AGENTS.md: referrals enter council review; Accept/Reject;
      referrer emails; v4 backfill; admin email/widget point to the workspace).
- [ ] **Step 4: Run** `npx vitest run`, `npm run lint`,
      `npm run format:check`, `npm run format:md`, `npm run lint:md`, and the
      theme suite — expected: all pass.
- [ ] **Step 5: Commit** — `feat(review): referral wording on the status page; document referral review`
