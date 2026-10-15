# Council review of submitted projects

**Status:** design, awaiting review.

## Problem

A public project submission today creates a `pending` `project` post and
emails `admin_email`. An administrator then publishes it by hand in wp-admin.
The Technical and Ecclesial Advisory Councils, who are meant to judge
submissions, have no part in the process: there is no place to review, vote,
or discuss, and the submitter gets no confirmation and no decision.

A council may also judge that a submission belongs among the community
projects rather than the CDCF projects. Those are a different post type
(`community_project`) with different fields, and nothing converts one into
the other.

## Scope

In scope:

- A signed-in review workspace in the Next.js frontend for members of the
  Technical Council, the Ecclesial Council and the Board of Directors.
- One review per council member, carrying a vote (accept as CDCF project,
  accept as community project, reject) and a Markdown rationale, with a reply
  thread under each review and a general discussion thread per project.
- Markdown and image attachments on any reviewer entry, opened in a side
  panel.
- A Board decision that publishes, converts to a community project, or
  rejects the submission.
- A no-account status page for the submitter, reached through a tokenized
  link, where they can answer committee questions and read the decision.
- An in-app notification bell (all events) and email (key events only).

Out of scope:

- Community-project **referrals** (`refer-community-project`). They keep their
  current admin-review flow.
- Converting **already-published** CDCF projects into community projects.
  Conversion happens only as the outcome of a review, before publication.
- Reopening a decided review. A mistaken decision is corrected by an
  administrator in wp-admin.
- Machine translation of the Board's summary to the submitter.
- Live updates. Pages refresh after the viewer's own actions; the bell polls.

## Decisions

1. **Visibility.** Reviews, votes, replies, discussion and attachments are
   visible only to council and Board members. The submitter sees the stage,
   the committee's questions (unattributed), their own answers and the
   Board's decision summary.
2. **Discussion shape.** Independent reviews side by side, each with a flat
   (one-level) reply thread, plus one general discussion thread per project.
3. **Decision rule.** Council votes are advisory. A Board member records the
   decision; a rationale is required when it differs from the plurality vote.
   No quorum is computed.
4. **Location.** Next.js pages under `/[lang]/review`, backed by `cdcf/v1`
   endpoints called with the user's Zitadel bearer token, following the
   `/my-bio` pattern.
5. **Membership source.** Derived from the About page council relationship
   fields through the user's `author_team_member` link. No new roles.
