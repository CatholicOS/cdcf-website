# Council review of community project referrals

**Status:** design, awaiting review.

## Problem

A community project referral (`POST /cdcf/v1/refer-community-project`)
creates a `pending` `community_project` post and emails `admin_email`. The
Technical and Ecclesial Advisory Councils and the Board never hear about it,
and the person who referred it gets no confirmation and no decision.

Council review of CDCF project submissions
([2026-10-15-project-review-design.md](2026-10-15-project-review-design.md))
deliberately left referrals out of scope. Even though a community project does
not need the full review a CDCF project gets, the councils and the Board
should know about every referral and accept or reject it.

## Intent

Referrals go through the same council review that submissions use, with a
simpler vote:

- Every council and Board member is told about a new referral, in the bell and
  by email.
- Technical and Ecclesial members vote **accept** or **reject**. A written
  review is optional.
- A Board member records the final decision, as for submissions.
- The person who referred the project is treated exactly like a submitter:
  confirmation email, tokenized status page, committee questions, decision
  email. Their wording says "the project you referred", not "your project".

## Goals

- Referrals appear in `/[lang]/review` next to submissions, marked as
  community referrals, and open in the same workspace.
- `submission_received` is recorded for a new referral, so members get the
  bell notification and the existing new-project email (with referral
  wording, below).
- Council votes on a referral are `accept` / `reject`; the tally, pending
  voters count and plurality work as they do for submissions.
- The Board's decision on a referral is accept (publish the
  `community_project`) or reject (move it to draft), with the existing rules:
  a summary for the referrer is required, and a rationale is required when
  the decision differs from the councils' plurality or there is none.
- The referrer gets the confirmation, question, link and decision emails and
  the status page, in the referral's language.
- Pending referrals that predate this change enter review once, are announced
  to members, and their referrers get a confirmation email.

## Non-goals

- Changing the submission review flow. Submissions keep three-way votes
  (CDCF project, community project, reject) and a required written review.
- Converting a referral into a CDCF project. A referral can only become a
  published community project or be rejected.
- Local-group referrals (`refer-local-group`). They keep their admin-review
  flow; their outcome model differs (no "community" outcome). _(Claude's
  call.)_
- Machine translation of the Board's summary, live updates, reopening a
  decided review: as in the submission design.

## Anti-goals

- A referrer must never see wording that implies they own or wrote the
  project ("your project"), in email or on the status page.
- Nothing about a referral's deliberation may become visible publicly, as for
  submissions (custom tables, members-only endpoints).
- Members must not be emailed twice for the same referral (for example once by
  the backfill and again on a later request).

## Decisions

1. **Same workspace, same endpoints.** Referrals use the existing `/review/*`
   endpoints, tables, events, inbox, and member email listener. No parallel
   system.
2. **A referral is identified by its post.** While under review, a
   `community_project` with a review stage is always a referral: a submission
   only becomes a `community_project` when it is decided. After the decision,
   a converted submission carries `_cdcf_converted_from_submission`. The
   builder adds one helper, e.g. `cdcf_review_is_referral(int $id): bool`,
   and every place that branches on it uses the helper. (Storing an explicit
   kind meta at creation is equally acceptable — builder's choice — as long
   as the backfill sets it too.)
3. **Votes.** A referral's vote is `community` (shown as **Accept**) or
   `reject`. Reusing the `community` value keeps `cdcf_review_tallies`,
   `cdcf_review_plurality`, `vote_changed` events and the stored schema
   unchanged. `PUT /review/projects/{id}/my-review` rejects `cdcf` on a
   referral with `400 invalid_vote`. _(Builder's call recorded in design:
   the alternative of a new `accept` value was rejected because it would
   touch the tally schema, plurality and every TallyBar for no gain.)_
4. **Written review optional on referrals.** On a referral, `body_md` may be
   empty in `PUT /my-review`; a vote alone is a valid review. The body length
   limit still applies. Replies and discussion still require text. Submissions
   still require a body.
5. **Decision.** On a referral the Board chooses `community` (shown as
   **Accept**) or `reject`; `cdcf` returns `400`. Accept publishes the
   existing `community_project` in place — it must **not** run
   `cdcf_review_convert_to_community()` (no field remapping, no
   `_cdcf_converted_from_submission`). Reject moves it to draft. The decision
   lock, recording, `decision_recorded` event and decision email follow the
   submission path unchanged.
6. **Admin publish.** An administrator who publishes a referral under review
   in wp-admin records an `admin_override` decision of `community`, which the
   existing hook already does for `community_project`. No change beyond
   making sure the edit-screen notice also appears on referrals.