6. **Conversion scope.** Pending submissions only.
7. **Notifications.** Bell shows every event; email goes out on key events
   only (see [Notifications](#notifications)).
8. **Storage.** Custom database tables, so that no core, WPGraphQL or Polylang
   surface can expose deliberations, including after the project is
   published.

## Membership and permissions

`cdcf_review_membership( int $user_id ): array{technical: bool, ecclesial:
bool, board: bool}`:

1. Read the user's `author_team_member` link (ACF user field). No link → all
   false.
2. Collect the linked `team_member` post's Polylang translation ids.
3. Read `technical_council`, `ecclesial_council` and `team_members` (the
   Board) on the **English** About page — the canonical copy, as in the
   existing `cdcf_my_team_member_is_board_member()` check. A flag is true
   when any of the collected ids appears in the corresponding field.

The result is cached in a per-user transient whose key embeds a generation
counter (`cdcf_review_membership_gen`). Saving an About page or writing any
user's `author_team_member` meta bumps the counter, so changes apply
immediately.

| Permission | Granted to                                  |
| ---------- | ------------------------------------------- |
| `view`     | any member (technical, ecclesial or board)  |
| `vote`     | technical or ecclesial council member       |
| `discuss`  | any member — replies, discussion, questions |
| `decide`   | Board member                                |

A user who is both on a council and on the Board holds `vote` and `decide`.
Administrators who are not members have no access through these endpoints.

The reverse lookup — all WordPress users who are members — is used for
notification fan-out. It reads the three About page fields, expands each
`team_member` id to its translation group, and queries users whose
`author_team_member` meta points at any of those ids.

A wp-admin screen, **Projects → Review access** (`manage_options`), lists
every council and Board `team_member` with the WordPress user it is linked to,
or "not linked". Unlinked members cannot sign in to review and receive no
notifications.

## Data model

### Project post meta (private)

| Meta key                          | Value                                              |
| --------------------------------- | -------------------------------------------------- |
| `_cdcf_review_stage`              | `in_review` · `awaiting_submitter` · `decided`     |
| `_cdcf_review_decision`           | `cdcf` · `community` · `reject`                    |
| `_cdcf_review_decided_by`         | user id                                            |
| `_cdcf_review_decided_at`         | Unix timestamp                                     |
| `_cdcf_review_rationale`          | Board rationale (internal)                         |
| `_cdcf_review_summary`            | summary sent to the submitter                      |
| `_cdcf_review_token_hash`         | SHA-256 hex of the submitter's status token        |
| `_cdcf_review_token_expires`      | Unix timestamp; set to decision time + 90 days     |
| `_cdcf_converted_from_submission` | `1` on a submission converted to community project |

A post is under review when `_cdcf_review_stage` is set. Submissions created
before this feature are backfilled to `in_review` once (no email; no token
until the first submitter email is sent).

### Tables

Created with `dbDelta()` and versioned by the `cdcf_review_db_version`
option, checked on `admin_init` and on REST requests to the review namespace.
Table names below omit `$wpdb->prefix`.

`cdcf_review_entries`

| Column           | Type               | Notes                                                                                                          |
| ---------------- | ------------------ | -------------------------------------------------------------------------------------------------------------- |
| `id`             | bigint unsigned PK |                                                                                                                |
| `project_id`     | bigint unsigned    | indexed                                                                                                        |
| `kind`           | varchar(20)        | `review` · `reply` · `discussion` · `question` · `answer`                                                      |
| `parent_id`      | bigint unsigned    | `reply` → its `review`; `answer` → its `question`; else NULL                                                   |
| `author_user_id` | bigint unsigned    | NULL only for `answer` (the submitter)                                                                         |
| `review_slot`    | bigint unsigned    | `author_user_id` on `review` rows, else NULL                                                                   |
| `body_md`        | longtext           | Markdown                                                                                                       |
| `vote`           | varchar(20)        | `review` only: `cdcf` · `community` · `reject`                                                                 |
| `created_at`     | datetime (UTC)     |                                                                                                                |
| `edited_at`      | datetime (UTC)     | NULL until edited. `COALESCE(edited_at, created_at)` is the entry's `version` token for optimistic concurrency |

A plain column `review_slot` (equal to `author_user_id` on `review` rows,
NULL otherwise) carries a unique key with `project_id`, enforcing one
review per reviewer per project. `dbDelta()` does not handle generated
columns, and MySQL permits many NULLs under a unique key.

`cdcf_review_attachments`

| Column          | Type               | Notes                                    |
| --------------- | ------------------ | ---------------------------------------- |
| `id`            | bigint unsigned PK |                                          |
| `entry_id`      | bigint unsigned    | indexed                                  |
| `kind`          | varchar(10)        | `markdown` · `image`                     |
| `mime`          | varchar(50)        | sniffed with `finfo`, not client-claimed |
| `original_name` | varchar(255)       | sanitized; display only                  |
| `storage_key`   | char(64)           | random hex; the on-disk file name        |
| `size`          | int unsigned       | bytes                                    |
| `created_at`    | datetime (UTC)     |                                          |

`cdcf_review_events`

| Column          | Type               | Notes                                                        |
| --------------- | ------------------ | ------------------------------------------------------------ |
| `id`            | bigint unsigned PK |                                                              |
| `project_id`    | bigint unsigned    | indexed                                                      |
| `type`          | varchar(30)        | see [Notifications](#notifications)                          |
| `actor_user_id` | bigint unsigned    | NULL for submitter actions                                   |
| `entry_id`      | bigint unsigned    | NULL when not entry-related                                  |
| `data`          | text               | JSON, e.g. `{"from":"reject","to":"cdcf"}` for a vote change |
| `created_at`    | datetime (UTC)     |                                                              |

`cdcf_review_notifications`

| Column     | Type            | Notes                           |
| ---------- | --------------- | ------------------------------- |
| `event_id` | bigint unsigned | PK part                         |
| `user_id`  | bigint unsigned | PK part; indexed with `read_at` |
| `read_at`  | datetime (UTC)  | NULL = unread                   |

One row per recipient is written when an event is recorded (all members
except the actor).

### Rules

- A vote lives on the reviewer's `review` row. Changing it is an edit of the
  review and records a `vote_changed` event with the previous value.
- Authors may edit only their own entries. There is no delete in v1.
- When the stage is `decided`, every write endpoint for that project returns
  `403 review_locked`.

### Attachment storage

Files are written to the directory named by the `CDCF_REVIEW_STORAGE_DIR`
constant in `wp-config.php`, which should sit outside the web root. When the
constant is undefined, files go to `wp-content/uploads/cdcf-review/`, which
receives a deny-all `.htaccess` and an empty `index.php`; production must
also deny that path in nginx, and the Review access screen shows a warning
while the fallback is in use.

| Kind     | Accepted                           | Limit  | Checks                         |
| -------- | ---------------------------------- | ------ | ------------------------------ |
| markdown | `.md`, or pasted text with a title | 512 KB | valid UTF-8                    |
| image    | PNG, JPEG, WebP, GIF               | 5 MB   | `finfo` MIME matches extension |

SVG and every other type are rejected. A pasted Markdown attachment is stored
as `<slugified title>.md`.

## REST endpoints

Namespace `cdcf/v1`. Reviewer endpoints authenticate with the Zitadel bearer
token (`zitadel-bearer.php`). No cookie auth is involved, so CSRF does not
apply. Each route declares `sanitize_callback`s in its `args` block per the
repository's sanitization convention.

### Reviewer

| Method | Route                              | Permission | Description                                                                                                                                                                                                      |
| ------ | ---------------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/review/me`                       | signed in  | Caller's WordPress user id and membership flags. Used to show the `/review` link and the bell, and to mark the caller's own entries.                                                                             |
| GET    | `/review/projects?stage=`          | `view`     | List: id, title, category, language, submitted_at, stage, tally, my vote, unread count.                                                                                                                          |
| GET    | `/review/projects/{id}`            | `view`     | Submission fields; reviews with replies; discussion; Q&A; tally; plurality; attachment metadata; decision (when decided). Submitter email only when the caller has `decide`.                                     |
| PUT    | `/review/projects/{id}/my-review`  | `vote`     | Create or update the caller's review: `vote`, `body_md`, `version` (required when a review exists; missing → `409 review_exists`).                                                                               |
| POST   | `/review/projects/{id}/entries`    | `discuss`  | `kind` = `reply` (requires `parent_id` of a review) or `discussion`; `body_md`.                                                                                                                                  |
| PATCH  | `/review/entries/{id}`             | author     | `body_md`, `version`. `409` when `version` does not match.                                                                                                                                                       |
| POST   | `/review/entries/{id}/attachments` | author     | Multipart file, or JSON `{ title, markdown }`.                                                                                                                                                                   |
| GET    | `/review/attachments/{id}`         | `view`     | Streams the file with `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, `Cache-Control: private, no-store` and `Content-Disposition` (`inline` for images, `attachment` for `?download=1`). |
| POST   | `/review/projects/{id}/question`   | `discuss`  | `body` (plain text). Sets stage `awaiting_submitter`, rotates the token and emails the submitter the fresh link.                                                                                                 |
| POST   | `/review/projects/{id}/decision`   | `decide`   | `decision`, `rationale`, `submitter_summary`. See [Decision](#decision-and-conversion).                                                                                                                          |
| GET    | `/review/inbox?unread=&page=`      | `view`     | Notifications, newest first, 20 per page, with project title and actor name.                                                                                                                                     |
| GET    | `/review/inbox/count`              | `view`     | `{ unread }` for the bell.                                                                                                                                                                                       |
| POST   | `/review/inbox/read`               | `view`     | `{ event_ids: [] }` or `{ all: true }`.                                                                                                                                                                          |

### Submitter

The token travels in the `X-CDCF-Submission-Token` header and is compared with
`hash_equals()` against the stored hash. A missing, wrong or expired token
returns a generic `404`. Requests are rate-limited per IP with the same
transient pattern as `submit-project.php`.

| Method | Route                            | Description                                                                                                                                                                       |
| ------ | -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/submission-status/{id}`        | Title, submitted_at, language, stage, questions with answers, decision and summary when decided, public URL when published.                                                       |
| POST   | `/submission-status/{id}/answer` | `question_id`, `body` (≤ 10,000 chars, plain text, spam-scored with the existing helper). Records an `answer` entry, sets stage `in_review`, records `submitter_answered`.        |
| POST   | `/submission-status/resend-link` | `project_id`, `email`. When the email matches the stored submitter email, rotates the token and emails the new link to the stored address. Always returns the same generic `202`. |

## Next.js

### Server data layer

`lib/review-api.ts` (server-only, modelled on `lib/bio-api.ts`) wraps every
reviewer endpoint with the session's bearer token. Route handlers under
`app/api/review/*` expose what client components need. The attachment route
streams the WordPress response and forwards its security headers unchanged.

### Pages

- `/[lang]/review` — tabs: In review · Awaiting submitter · Decided. Each card
  shows project name, category, submission date and language, a tally bar
  (CDCF / Community / Reject / not yet voted), the caller's vote or an
  "awaiting your vote" badge, and an unread marker. Non-members see an explanation of who can review
  (rendered with HTTP 200); anonymous visitors are redirected to sign in.
- `/[lang]/review/[id]` — the workspace, top to bottom:
  1. Submission card: all submitted fields, repo links, tags, submitter name
     (and email for Board members).
  2. Tally strip; for Board members, the Decision panel: plurality, decision
     selector, rationale (required when overriding the plurality), summary
     for the submitter (labelled with the language to write it in), and a
     preview of the submitter email.
  3. My review composer (council members): vote selector plus Markdown editor
     with Write and Preview tabs. After submission it becomes the caller's
     review card with Edit.
  4. Reviews: one card per reviewer with council badge, vote, body,
     attachment chips, "edited" marker, reply thread and reply box.
  5. General discussion.
  6. Questions to submitter: Q&A history and an "Ask the submitter" composer
     warning that the text is emailed verbatim. In Phase 2 this section is read-only; the composer
     arrives with Phase 3.
- `/[lang]/review/inbox` — full notification list with an unread filter.

Every composer has an Attach control (upload, or Paste Markdown with title
and textarea). Attachments appear as chips. Clicking a chip opens a side
panel (about 45% width on desktop, full-screen sheet on mobile) driven by the
`?att=<id>` search param, so the link is shareable and Back closes it:

- Markdown: rendered reader, Raw toggle, Download.
- Image: fit-to-panel with click-to-zoom; previous/next across the entry's
  images.

### Markdown rendering

`react-markdown` with `remark-gfm`, used for entry bodies and Markdown
attachments alike, configured in one shared component:

- `skipHtml` — raw HTML is never rendered.
- Links: `target="_blank" rel="noopener noreferrer nofollow"`.
- Images in Markdown render as plain links, never as `<img>`, so a
  confidential page never fetches third-party resources.
- Styled with the existing `.prose` class.

### Notification bell

A client component next to `AuthButton`, rendered only when `/review/me`
reports membership. It polls `/api/review/inbox/count` every 60 seconds while
the tab is visible. The dropdown shows the latest 10 events
("Fr. A replied to your review · Liturgy Helper · 5m"); clicking one marks it
read and navigates to `/review/[id]#entry-<id>`. It offers Mark all read and
View all.

### Pages are Server Components

Pages fetch through `lib/review-api.ts`. Composers, the side panel and the
bell are client components that call `router.refresh()` after a mutation.
UI strings live in `messages/*.json` under a `review` namespace for all six
locales; reviewer-written content is shown as written.

### Submitter status page

`/[lang]/submission-status/[id]`:

- Arriving with `?t=<token>`, a route handler stores the token in an
  `httpOnly`, `Secure`, `SameSite=Lax` cookie scoped to that path and
  redirects to the clean URL, so the token leaves the address bar and
  history.
- The page sends `Referrer-Policy: no-referrer` and `noindex`, and forwards
  the token to WordPress server-side only.
- Content: project name and submission date; a timeline (Received → Under
  review → Question awaiting your reply → Decision); each question from "the
  CDCF review committee" with an answer form; the decision and summary once
  decided, with a link to the public listing when published.
- Without a valid token: an "Email me a new link" form.
- Strings come from `messages/*.json`; the page renders in the submission
  language.

## Decision and conversion

`POST /review/projects/{id}/decision` validates: caller has `decide`; stage is
not `decided`; `submitter_summary` is non-empty; `rationale` is non-empty when
`decision` differs from the plurality vote (or when there are no votes). It
performs the outcome first and records the decision only on success.

| Decision    | Outcome                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `cdcf`      | `wp_update_post` to `publish`. The existing `cdcf_enqueue_translations_on_publish` hook creates and queues the five translations. `project_status` stays `incubating`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `community` | 1. Copy the first repository URL from `project_repo_url` into `project_github_url`; keep `project_url`, `project_category` and `project_tag` terms. 2. Delete `project_status`, `project_license`, `project_leads`, `project_repo_url` and their ACF `_<field>` reference meta. 3. `set_post_type( $id, 'community_project' )` (the id, and so the review record, is preserved). 4. Set `_cdcf_converted_from_submission`. 5. Publish. `cdcf_link_referral_on_publish` is extended to treat that marker like referral meta, so each language's Projects page `community_projects` relationship receives the post, and the translation hook fires. If publishing fails, the post type and fields are restored and the endpoint returns `500`. |
| `reject`    | Post status `pending` → `draft`. Leaves the pending queue and admin counters but stays recoverable. Trash is avoided because `cdcf_repend_submission_on_untrash` would return it to `pending`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |

Then: write the decision meta, set stage `decided`, set token expiry, record
`decision_recorded`, queue member emails, and email the submitter. An email
failure is reported in `warnings[]` and does not roll back the decision.

To verify during planning:

- Whether the `project_category` choices match between `group_project` and
  `group_community_project`; if not, add a mapping table.
- Whether the existing publish path revalidates `/projects` in every locale.

### Admin override

The edit screen of a post under review shows a notice: "Under council review
— decide in /review". Publishing is not blocked. When an administrator
publishes a post whose stage is not `decided`, a `transition_post_status`
hook sets stage `decided`, decision `cdcf`, decided-by the administrator, and
records `admin_override`. No submitter email is sent.

## Notifications

| Event                 | Bell (all members except actor) | Email                                |
| --------------------- | ------------------------------- | ------------------------------------ |
| `submission_received` | yes                             | all members                          |
| `review_posted`       | yes                             | —                                    |
| `review_edited`       | yes                             | —                                    |
| `vote_changed`        | yes                             | —                                    |
| `reply_posted`        | yes                             | the parent review's author           |
| `discussion_posted`   | yes                             | —                                    |
| `attachment_added`    | yes                             | —                                    |
| `question_sent`       | yes                             | — (the submitter receives their own) |
| `submitter_answered`  | yes                             | all members                          |
| `decision_recorded`   | yes                             | all members                          |
| `admin_override`      | yes                             | all members                          |

Emails are plain text, one per recipient, sent asynchronously through the
existing Redis queue: a `cdcf_review_email` job type is registered on the
`redis_queue_create_job` filter (as `cdcf-redis-translations` does for
translations), enqueued with `redis_queue()->queue_manager->enqueue()`, and
drained by `cdcf-queue-worker` through `/process-queue`. WP-Cron is not used:
on a headless install the WordPress site gets almost no page views, so
WP-Cron would rarely fire. When the `redis_queue` plugin is unavailable, the
email is sent inline and any failure is logged; the request still succeeds.

### Submitter emails

Sent in the submission language from a PHP string table
(`includes/review/email-strings.php`, six languages). Only the token's hash is
stored, so every submitter email rotates the token and carries a fresh link;
the most recent email's link is the valid one.

1. Confirmation, with the status link — sent by `submit-project.php` on every
   new submission, which also issues the token and sets stage `in_review`.
2. "The committee has a question", with the question text and the link.
3. The decision, with the summary and the link.

## Error handling

- `401` anonymous; `403` missing permission or `review_locked`; `404` unknown
  project or entry; `409` stale or missing `version`; `400` invalid input or rejected
  attachment, with a reason the UI shows on the chip.
- Submitter endpoints return a generic `404` for every token failure.
- Conversion failures roll back as described above.
- Email failures are logged and surfaced as warnings, never fatal.

## Testing

- **PHPUnit (theme):** membership resolution including translated bios and
  cache invalidation; the permission matrix for every endpoint;
  one-review-per-reviewer; locking after decision; plurality and rationale
  rule; each decision outcome including conversion and its rollback; token
  hashing, expiry and rotation; attachment validation (SVG rejected, MIME
  mismatch, oversize, non-UTF-8 Markdown); event fan-out excluding the actor;
  the admin-override hook. New include files use the one-line
  `defined('ABSPATH') || exit;` guard.
- **Vitest:** `lib/review-api.ts`, and the shared Markdown renderer's
  configuration (no raw HTML, no remote images).
- **Manual, local stack:** with Technical, Ecclesial and Board test users,
  run submit → review → reply → attach → ask → answer via the status link →
  decide as community, and confirm the converted post appears on all six
  Projects pages once translated.

## Delivery

One spec, implemented in five PRs:

1. Tables, membership, Review access screen, core reviewer endpoints.
2. `/review` UI, Markdown renderer, attachments and side panel.
3. Submitter token, status page and submitter emails.
4. Decision and conversion, admin override.
5. Notification bell, inbox and member emails.