7. **Submitter address.** Referrals store `_referral_submitter_name` and
   `_referral_submitter_email`; the mailer, token and resend-link code read
   `_submission_submitter_email`. The builder introduces one accessor
   (e.g. `cdcf_review_submitter_email(int $id)`) that reads the submission
   key and falls back to the referral key, and uses it everywhere the
   submitter address is read (mailer, resend link, Board-only email display
   in the workspace). New referrals keep writing the `_referral_*` keys.
8. **Referrer wording.** Submitter emails (`email-strings.php`) get a
   referral variant of each template (`confirmation`, `question`, `link`,
   `decision`) in all six languages, phrased around "the project you
   referred" / "the project you suggested". The outcome label for an
   accepted referral is "Accepted as a community project" (existing
   label); "Not accepted" for reject. The status page
   (`/[lang]/submission-status/[id]`) gets referral wording through
   `messages/*.json`; `GET /submission-status/{id}` returns a flag (e.g.
   `kind: "referral"`) so the page can choose.
9. **Member wording.** The `submission_received` member email and the inbox
   item say "New community project referral to review: {title}" for a
   referral (all six languages); other member emails are unchanged. The
   event payload or a lookup of the project tells the template which to use.
10. **Admin email kept, pointed at the review.** The referral handler keeps
    emailing `admin_email`, as the submission handler still does _(Claude's
    call)_, but the text no longer says "Review and approve it here" with a
    wp-admin edit link: it says the referral is under council review and links
    to the review workspace (`/review/{id}` on the frontend). The
    "Pending Community Project Referrals" dashboard widget in `functions.php`
    changes the same way: it says the referrals are under council review and
    its button opens the workspace, not the edit screen. Publishing from
    wp-admin remains possible and records an `admin_override` (decision 6).
    _(John's decision.)_
11. **Backfill of existing referrals: all of them.** A schema-version bump
    (to `4`; PR #368 does not change the version) runs, once, for every
    pending `community_project` post with `_referral_submitter_email` and no
    review stage: set stage `in_review`, record `submission_received`
    (`data: {migrated: true}`), and send the referral confirmation email. No
    date cutoff or selection. Same named-lock and "skip already announced"
    guards as the v3 submission backfill, so concurrent first requests email
    each person once. _(John's decision.)_

## Frontend

- **List** (`/[lang]/review`, `PendingList` / `ProjectCard`): a "Community
  referral" badge on referrals; the tally bar shows two segments
  (Accept / Reject) instead of three. The list response gains a field telling
  the two apart (e.g. `kind: "submission" | "referral"`).
- **Workspace** (`/[lang]/review/[id]`): the project detail response carries
  the same `kind`. On a referral, `MyReviewComposer` shows two vote options
  and lets the member save with an empty body; `DecisionPanel` shows two
  outcomes; the submitter card says "Referred by" instead of "Submitted by".
- **Status page:** referral wording as in decision 8.
- **Inbox / bell:** `InboxItemSummary` uses referral wording for
  `submission_received` on a referral.
- All new UI strings go into `messages/*.json` for the six locales.

## Constraints

- WordPress changes ship only with a **production** deploy.
- Sanitization stays in the REST `args` blocks; handlers don't re-sanitize.
- Async email goes through the Redis queue (`cdcf_review_email` job), never
  WP-Cron.
- Tests: PHPUnit for every handler/helper change (vote and body rules on
  referrals, decision path without conversion, address fallback, referral
  email templates in six languages, backfill idempotence and lock), Vitest for
  the composer/decision/tally/status-page variants.

## Interaction with PR #368

PR #368 (notification catch-up for late-joining members) already covers any
project with an open review stage, so referrals under review are caught up for
new members with no extra work once both land.

## Left to the builder

- How `kind` is derived and exposed (decision 2), and the exact field name in
  REST responses.
- Exact translations, keeping the tone of the existing strings.
- Component structure for the two-option vote and decision UI.
- Whether the referral handler records the event and sends the confirmation
  before or after the admin email (submissions do event → confirmation →
  admin email).
- A submitter-name accessor alongside the email one (decision 7), mapping the
  referral's fields (`project_github_url` rather than `project_repo_url`) into
  the workspace's submission card, the referral form's success message, and
  using the referral template in the Board's decision-email preview.

## Already true in the code (verified)

- `cdcf_review_get_reviewable_project`, the list query, the admin-override
  hook and the v2 backfill already accept `community_project`.
- Plurality and the decision rationale check work unchanged with only
  `community` and `reject` votes.
- Publishing a referral in place already fires the auto-translate and
  Projects-page linking hooks (`cdcf_enqueue_translations_on_publish`,
  `cdcf_link_referral_on_publish`).
