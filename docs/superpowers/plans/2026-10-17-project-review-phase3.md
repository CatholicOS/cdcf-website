# Council Project Review — Phase 3 (submitter token, status page, submitter emails) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the person who submitted a project a private, no-account
status page — reached through a link emailed to them — where they follow
the review, answer the committee's questions, and (from Phase 4) read the
decision; and let council members ask those questions from the workspace.

**Architecture:** WordPress issues a random token per submission and stores
only its SHA-256 hash; every submitter email rotates it, so only the latest
link works. Three public `cdcf/v1/submission-status/*` endpoints check the
token (sent in a header) and are rate-limited per IP and project. A new
reviewer endpoint records a question, moves the stage to
`awaiting_submitter` and emails the submitter. Submitter emails are plain
text from a six-language string table and go through the Redis queue
(`cdcf_review_email` job), falling back to an inline `wp_mail` when the
queue plugin is absent. In Next.js the emailed link hits a route handler
that moves the token into an `httpOnly` cookie and redirects to the clean
`/[lang]/submission-status/[id]` page, a Server Component that reads the
cookie and calls WordPress server-side; the answer and "email me a new
link" forms are client components posting to thin `/api/submission-status/*`
route handlers.

**Tech Stack:** PHP 8.3 + PHPUnit 10 / Brain Monkey / Patchwork (theme);
Next.js 16 App Router, React 19, next-intl 4, Auth.js v5, Tailwind v4,
Vitest 5 (+ happy-dom for component tests).

**Spec:** `docs/superpowers/specs/2026-10-15-project-review-design.md`
(sections "Project post meta", "REST endpoints → Submitter",
"Submitter status page", "Notifications → Submitter emails", "Error
handling"). Phases 1–2 shipped in PRs #345, #346 and #349: tables,
membership, reviewer endpoints, `/review` UI, attachments, the visual
editor. `question`/`answer` entries already work end to end in the data
layer (`cdcf_review_insert_entry` accepts any kind with a null author; the
detail handler nests answers under questions).

## Global Constraints

- Only `hash('sha256', $token)` is stored (`_cdcf_review_token_hash`); the
  raw token is 64 lowercase hex characters from `random_bytes(32)`. Issuing
  a token replaces the previous hash. `_cdcf_review_token_expires` (Unix
  time, set at decision in Phase 4) is honoured when present.
- The token travels in the `X-CDCF-Submission-Token` header to WordPress
  and is compared with `hash_equals()`. A missing, malformed, wrong or
  expired token, an unknown project, and a project not under review all
  return the same `404 not_found`.
- Submitter endpoints are rate-limited with transients keyed by
  `md5(REMOTE_ADDR . '|' . project_id)`: `GET` 120/hour, `answer` 20/hour,
  `resend-link` 5/hour. `resend-link` always answers `202 {accepted: true}`,
  whatever happened.
- Answers: plain text (`sanitize_textarea_field`), ≤ 10,000 characters,
  refused when `cdcf_is_spam_content()` flags them, one answer per question,
  refused once the stage is `decided`.
- Questions are plain text, emailed verbatim, never attributed to a member
  on the submitter's side.
- Submitter emails are plain text, in the submission language (`en`
  fallback), built from `includes/review/email-strings.php`, sent through the
  Redis queue job `cdcf_review_email` when `redis_queue()` exists, otherwise
  inline with `wp_mail`. **No WP-Cron.** Logs never contain email addresses
  or tokens.
- The status link is
  `CDCF_FRONTEND_URL/api/submission-status/{id}/open?lang={lang}&t={token}`
  (`http://localhost:3000` when the constant is undefined).
- The status page sends `robots: noindex, nofollow` and
  `referrer: no-referrer`. The token cookie is `cdcf_status_{id}`,
  `httpOnly`, `SameSite=Lax`, `Secure` in production, `path=/`, 180 days.
  The token never reaches client JavaScript.
- All REST route args declare `sanitize_callback`; args with `enum` also set
  `validate_callback => rest_validate_request_arg`. New review routes need a
  key in `cdcf_review_route_args()` (the registration loop reads
  `$args[$route]`).
- New PHP include files use the one-line `defined('ABSPATH') || exit;` guard
  and are added to `tests/bootstrap.php`'s require list.
- UI strings: new `SubmissionStatus` namespace and `Review.questions.*`
  additions, in all six `messages/*.json` files, edited as text (the files
  mix `\u00e9`-style escapes and literal characters; never re-serialize
  them).
- Use `Link`, `useRouter` from `@/src/i18n/navigation`; `notFound`,
  `redirect` from `next/navigation`.
- Commit messages end with
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and
  `Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k`.

## Rulings made while planning (spec silent or unworkable as written)

1. **Cookie scope.** The spec says the cookie is "scoped to that path". The
   page lives at `/submission-status/{id}` _and_ `/{lang}/submission-status/{id}`
   (`localePrefix: 'as-needed'`), and the answer route at
   `/api/submission-status/{id}/answer`; no single path covers all three. The
   cookie is therefore _name_-scoped (`cdcf_status_{id}`) with `path=/`.
   Cost if wrong: a browser holding several status cookies sends all of them
   to every page of the site — harmless, they are `httpOnly` and only the
   matching id is read.
2. **Where the link lands.** Server Components cannot set cookies, so the
   emailed link points at the route handler
   `/api/submission-status/{id}/open`, which sets the cookie and redirects
   (via `redirect()`, as `app/api/preview/route.ts` already does in
   production) to the clean page. `lang` rides in the link because only
   WordPress knows the submission language.
3. **Rate-limit key.** All Next.js → WordPress calls arrive from the Next
   server's IP, so a per-IP WordPress limit would be one shared bucket for
   every submitter. Keys combine IP and project id. (The same flaw affects
   the existing `submit-project` limit — out of scope, noted in the PR.)
4. **Spam answers** get `400 answer_rejected` with a neutral message instead
   of `submit-project`'s silent fake success, so a real person whose answer
   trips the heuristic is told and can rephrase.
5. **One answer per question** (`409 already_answered`); the stage returns to
   `in_review` only when every question has an answer.
6. **Confirmation email on submission** is sent after the post is created;
   the submitter's own email address was already verified with a code by
   `submit-project`, so the link reaches the person who submitted.

## Review Focus

1. **Token leaking from the address bar** (shared screenshots, browser
   history, `Referer` to the public project page): after following the
   emailed link the address bar shows no token, and the page tells the
   browser to send no referrer. Pinned by Task 6
   (`statusPagePath`, the open-route helper strips the token) and Task 7
   (page metadata test).
2. **An old or forwarded link after a newer email**: it must stop working
   and offer "Email me a new link", not show stale data or an error page.
   Pinned by Task 4 (rotated token → 404) and Task 7 (404 → resend form).
3. **Probing someone else's submission via "Email me a new link"**: wrong
   email, unknown project and rate-limited requests are indistinguishable
   from success (`202`), and the link only ever goes to the stored address.
   Pinned by Task 4.
4. **Answering after the decision, twice, or to an unknown question**: a
   clear message, nothing written. Pinned by Task 4.
5. **Email delivery failure** (no Redis plugin and `wp_mail` fails, or the
   enqueue throws): the question is still saved and the reviewer sees a
   warning; nothing is logged with an address. Pinned by Tasks 2 and 5.

## File structure

| File                                                                                | Responsibility                                                                                                |
| ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `wordpress/themes/cdcf-headless/includes/review/submitter-token.php` (new)          | Issue/rotate and verify the submitter token                                                                   |
| `wordpress/themes/cdcf-headless/includes/review/email-strings.php` (new)            | Six-language submitter email templates + renderer                                                             |
| `wordpress/themes/cdcf-headless/includes/review/mailer.php` (new)                   | Status URL, send-via-queue-or-inline, "rotate token and email the submitter"                                  |
| `wordpress/themes/cdcf-headless/includes/review/email-job.php` (new)                | `CDCF_Review_Email_Job` + `redis_queue_create_job` registration (loaded only when the queue plugin is active) |
| `wordpress/themes/cdcf-headless/includes/handlers/submission-status.php` (new)      | The three public submitter endpoints                                                                          |
| `wordpress/themes/cdcf-headless/includes/handlers/review.php`                       | `POST /review/projects/{id}/question` + its route args                                                        |
| `wordpress/themes/cdcf-headless/includes/handlers/submit-project.php`               | Send the confirmation email                                                                                   |
| `wordpress/themes/cdcf-headless/functions.php`                                      | Requires, job loading, route registration                                                                     |
| `wordpress/themes/cdcf-headless/tests/*`                                            | New tests; bootstrap gains requires, a request-header fake and a job stub                                     |
| `lib/submission-status-types.ts` (new)                                              | Shared TS types for the status payload                                                                        |
| `lib/submission-token.ts` (new)                                                     | Cookie name/options, token pattern, clean page path                                                           |
| `lib/submission-status-api.ts` (new, server-only)                                   | Calls the public WordPress endpoints                                                                          |
| `lib/submission-status-ui.ts` (new)                                                 | Timeline steps, error-code → message key                                                                      |
| `lib/ip-rate-limit.ts` (new)                                                        | In-memory per-IP limiter for the resend route                                                                 |
| `lib/review-api.ts`                                                                 | Export `getWpRestUrl`; add `askSubmitter`                                                                     |
| `app/api/submission-status/[id]/open/route.ts` (new)                                | Token → cookie → redirect to the clean page                                                                   |
| `app/api/submission-status/[id]/answer/route.ts` (new)                              | Forward an answer with the cookie's token                                                                     |
| `app/api/submission-status/resend-link/route.ts` (new)                              | Forward "email me a new link"                                                                                 |
| `app/api/review/projects/[id]/question/route.ts` (new)                              | Reviewer asks the submitter                                                                                   |
| `app/[lang]/submission-status/[id]/page.tsx` (new)                                  | The status page                                                                                               |
| `components/submission-status/{StatusTimeline,AnswerForm,ResendLinkForm}.tsx` (new) | Page pieces                                                                                                   |
| `components/review/AskSubmitter.tsx` (new), `components/review/QuestionsList.tsx`   | Ask composer; questions shown as plain text                                                                   |
| `messages/*.json`                                                                   | `SubmissionStatus`, `Review.questions.*`, `projects.successMessage`                                           |
| `AGENTS.md`                                                                         | Endpoint rows and a short status-page paragraph                                                               |

---

### Task 1: Submitter token (WordPress)

**Files:**

- Create: `wordpress/themes/cdcf-headless/includes/review/submitter-token.php`
- Create: `wordpress/themes/cdcf-headless/tests/ReviewSubmitterTokenTest.php`
- Modify: `wordpress/themes/cdcf-headless/tests/bootstrap.php` (require list)
- Modify: `wordpress/themes/cdcf-headless/functions.php` (review requires block, ~L804-810)

**Interfaces:**

- Produces: `CDCF_REVIEW_TOKEN_HASH_META`, `CDCF_REVIEW_TOKEN_EXPIRES_META`,
  `cdcf_review_issue_token(int $project_id): string`,
  `cdcf_review_token_matches(int $project_id, string $token): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewSubmitterTokenTest extends TestCase
{
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$id][$key] = $value;
            return true;
        });
        Functions\when('get_post_meta')->alias(
            fn(int $id, string $key, bool $single = false) => $this->meta[$id][$key] ?? ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_issue_stores_only_the_hash_of_a_64_hex_token(): void
    {
        $token = cdcf_review_issue_token(40);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $this->meta[40][CDCF_REVIEW_TOKEN_HASH_META]);
        $this->assertNotContains($token, $this->meta[40]);
    }

    public function test_each_issue_rotates_the_token(): void
    {
        $first  = cdcf_review_issue_token(40);
        $second = cdcf_review_issue_token(40);

        $this->assertNotSame($first, $second);
        $this->assertFalse(cdcf_review_token_matches(40, $first));
        $this->assertTrue(cdcf_review_token_matches(40, $second));
    }

    public function test_wrong_malformed_or_missing_tokens_do_not_match(): void
    {
        $token = cdcf_review_issue_token(40);

        $this->assertFalse(cdcf_review_token_matches(40, str_repeat('0', 64)));
        $this->assertFalse(cdcf_review_token_matches(40, strtoupper($token)));
        $this->assertFalse(cdcf_review_token_matches(40, ''));
        $this->assertFalse(cdcf_review_token_matches(41, $token), 'another project has no hash');
    }

    public function test_expiry_is_honoured_when_set(): void
    {
        $token = cdcf_review_issue_token(40);

        $this->meta[40][CDCF_REVIEW_TOKEN_EXPIRES_META] = time() + 3600;
        $this->assertTrue(cdcf_review_token_matches(40, $token));

        $this->meta[40][CDCF_REVIEW_TOKEN_EXPIRES_META] = time() - 1;
        $this->assertFalse(cdcf_review_token_matches(40, $token));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd wordpress/themes/cdcf-headless && php8.3 vendor/bin/phpunit tests/ReviewSubmitterTokenTest.php`
Expected: errors, `Call to undefined function cdcf_review_issue_token()`.

- [ ] **Step 3: Implement**

`includes/review/submitter-token.php`:

```php
<?php
/**
 * Council review — the submitter's status-page token.
 *
 * Only the SHA-256 hash is stored. Issuing a token replaces the previous
 * hash, so every submitter email carries a fresh link and only the most
 * recent one works. The expiry is set at decision time (Phase 4).
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_TOKEN_HASH_META    = '_cdcf_review_token_hash';
const CDCF_REVIEW_TOKEN_EXPIRES_META = '_cdcf_review_token_expires';

/** Issue (or rotate) the token and return the raw value to email. */
function cdcf_review_issue_token(int $project_id): string {
    $token = bin2hex(random_bytes(32));
    update_post_meta($project_id, CDCF_REVIEW_TOKEN_HASH_META, hash('sha256', $token));
    return $token;
}

function cdcf_review_token_matches(int $project_id, string $token): bool {
    if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        return false;
    }
    $stored = (string) get_post_meta($project_id, CDCF_REVIEW_TOKEN_HASH_META, true);
    if ($stored === '' || !hash_equals($stored, hash('sha256', $token))) {
        return false;
    }
    $expires = (int) get_post_meta($project_id, CDCF_REVIEW_TOKEN_EXPIRES_META, true);
    return $expires === 0 || time() <= $expires;
}
```

Add `require_once __DIR__ . '/includes/review/submitter-token.php';` to the
review requires block in `functions.php` (after `events.php`), and the same
path to `tests/bootstrap.php`'s require list next to the other
`includes/review/*.php` files.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php8.3 vendor/bin/phpunit tests/ReviewSubmitterTokenTest.php`
Expected: `OK (4 tests, …)`.

- [ ] **Step 5: Run the theme suite and commit**

Run: `php8.3 vendor/bin/phpunit` — Expected: all pass.

```bash
git add wordpress/themes/cdcf-headless/includes/review/submitter-token.php \
  wordpress/themes/cdcf-headless/tests/ReviewSubmitterTokenTest.php \
  wordpress/themes/cdcf-headless/tests/bootstrap.php wordpress/themes/cdcf-headless/functions.php
git commit -m "feat(review): submitter status token, stored as a hash and rotated per email"
```

---

### Task 2: Submitter emails — strings, status URL, queue job (WordPress)

**Files:**

- Create: `includes/review/email-strings.php`, `includes/review/mailer.php`, `includes/review/email-job.php`
- Create: `tests/stubs/AbstractBaseJobStub.php`, `tests/ReviewMailerTest.php`, `tests/ReviewEmailStringsTest.php`
- Modify: `tests/bootstrap.php`, `functions.php`

(Paths relative to `wordpress/themes/cdcf-headless/`.)

**Interfaces:**

- Consumes: `cdcf_review_issue_token()` (Task 1), `cdcf_review_post_language(int): string` (handlers/review.php).
- Produces:
  - `cdcf_review_render_email(string $template, string $lang, array $vars): array{subject: string, body: string}` — templates `confirmation`, `question`, `link`; vars `{title}`, `{link}`, `{question}`.
  - `cdcf_review_status_url(int $project_id, string $lang, string $token): string`
  - `cdcf_review_send_email(string $to, string $subject, string $body): string` — `'queued'|'sent'|'failed'`
  - `cdcf_review_send_submitter_email(int $project_id, string $template, array $vars = []): string` — rotates the token; `'queued'|'sent'|'failed'|'no_email'`
  - `CDCF_REVIEW_EMAIL_JOB = 'cdcf_review_email'`, class `CDCF_Review_Email_Job`.

- [ ] **Step 1: Job stub for tests**

`tests/stubs/AbstractBaseJobStub.php` (mirrors the plugin's own stub at
`wordpress/plugins/cdcf-redis-translations/tests/stubs/AbstractBaseJobStub.php`):

```php
<?php

namespace Soderlind\RedisQueue\Jobs;

if (!class_exists(Abstract_Base_Job::class)) {
    abstract class Abstract_Base_Job
    {
        public function __construct(protected array $payload = []) {}

        public function get_payload_value(string $key): mixed
        {
            return $this->payload[$key] ?? null;
        }

        public function get_payload(): array
        {
            return $this->payload;
        }

        protected function success(array $data = []): array
        {
            return ['success' => true, 'data' => $data];
        }

        abstract public function get_job_type();

        abstract public function execute();
    }
}
```

In `tests/bootstrap.php`, before the theme requires:
`require_once __DIR__ . '/stubs/AbstractBaseJobStub.php';`, then require
`email-strings.php`, `mailer.php` and `email-job.php` with the other
`includes/review/*.php` files.

- [ ] **Step 2: Write the failing tests**

`tests/ReviewEmailStringsTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

final class ReviewEmailStringsTest extends TestCase
{
    public function test_every_language_has_every_template_with_its_placeholders(): void
    {
        foreach (['en', 'it', 'es', 'fr', 'pt', 'de'] as $lang) {
            foreach (['confirmation' => ['{title}', '{link}'], 'question' => ['{title}', '{link}', '{question}'], 'link' => ['{title}', '{link}']] as $template => $needs) {
                $raw = cdcf_review_email_strings()[$lang][$template];
                $this->assertNotSame('', $raw['subject'], "$lang/$template subject");
                $this->assertStringContainsString('{title}', $raw['subject'], "$lang/$template subject");
                foreach ($needs as $placeholder) {
                    $this->assertStringContainsString($placeholder, $raw['body'], "$lang/$template body $placeholder");
                }
            }
        }
    }

    public function test_render_fills_placeholders(): void
    {
        $mail = cdcf_review_render_email('question', 'it', [
            'title' => 'Liturgy Helper', 'link' => 'https://x/open?t=abc', 'question' => 'Which licence?',
        ]);

        $this->assertStringContainsString('Liturgy Helper', $mail['subject']);
        $this->assertStringContainsString('https://x/open?t=abc', $mail['body']);
        $this->assertStringContainsString('Which licence?', $mail['body']);
        $this->assertStringNotContainsString('{', $mail['body']);
    }

    public function test_unknown_language_falls_back_to_english(): void
    {
        $this->assertSame(
            cdcf_review_render_email('link', 'en', ['title' => 'T', 'link' => 'L']),
            cdcf_review_render_email('link', 'xx', ['title' => 'T', 'link' => 'L'])
        );
    }
}
```

`tests/ReviewMailerTest.php`:

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewMailerTest extends TestCase
{
    private array $mails = [];
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->mails = [];
        $this->logs = [];
        Patchwork\redefine('error_log', function (string $msg): bool {
            $this->logs[] = $msg;
            return true;
        });
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = compact('to', 'subject', 'body');
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function noQueue(): void
    {
        Patchwork\redefine('function_exists', fn(string $name) => $name === 'redis_queue' ? false : Patchwork\relay());
    }

    public function test_status_url_points_at_the_open_route_with_lang_and_token(): void
    {
        $base = defined('CDCF_FRONTEND_URL') ? rtrim((string) CDCF_FRONTEND_URL, '/') : 'http://localhost:3000';

        $this->assertSame(
            $base . '/api/submission-status/42/open?lang=it&t=abc123',
            cdcf_review_status_url(42, 'it', 'abc123')
        );
    }

    public function test_sends_inline_when_the_queue_plugin_is_absent(): void
    {
        $this->noQueue();

        $this->assertSame('sent', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertSame([['to' => 'sam@example.org', 'subject' => 'S', 'body' => 'B']], $this->mails);
    }

    public function test_queues_a_job_when_the_queue_plugin_is_active(): void
    {
        $manager = new class {
            public array $jobs = [];
            public function enqueue($job) { $this->jobs[] = $job; return 'job-1'; }
        };
        Functions\when('redis_queue')->justReturn((object) ['queue_manager' => $manager]);

        $this->assertSame('queued', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertCount(1, $manager->jobs);
        $this->assertInstanceOf(CDCF_Review_Email_Job::class, $manager->jobs[0]);
        $this->assertSame(['to' => 'sam@example.org', 'subject' => 'S', 'body' => 'B'], $manager->jobs[0]->get_payload());
        $this->assertSame([], $this->mails);
    }

    public function test_falls_back_inline_when_enqueue_throws(): void
    {
        $manager = new class {
            public function enqueue($job) { throw new RuntimeException('redis down'); }
        };
        Functions\when('redis_queue')->justReturn((object) ['queue_manager' => $manager]);

        $this->assertSame('sent', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertCount(1, $this->mails);
    }

    public function test_reports_failure_without_logging_the_address(): void
    {
        $this->noQueue();
        Functions\when('wp_mail')->justReturn(false);

        $this->assertSame('failed', cdcf_review_send_email('sam@example.org', 'S', 'B'));
        $this->assertNotEmpty($this->logs);
        foreach ($this->logs as $line) {
            $this->assertStringNotContainsString('sam@example.org', $line);
        }
    }

    public function test_job_sends_and_throws_on_failure_so_the_queue_retries(): void
    {
        $job = new CDCF_Review_Email_Job(['to' => 'sam@example.org', 'subject' => 'S', 'body' => 'B']);
        $this->assertSame('cdcf_review_email', $job->get_job_type());
        $this->assertTrue($job->execute()['success']);

        Functions\when('wp_mail')->justReturn(false);
        $this->expectException(RuntimeException::class);
        $job->execute();
    }

    public function test_submitter_email_rotates_the_token_and_uses_the_submission_language(): void
    {
        $this->noQueue();
        Functions\when('get_post_meta')->justReturn('sam@example.org');
        Functions\when('is_email')->alias(fn($e) => str_contains((string) $e, '@'));
        Functions\when('get_post_field')->justReturn('Liturgy Helper');
        Functions\when('cdcf_review_post_language')->justReturn('de');
        Functions\expect('cdcf_review_issue_token')->once()->with(42)->andReturn('tok');

        $this->assertSame('sent', cdcf_review_send_submitter_email(42, 'question', ['question' => 'Welche Lizenz?']));
        $this->assertSame('sam@example.org', $this->mails[0]['to']);
        $this->assertStringContainsString('Liturgy Helper', $this->mails[0]['subject']);
        $this->assertStringContainsString('lang=de&t=tok', $this->mails[0]['body']);
        $this->assertStringContainsString('Welche Lizenz?', $this->mails[0]['body']);
    }

    public function test_submitter_email_is_skipped_without_a_valid_address(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_email')->justReturn(false);
        Functions\expect('cdcf_review_issue_token')->never();

        $this->assertSame('no_email', cdcf_review_send_submitter_email(42, 'link'));
        $this->assertSame([], $this->mails);
    }
}
```

- [ ] **Step 3: Run them to verify they fail**

Run: `php8.3 vendor/bin/phpunit tests/ReviewEmailStringsTest.php tests/ReviewMailerTest.php`
Expected: errors on undefined `cdcf_review_email_strings()` / `cdcf_review_status_url()` / class `CDCF_Review_Email_Job`.

- [ ] **Step 4: Implement the strings**

`includes/review/email-strings.php`:

```php
<?php
/**
 * Council review — submitter email templates, one per submission language.
 * Plain text. Placeholders: {title}, {link}, {question}.
 */

defined('ABSPATH') || exit;

/** @return array<string, array<string, array{subject: string, body: string}>> */
function cdcf_review_email_strings(): array {
    $sig = "\n\nCatholic Digital Commons Foundation";
    return [
        'en' => [
            'confirmation' => [
                'subject' => 'We received your project: {title}',
                'body'    => "Thank you for submitting \"{title}\" to the Catholic Digital Commons Foundation.\n\nOur review committee will look at it and may write to you with questions. You can follow the review, and answer any questions, on this page:\n\n{link}\n\nKeep this link private: anyone who has it can see the status of your submission. Each email we send contains a new link, and only the most recent one works." . $sig,
            ],
            'question' => [
                'subject' => 'A question about your project: {title}',
                'body'    => "The review committee has a question about \"{title}\":\n\n{question}\n\nPlease answer on your submission's status page:\n\n{link}\n\nThis link replaces any earlier one; only the most recent link works." . $sig,
            ],
            'link' => [
                'subject' => 'Your link to follow your project: {title}',
                'body'    => "Here is a new link to the status page for \"{title}\":\n\n{link}\n\nIt replaces any earlier link. If you did not ask for it, you can ignore this email." . $sig,
            ],
        ],
        'it' => [
            'confirmation' => [
                'subject' => 'Abbiamo ricevuto il tuo progetto: {title}',
                'body'    => "Grazie per aver proposto \"{title}\" alla Catholic Digital Commons Foundation.\n\nIl nostro comitato di revisione lo esaminerà e potrebbe scriverti per farti delle domande. Puoi seguire la revisione, e rispondere alle domande, da questa pagina:\n\n{link}\n\nTieni questo link riservato: chiunque lo abbia può vedere lo stato della tua proposta. Ogni email che inviamo contiene un nuovo link, e funziona solo il più recente." . $sig,
            ],
            'question' => [
                'subject' => 'Una domanda sul tuo progetto: {title}',
                'body'    => "Il comitato di revisione ha una domanda su \"{title}\":\n\n{question}\n\nRispondi dalla pagina di stato della tua proposta:\n\n{link}\n\nQuesto link sostituisce quelli precedenti; funziona solo il più recente." . $sig,
            ],
            'link' => [
                'subject' => 'Il tuo link per seguire il progetto: {title}',
                'body'    => "Ecco un nuovo link alla pagina di stato di \"{title}\":\n\n{link}\n\nSostituisce i link precedenti. Se non l'hai richiesto, puoi ignorare questa email." . $sig,
            ],
        ],
        'es' => [
            'confirmation' => [
                'subject' => 'Hemos recibido tu proyecto: {title}',
                'body'    => "Gracias por presentar \"{title}\" a la Catholic Digital Commons Foundation.\n\nNuestro comité de revisión lo examinará y puede escribirte con preguntas. Puedes seguir la revisión, y responder a las preguntas, en esta página:\n\n{link}\n\nMantén este enlace en privado: cualquiera que lo tenga puede ver el estado de tu propuesta. Cada correo que enviamos contiene un enlace nuevo y solo funciona el más reciente." . $sig,
            ],
            'question' => [
                'subject' => 'Una pregunta sobre tu proyecto: {title}',
                'body'    => "El comité de revisión tiene una pregunta sobre \"{title}\":\n\n{question}\n\nResponde en la página de estado de tu propuesta:\n\n{link}\n\nEste enlace sustituye a los anteriores; solo funciona el más reciente." . $sig,
            ],
            'link' => [
                'subject' => 'Tu enlace para seguir tu proyecto: {title}',
                'body'    => "Aquí tienes un nuevo enlace a la página de estado de \"{title}\":\n\n{link}\n\nSustituye a los enlaces anteriores. Si no lo has pedido, puedes ignorar este correo." . $sig,
            ],
        ],
        'fr' => [
            'confirmation' => [
                'subject' => 'Nous avons reçu votre projet : {title}',
                'body'    => "Merci d'avoir proposé « {title} » à la Catholic Digital Commons Foundation.\n\nNotre comité d'examen va l'étudier et pourra vous écrire pour vous poser des questions. Vous pouvez suivre l'examen, et répondre aux questions, sur cette page :\n\n{link}\n\nGardez ce lien confidentiel : toute personne qui le possède peut voir l'état de votre proposition. Chaque e-mail que nous envoyons contient un nouveau lien, et seul le plus récent fonctionne." . $sig,
            ],
            'question' => [
                'subject' => 'Une question sur votre projet : {title}',
                'body'    => "Le comité d'examen a une question sur « {title} » :\n\n{question}\n\nVeuillez répondre sur la page de suivi de votre proposition :\n\n{link}\n\nCe lien remplace les précédents ; seul le plus récent fonctionne." . $sig,
            ],
            'link' => [
                'subject' => 'Votre lien pour suivre votre projet : {title}',
                'body'    => "Voici un nouveau lien vers la page de suivi de « {title} » :\n\n{link}\n\nIl remplace les liens précédents. Si vous ne l'avez pas demandé, vous pouvez ignorer cet e-mail." . $sig,
            ],
        ],
        'pt' => [
            'confirmation' => [
                'subject' => 'Recebemos o seu projeto: {title}',
                'body'    => "Obrigado por submeter \"{title}\" à Catholic Digital Commons Foundation.\n\nO nosso comité de revisão vai analisá-lo e poderá escrever-lhe com perguntas. Pode acompanhar a revisão, e responder às perguntas, nesta página:\n\n{link}\n\nMantenha este link privado: qualquer pessoa que o tenha pode ver o estado da sua submissão. Cada email que enviamos contém um novo link, e só o mais recente funciona." . $sig,
            ],
            'question' => [
                'subject' => 'Uma pergunta sobre o seu projeto: {title}',
                'body'    => "O comité de revisão tem uma pergunta sobre \"{title}\":\n\n{question}\n\nResponda na página de estado da sua submissão:\n\n{link}\n\nEste link substitui os anteriores; só o mais recente funciona." . $sig,
            ],
            'link' => [
                'subject' => 'O seu link para acompanhar o projeto: {title}',
                'body'    => "Aqui está um novo link para a página de estado de \"{title}\":\n\n{link}\n\nSubstitui os links anteriores. Se não o pediu, pode ignorar este email." . $sig,
            ],
        ],
        'de' => [
            'confirmation' => [
                'subject' => 'Wir haben Ihr Projekt erhalten: {title}',
                'body'    => "Vielen Dank, dass Sie „{title}“ bei der Catholic Digital Commons Foundation eingereicht haben.\n\nUnser Prüfungsausschuss wird es begutachten und sich möglicherweise mit Fragen an Sie wenden. Auf dieser Seite können Sie die Prüfung verfolgen und Fragen beantworten:\n\n{link}\n\nBehandeln Sie diesen Link vertraulich: Wer ihn hat, kann den Status Ihrer Einreichung sehen. Jede E-Mail von uns enthält einen neuen Link, und nur der neueste funktioniert." . $sig,
            ],
            'question' => [
                'subject' => 'Eine Frage zu Ihrem Projekt: {title}',
                'body'    => "Der Prüfungsausschuss hat eine Frage zu „{title}“:\n\n{question}\n\nBitte antworten Sie auf der Statusseite Ihrer Einreichung:\n\n{link}\n\nDieser Link ersetzt alle früheren; nur der neueste funktioniert." . $sig,
            ],
            'link' => [
                'subject' => 'Ihr Link zur Verfolgung Ihres Projekts: {title}',
                'body'    => "Hier ist ein neuer Link zur Statusseite von „{title}“:\n\n{link}\n\nEr ersetzt alle früheren Links. Wenn Sie ihn nicht angefordert haben, können Sie diese E-Mail ignorieren." . $sig,
            ],
        ],
    ];
}

/** @return array{subject: string, body: string} */
function cdcf_review_render_email(string $template, string $lang, array $vars): array {
    $all  = cdcf_review_email_strings();
    $raw  = $all[$lang][$template] ?? $all['en'][$template];
    $map  = [];
    foreach (['title', 'link', 'question'] as $key) {
        $map['{' . $key . '}'] = (string) ($vars[$key] ?? '');
    }
    return ['subject' => strtr($raw['subject'], $map), 'body' => strtr($raw['body'], $map)];
}
```

- [ ] **Step 5: Implement the mailer and the job**

`includes/review/mailer.php`:

```php
<?php
/**
 * Council review — sending email. Through the Redis queue
 * (`cdcf_review_email` job, drained by cdcf-queue-worker via
 * /process-queue) when the queue plugin is active; inline otherwise.
 * WP-Cron is never used: it barely fires on the headless install.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_EMAIL_JOB = 'cdcf_review_email';

function cdcf_review_status_url(int $project_id, string $lang, string $token): string {
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    return rtrim($frontend, '/') . '/api/submission-status/' . $project_id . '/open?'
        . http_build_query(['lang' => $lang, 't' => $token]);
}

/** @return 'queued'|'sent'|'failed' */
function cdcf_review_send_email(string $to, string $subject, string $body): string {
    if (function_exists('redis_queue') && class_exists('CDCF_Review_Email_Job')) {
        try {
            redis_queue()->queue_manager->enqueue(
                new CDCF_Review_Email_Job(['to' => $to, 'subject' => $subject, 'body' => $body])
            );
            return 'queued';
        } catch (\Throwable $e) {
            error_log('[cdcf-review] email enqueue failed, sending inline: ' . $e->getMessage());
        }
    }
    if (wp_mail($to, $subject, $body)) {
        return 'sent';
    }
    // Never log the address or the body (it carries the status token).
    error_log('[cdcf-review] email could not be sent: ' . $subject);
    return 'failed';
}

/**
 * Rotate the submitter's token and email them `$template` with the fresh
 * status link, in the submission language.
 *
 * @return 'queued'|'sent'|'failed'|'no_email'
 */
function cdcf_review_send_submitter_email(int $project_id, string $template, array $vars = []): string {
    $to = (string) get_post_meta($project_id, '_submission_submitter_email', true);
    if (!is_email($to)) {
        return 'no_email';
    }
    $lang  = cdcf_review_post_language($project_id);
    $token = cdcf_review_issue_token($project_id);
    $mail  = cdcf_review_render_email($template, $lang, $vars + [
        // Raw title: get_the_title() would HTML-escape it for a text email.
        'title' => (string) get_post_field('post_title', $project_id),
        'link'  => cdcf_review_status_url($project_id, $lang, $token),
    ]);
    return cdcf_review_send_email($to, $mail['subject'], $mail['body']);
}
```

`includes/review/email-job.php` (loaded only when the queue plugin's base
class exists):

```php
<?php
/**
 * Council review — the queued email job.
 */

defined('ABSPATH') || exit;

class CDCF_Review_Email_Job extends \Soderlind\RedisQueue\Jobs\Abstract_Base_Job
{
    protected int $timeout = 30;
    protected int $retry_attempts = 3;
    protected array $retry_backoff = [60, 300, 900];

    public function get_job_type()
    {
        return CDCF_REVIEW_EMAIL_JOB;
    }

    public function execute()
    {
        $sent = wp_mail(
            (string) $this->get_payload_value('to'),
            (string) $this->get_payload_value('subject'),
            (string) $this->get_payload_value('body')
        );
        if (!$sent) {
            // Throwing hands the job back to the queue for a retry.
            throw new \RuntimeException('wp_mail failed for a review email');
        }
        return $this->success();
    }
}

add_filter('redis_queue_create_job', function ($job, $type, $payload) {
    return $type === CDCF_REVIEW_EMAIL_JOB ? new CDCF_Review_Email_Job((array) $payload) : $job;
}, 10, 3);
```

In `functions.php`, review requires block: add
`require_once __DIR__ . '/includes/review/email-strings.php';` and
`require_once __DIR__ . '/includes/review/mailer.php';`, then:

```php
// The queued email job needs the redis-queue plugin's base class, which
// is loaded (plugins load before the theme) only when that plugin is
// active; without it, review emails are sent inline.
if (class_exists('Soderlind\\RedisQueue\\Jobs\\Abstract_Base_Job')) {
    require_once __DIR__ . '/includes/review/email-job.php';
}
```

- [ ] **Step 6: Run the tests to verify they pass, then the suite**

Run: `php8.3 vendor/bin/phpunit tests/ReviewEmailStringsTest.php tests/ReviewMailerTest.php` — Expected: OK (11 tests).
Run: `php8.3 vendor/bin/phpunit` — Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add wordpress/themes/cdcf-headless/includes/review/{email-strings,mailer,email-job}.php \
  wordpress/themes/cdcf-headless/tests/{stubs/AbstractBaseJobStub,ReviewMailerTest,ReviewEmailStringsTest}.php \
  wordpress/themes/cdcf-headless/tests/bootstrap.php wordpress/themes/cdcf-headless/functions.php
git commit -m "feat(review): submitter email templates and a queued review email job"
```

---

### Task 3: Confirmation email on submission (WordPress)

**Files:**

- Modify: `includes/handlers/submit-project.php` (after the council handoff, L137-138)
- Modify: `tests/SubmitProjectHandlerTest.php`

**Interfaces:**

- Consumes: `cdcf_review_send_submitter_email()` (Task 2).

- [ ] **Step 1: Write the failing test** — in `SubmitProjectHandlerTest`, next
      to the existing review-stage test (L146-165), using the class's existing
      setup (`stubCommonFunctions()`, then `allowAllFunctionsToExist()`):

```php
public function test_successful_submission_emails_the_submitter_their_status_link(): void
{
    $this->stubCommonFunctions();
    $sent = [];
    Functions\when('cdcf_review_send_submitter_email')->alias(
        function (int $id, string $template) use (&$sent): string {
            $sent[] = [$id, $template];
            return 'queued';
        }
    );
    $this->allowAllFunctionsToExist();

    cdcf_rest_submit_project($this->validRequest());

    $this->assertSame([[800, 'confirmation']], $sent);
}
```

(Use the request-builder helper this test class already uses for its
happy-path test; if it is named differently, use that name.) Also add
`'cdcf_review_send_submitter_email'` returning `'queued'` to
`stubCommonFunctions()` so the other tests in the class do not send.

- [ ] **Step 2: Run it to verify it fails**

Run: `php8.3 vendor/bin/phpunit --filter status_link tests/SubmitProjectHandlerTest.php`
Expected: FAIL, `[]` is not `[[800, 'confirmation']]`.

- [ ] **Step 3: Implement** — in `submit-project.php`, directly after
      `cdcf_review_record_event((int) $post_id, 'submission_received', null);`:

```php
    // The submitter's status link (their address was verified by code above).
    cdcf_review_send_submitter_email((int) $post_id, 'confirmation');
```

- [ ] **Step 4: Run the class, then the suite**

Run: `php8.3 vendor/bin/phpunit tests/SubmitProjectHandlerTest.php` then `php8.3 vendor/bin/phpunit` — Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add wordpress/themes/cdcf-headless/includes/handlers/submit-project.php \
  wordpress/themes/cdcf-headless/tests/SubmitProjectHandlerTest.php
git commit -m "feat(review): email submitters a status link when they submit"
```

---

### Task 4: Public submitter endpoints (WordPress)

**Files:**

- Create: `includes/handlers/submission-status.php`
- Create: `tests/SubmissionStatusHandlerTest.php`
- Modify: `tests/bootstrap.php` (require the handler; add header support to the `WP_REST_Request` fake)
- Modify: `functions.php` (routes)

**Interfaces:**

- Consumes: Task 1 token functions; `cdcf_review_send_submitter_email()` (Task 2);
  `cdcf_review_get_reviewable_project()`, `cdcf_review_project_entries()`,
  `cdcf_review_insert_entry()`, `cdcf_review_record_event()`,
  `cdcf_review_post_language()`, `cdcf_review_submitted_at()`,
  `cdcf_frontend_path_for()`, `cdcf_is_spam_content()`.
- Produces (routes, `permission_callback => __return_true`):
  - `GET /cdcf/v1/submission-status/{id}` → `200 SubmissionStatus` (below) or `404 not_found` / `429 rate_limited`
  - `POST /cdcf/v1/submission-status/{id}/answer` `{question_id, body}` → `201 {id, question_id, stage}`; errors `400 empty_body|body_too_long|answer_rejected`, `404 not_found|question_not_found`, `409 already_answered|review_closed`, `429 rate_limited`
  - `POST /cdcf/v1/submission-status/resend-link` `{project_id, email}` → always `202 {accepted: true}`
  - `SubmissionStatus` = `{id, title, submitted_at, language, stage, questions: [{id, body, asked_at, answer: {body, answered_at}|null}], decision: {outcome, summary, decided_at}|null, public_url: string|null}`

- [ ] **Step 1: Header support in the request fake** — in
      `tests/bootstrap.php`'s `WP_REST_Request` fake add (WordPress's real
      `get_header()` canonicalises names the same way):

```php
        private array $headers = [];

        public function set_header(string $name, string $value): void {
            $this->headers[strtolower(str_replace('-', '_', $name))] = $value;
        }

        public function get_header(string $name): ?string {
            return $this->headers[strtolower(str_replace('-', '_', $name))] ?? null;
        }
```

- [ ] **Step 2: Write the failing tests**

`tests/SubmissionStatusHandlerTest.php`:

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SubmissionStatusHandlerTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private array $meta = [];
    private array $transients = [];
    private array $entries = [];
    private array $inserted = [];
    private array $events = [];
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        $this->meta = [40 => [
            CDCF_REVIEW_STAGE_META         => 'awaiting_submitter',
            CDCF_REVIEW_TOKEN_HASH_META    => hash('sha256', self::TOKEN),
            '_submission_submitter_email'  => 'Sam@Example.org',
        ]];
        $this->transients = $this->entries = $this->inserted = $this->events = $this->emails = [];
        $this->entries = [
            ['id' => 1, 'project_id' => 40, 'kind' => 'question', 'parent_id' => null, 'author_user_id' => 7, 'body_md' => 'Which licence?', 'created_at' => '2026-10-15 10:00:00.000000'],
        ];

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('rest_ensure_response')->alias(fn($data) => new WP_REST_Response($data));
        Functions\when('get_post_meta')->alias(fn(int $id, string $k, bool $s = false) => $this->meta[$id][$k] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v): bool {
            $this->meta[$id][$k] = $v;
            return true;
        });
        Functions\when('get_transient')->alias(fn(string $k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, $v): bool {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('cdcf_review_get_reviewable_project')->alias(
            fn(int $id) => $id === 40
                ? (object) ['ID' => 40, 'post_title' => 'Liturgy Helper', 'post_status' => 'pending', 'post_type' => 'project', 'post_date' => '2026-10-01 11:00:00', 'post_date_gmt' => '2026-10-01 09:00:00']
                : new WP_Error('review_not_found', 'No project under review with that id.', ['status' => 404])
        );
        Functions\when('cdcf_review_project_entries')->alias(fn() => $this->entries);
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row): int {
            $this->inserted[] = $row;
            return 99;
        });
        Functions\when('cdcf_review_record_event')->alias(function (...$args): int {
            $this->events[] = $args;
            return 1;
        });
        Functions\when('cdcf_review_post_language')->justReturn('it');
        Functions\when('cdcf_frontend_path_for')->justReturn(null);
        Functions\when('cdcf_is_spam_content')->justReturn(false);
        Functions\when('cdcf_review_send_submitter_email')->alias(function (int $id, string $t): string {
            $this->emails[] = [$id, $t];
            return 'queued';
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function request(array $params, ?string $token = self::TOKEN): WP_REST_Request
    {
        $r = new WP_REST_Request();
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        if ($token !== null) {
            $r->set_header('X-CDCF-Submission-Token', $token);
        }
        return $r;
    }

    private function assertNotFound($result): void
    {
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
        $this->assertSame(404, $result->get_error_data()['status']);
    }

    // ─── GET ───────────────────────────────────────────────────────────

    public function test_status_lists_questions_without_saying_who_asked(): void
    {
        $this->entries[] = ['id' => 2, 'project_id' => 40, 'kind' => 'answer', 'parent_id' => 1, 'author_user_id' => null, 'body_md' => 'MIT', 'created_at' => '2026-10-15 11:00:00.000000'];

        $out = cdcf_rest_submission_status_get($this->request(['id' => 40]))->get_data();

        $this->assertSame(40, $out['id']);
        $this->assertSame('Liturgy Helper', $out['title']);
        $this->assertSame('2026-10-01 09:00:00', $out['submitted_at']);
        $this->assertSame('it', $out['language']);
        $this->assertSame('awaiting_submitter', $out['stage']);
        $this->assertSame([[
            'id' => 1, 'body' => 'Which licence?', 'asked_at' => '2026-10-15 10:00:00.000000',
            'answer' => ['body' => 'MIT', 'answered_at' => '2026-10-15 11:00:00.000000'],
        ]], $out['questions']);
        $this->assertNull($out['decision']);
        $this->assertNull($out['public_url']);
    }

    public function test_missing_wrong_or_rotated_tokens_get_the_same_404(): void
    {
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40], null)));
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40], str_repeat('b', 64))));

        $this->meta[40][CDCF_REVIEW_TOKEN_HASH_META] = hash('sha256', str_repeat('c', 64)); // a newer email went out
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40])));
    }

    public function test_expired_token_and_unknown_project_get_the_same_404(): void
    {
        $this->meta[40][CDCF_REVIEW_TOKEN_EXPIRES_META] = time() - 1;
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 40])));
        $this->assertNotFound(cdcf_rest_submission_status_get($this->request(['id' => 41])));
    }

    public function test_decided_status_includes_the_decision_and_public_url(): void
    {
        $this->meta[40][CDCF_REVIEW_STAGE_META]     = 'decided';
        $this->meta[40]['_cdcf_review_decision']    = 'cdcf';
        $this->meta[40]['_cdcf_review_summary']     = 'Welcome aboard.';
        $this->meta[40]['_cdcf_review_decided_at']  = 1760000000;
        Functions\when('cdcf_frontend_path_for')->justReturn('/it/projects/liturgy-helper');

        $out = cdcf_rest_submission_status_get($this->request(['id' => 40]))->get_data();

        $this->assertSame(['outcome' => 'cdcf', 'summary' => 'Welcome aboard.', 'decided_at' => 1760000000], $out['decision']);
        $base = defined('CDCF_FRONTEND_URL') ? rtrim((string) CDCF_FRONTEND_URL, '/') : 'http://localhost:3000';
        $this->assertSame($base . '/it/projects/liturgy-helper', $out['public_url']);
    }

    public function test_reads_are_rate_limited_per_ip_and_project(): void
    {
        for ($i = 0; $i < 120; $i++) {
            cdcf_rest_submission_status_get($this->request(['id' => 40]));
        }
        $result = cdcf_rest_submission_status_get($this->request(['id' => 40]));

        $this->assertSame('rate_limited', $result->get_error_code());
        $this->assertSame(429, $result->get_error_data()['status']);
    }

    // ─── POST answer ───────────────────────────────────────────────────

    public function test_answer_is_saved_and_returns_the_review_to_in_review(): void
    {
        $response = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT licence']));

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['id' => 99, 'question_id' => 1, 'stage' => 'in_review'], $response->get_data());
        $this->assertSame([['project_id' => 40, 'kind' => 'answer', 'parent_id' => 1, 'author_user_id' => null, 'body_md' => 'MIT licence']], $this->inserted);
        $this->assertSame('in_review', $this->meta[40][CDCF_REVIEW_STAGE_META]);
        $this->assertSame([[40, 'submitter_answered', null, 99]], $this->events);
    }

    public function test_stage_stays_awaiting_while_another_question_is_open(): void
    {
        $this->entries[] = ['id' => 3, 'project_id' => 40, 'kind' => 'question', 'parent_id' => null, 'author_user_id' => 8, 'body_md' => 'Who maintains it?', 'created_at' => '2026-10-15 12:00:00.000000'];

        $response = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT']));

        $this->assertSame('awaiting_submitter', $response->get_data()['stage']);
        $this->assertSame('awaiting_submitter', $this->meta[40][CDCF_REVIEW_STAGE_META]);
    }

    public function test_answer_refusals_write_nothing(): void
    {
        $this->entries[] = ['id' => 2, 'project_id' => 40, 'kind' => 'answer', 'parent_id' => 1, 'author_user_id' => null, 'body_md' => 'MIT', 'created_at' => '2026-10-15 11:00:00.000000'];
        $cases = [
            'already_answered'   => [['question_id' => 1, 'body' => 'again'], 409],
            'question_not_found' => [['question_id' => 77, 'body' => 'x'], 404],
            'empty_body'         => [['question_id' => 1, 'body' => "  \n "], 400],
            'body_too_long'      => [['question_id' => 1, 'body' => str_repeat('a', 10001)], 400],
        ];
        foreach ($cases as $code => [$params, $status]) {
            $result = cdcf_rest_submission_status_answer($this->request(['id' => 40] + $params));
            $this->assertSame($code, $result->get_error_code(), $code);
            $this->assertSame($status, $result->get_error_data()['status'], $code);
        }
        $this->assertSame([], $this->inserted);
        $this->assertSame([], $this->events);
    }

    public function test_spam_and_decided_reviews_are_refused(): void
    {
        Functions\when('cdcf_is_spam_content')->justReturn(true);
        $spam = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'buy now']));
        $this->assertSame('answer_rejected', $spam->get_error_code());

        Functions\when('cdcf_is_spam_content')->justReturn(false);
        $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
        $closed = cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT']));
        $this->assertSame('review_closed', $closed->get_error_code());
        $this->assertSame(409, $closed->get_error_data()['status']);
        $this->assertSame([], $this->inserted);
    }

    public function test_answer_needs_a_valid_token(): void
    {
        $this->assertNotFound(cdcf_rest_submission_status_answer($this->request(['id' => 40, 'question_id' => 1, 'body' => 'MIT'], str_repeat('b', 64))));
        $this->assertSame([], $this->inserted);
    }

    // ─── POST resend-link ──────────────────────────────────────────────

    public function test_matching_email_gets_a_new_link_case_insensitively(): void
    {
        $response = cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => ' sam@EXAMPLE.org '], null));

        $this->assertSame(202, $response->get_status());
        $this->assertSame(['accepted' => true], $response->get_data());
        $this->assertSame([[40, 'link']], $this->emails);
    }

    public function test_wrong_email_unknown_project_and_rate_limit_all_look_the_same(): void
    {
        $responses = [
            cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'eve@example.org'], null)),
            cdcf_rest_submission_status_resend_link($this->request(['project_id' => 41, 'email' => 'sam@example.org'], null)),
        ];
        for ($i = 0; $i < 6; $i++) {
            $responses[] = cdcf_rest_submission_status_resend_link($this->request(['project_id' => 40, 'email' => 'sam@example.org'], null));
        }

        foreach ($responses as $response) {
            $this->assertSame(202, $response->get_status());
            $this->assertSame(['accepted' => true], $response->get_data());
        }
        $this->assertCount(5, $this->emails, 'only the five allowed matching requests send');
    }
}
```

- [ ] **Step 3: Run them to verify they fail**

Run: `php8.3 vendor/bin/phpunit tests/SubmissionStatusHandlerTest.php`
Expected: errors, `Call to undefined function cdcf_rest_submission_status_get()`.

- [ ] **Step 4: Implement**

`includes/handlers/submission-status.php`:

```php
<?php
/**
 * Public endpoints for a submitter's status page.
 *
 * Authenticated only by the status token (X-CDCF-Submission-Token), which
 * is never stored in clear. Every token failure — missing, malformed,
 * wrong, rotated, expired, unknown project — is the same 404, so the
 * endpoints reveal nothing about which submissions exist.
 */

defined('ABSPATH') || exit;

const CDCF_SUBMISSION_ANSWER_MAX = 10000;

/**
 * Per IP *and* project: every request reaches WordPress from the Next.js
 * server, so a per-IP key alone would be one bucket shared by everyone.
 */
function cdcf_submission_status_rate_limited(string $bucket, int $project_id, int $limit): bool {
    $ip    = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key   = 'cdcf_sst_' . $bucket . '_' . md5($ip . '|' . $project_id);
    $count = (int) get_transient($key);
    if ($count >= $limit) {
        return true;
    }
    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    return false;
}

function cdcf_submission_status_not_found(): WP_Error {
    return new WP_Error('not_found', 'No submission matches this link.', ['status' => 404]);
}

function cdcf_submission_status_rate_error(): WP_Error {
    return new WP_Error('rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
}

/** @return object|WP_Error The project post when the token is valid. */
function cdcf_submission_status_project(WP_REST_Request $request) {
    $pid     = (int) $request['id'];
    $token   = (string) $request->get_header('x-cdcf-submission-token');
    $project = cdcf_review_get_reviewable_project($pid);
    if (is_wp_error($project) || !cdcf_review_token_matches($pid, $token)) {
        return cdcf_submission_status_not_found();
    }
    return $project;
}

function cdcf_submission_status_public_url(object $project): ?string {
    $path = function_exists('cdcf_frontend_path_for') ? cdcf_frontend_path_for($project) : null;
    if (!is_string($path) || $path === '') {
        return null;
    }
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    return rtrim($frontend, '/') . $path;
}

function cdcf_rest_submission_status_get(WP_REST_Request $request) {
    if (cdcf_submission_status_rate_limited('get', (int) $request['id'], 120)) {
        return cdcf_submission_status_rate_error();
    }
    $project = cdcf_submission_status_project($request);
    if (is_wp_error($project)) {
        return $project;
    }
    $pid = (int) $project->ID;

    // Questions are shown without their author: the submitter hears from
    // "the review committee", never from a named member.
    $questions = [];
    $answers   = [];
    foreach (cdcf_review_project_entries($pid) as $row) {
        if ($row['kind'] === 'question') {
            $questions[(int) $row['id']] = [
                'id' => (int) $row['id'], 'body' => (string) $row['body_md'],
                'asked_at' => (string) $row['created_at'], 'answer' => null,
            ];
        } elseif ($row['kind'] === 'answer') {
            $answers[] = $row;
        }
    }
    foreach ($answers as $answer) {
        $qid = (int) $answer['parent_id'];
        if (isset($questions[$qid]) && $questions[$qid]['answer'] === null) {
            $questions[$qid]['answer'] = ['body' => (string) $answer['body_md'], 'answered_at' => (string) $answer['created_at']];
        }
    }

    $stage = (string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true);
    return rest_ensure_response([
        'id'           => $pid,
        'title'        => (string) $project->post_title,
        'submitted_at' => cdcf_review_submitted_at($project),
        'language'     => cdcf_review_post_language($pid),
        'stage'        => $stage,
        'questions'    => array_values($questions),
        'decision'     => $stage === 'decided' ? [
            'outcome'    => (string) get_post_meta($pid, '_cdcf_review_decision', true),
            'summary'    => (string) get_post_meta($pid, '_cdcf_review_summary', true),
            'decided_at' => (int) get_post_meta($pid, '_cdcf_review_decided_at', true),
        ] : null,
        'public_url'   => cdcf_submission_status_public_url($project),
    ]);
}

function cdcf_rest_submission_status_answer(WP_REST_Request $request) {
    $pid = (int) $request['id'];
    if (cdcf_submission_status_rate_limited('answer', $pid, 20)) {
        return cdcf_submission_status_rate_error();
    }
    $project = cdcf_submission_status_project($request);
    if (is_wp_error($project)) {
        return $project;
    }
    if ((string) get_post_meta($pid, CDCF_REVIEW_STAGE_META, true) === 'decided') {
        return new WP_Error('review_closed', 'This review has been decided and no longer accepts answers.', ['status' => 409]);
    }
    $body = trim((string) $request['body']);
    if ($body === '') {
        return new WP_Error('empty_body', 'Please write an answer.', ['status' => 400]);
    }
    if (mb_strlen($body) > CDCF_SUBMISSION_ANSWER_MAX) {
        return new WP_Error('body_too_long', 'The answer is too long.', ['status' => 400]);
    }
    if (cdcf_is_spam_content($body)) {
        return new WP_Error('answer_rejected', 'This answer could not be accepted. Please rephrase it and try again.', ['status' => 400]);
    }

    $qid       = (int) $request['question_id'];
    $questions = [];
    $answered  = [];
    foreach (cdcf_review_project_entries($pid) as $row) {
        if ($row['kind'] === 'question') {
            $questions[(int) $row['id']] = true;
        } elseif ($row['kind'] === 'answer') {
            $answered[(int) $row['parent_id']] = true;
        }
    }
    if (!isset($questions[$qid])) {
        return new WP_Error('question_not_found', 'That question does not exist.', ['status' => 404]);
    }
    if (isset($answered[$qid])) {
        return new WP_Error('already_answered', 'This question has already been answered.', ['status' => 409]);
    }

    $id = cdcf_review_insert_entry([
        'project_id' => $pid, 'kind' => 'answer', 'parent_id' => $qid,
        'author_user_id' => null, 'body_md' => $body,
    ]);
    if ($id <= 0) {
        return new WP_Error('insert_failed', 'Could not save the answer.', ['status' => 500]);
    }
    $answered[$qid] = true;
    $stage = count(array_diff_key($questions, $answered)) === 0 ? 'in_review' : 'awaiting_submitter';
    update_post_meta($pid, CDCF_REVIEW_STAGE_META, $stage);
    cdcf_review_record_event($pid, 'submitter_answered', null, $id);

    return new WP_REST_Response(['id' => $id, 'question_id' => $qid, 'stage' => $stage], 201);
}

function cdcf_rest_submission_status_resend_link(WP_REST_Request $request) {
    // One answer for every outcome, so the endpoint cannot be used to test
    // which projects exist or which address submitted them.
    $accepted = new WP_REST_Response(['accepted' => true], 202);
    $pid      = (int) $request['project_id'];
    if (cdcf_submission_status_rate_limited('resend', $pid, 5)) {
        return $accepted;
    }
    if (is_wp_error(cdcf_review_get_reviewable_project($pid))) {
        return $accepted;
    }
    $stored = strtolower(trim((string) get_post_meta($pid, '_submission_submitter_email', true)));
    $given  = strtolower(trim((string) $request['email']));
    if ($stored !== '' && hash_equals($stored, $given)) {
        cdcf_review_send_submitter_email($pid, 'link');
    }
    return $accepted;
}
```

In `functions.php`: require the handler with the other handlers
(`require_once __DIR__ . '/includes/handlers/submission-status.php';`) and
register the routes next to `/submit-project`:

```php
add_action('rest_api_init', function () {
    $id = ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'];
    register_rest_route('cdcf/v1', '/submission-status/(?P<id>\d+)', [
        'methods' => 'GET', 'callback' => 'cdcf_rest_submission_status_get',
        'permission_callback' => '__return_true', 'args' => ['id' => $id],
    ]);
    register_rest_route('cdcf/v1', '/submission-status/(?P<id>\d+)/answer', [
        'methods' => 'POST', 'callback' => 'cdcf_rest_submission_status_answer',
        'permission_callback' => '__return_true',
        'args' => [
            'id'          => $id,
            'question_id' => $id,
            'body'        => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
        ],
    ]);
    register_rest_route('cdcf/v1', '/submission-status/resend-link', [
        'methods' => 'POST', 'callback' => 'cdcf_rest_submission_status_resend_link',
        'permission_callback' => '__return_true',
        'args' => [
            'project_id' => $id,
            'email'      => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
        ],
    ]);
});
```

Add `require_once dirname(__DIR__) . '/includes/handlers/submission-status.php';`
(same form as the neighbouring handler requires) to `tests/bootstrap.php`.

- [ ] **Step 5: Run the tests, then the suite**

Run: `php8.3 vendor/bin/phpunit tests/SubmissionStatusHandlerTest.php` — Expected: OK (12 tests).
Run: `php8.3 vendor/bin/phpunit` — Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add wordpress/themes/cdcf-headless/includes/handlers/submission-status.php \
  wordpress/themes/cdcf-headless/tests/SubmissionStatusHandlerTest.php \
  wordpress/themes/cdcf-headless/tests/bootstrap.php wordpress/themes/cdcf-headless/functions.php
git commit -m "feat(review): public submission-status endpoints behind the emailed token"
```

---

### Task 5: Ask the submitter (WordPress)

**Files:**

- Modify: `includes/handlers/review.php` (handler + `cdcf_review_route_args()` key)
- Modify: `functions.php` (routes map, L825-846)
- Create: `tests/ReviewQuestionHandlerTest.php`

**Interfaces:**

- Consumes: `cdcf_review_guard_write()`, `cdcf_review_insert_entry()`,
  `cdcf_review_record_event()`, `cdcf_review_entry_response()`,
  `cdcf_review_send_submitter_email()` (Task 2).
- Produces: `POST /cdcf/v1/review/projects/{id}/question` `{body}` (permission
  `discuss`) → `201` presented entry `+ {warnings: string[]}` (`['email_failed']`
  when the email could not be sent or queued); errors from the write guard
  (`404 review_not_found`, `403 review_locked`, `400 empty_body|body_too_long`)
  and `409 no_submitter_email`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewQuestionHandlerTest extends TestCase
{
    private array $meta = [];
    private array $inserted = [];
    private array $events = [];
    private array $emails = [];
    private string $emailResult = 'queued';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [40 => [CDCF_REVIEW_STAGE_META => 'in_review', '_submission_submitter_email' => 'sam@example.org']];
        $this->inserted = $this->events = $this->emails = [];
        $this->emailResult = 'queued';

        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('is_email')->alias(fn($e) => str_contains((string) $e, '@'));
        Functions\when('get_post')->justReturn((object) ['ID' => 40, 'post_type' => 'project', 'post_status' => 'pending']);
        Functions\when('get_post_meta')->alias(fn(int $id, string $k, bool $s = false) => $this->meta[$id][$k] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v): bool {
            $this->meta[$id][$k] = $v;
            return true;
        });
        Functions\when('cdcf_review_insert_entry')->alias(function (array $row): int {
            $this->inserted[] = $row;
            return 55;
        });
        Functions\when('cdcf_review_record_event')->alias(function (...$args): int {
            $this->events[] = $args;
            return 1;
        });
        Functions\when('cdcf_review_entry_response')->alias(
            fn(int $id, int $status) => new WP_REST_Response(['id' => $id, 'kind' => 'question'], $status)
        );
        Functions\when('cdcf_review_send_submitter_email')->alias(function (int $id, string $t, array $vars = []): string {
            $this->emails[] = [$id, $t, $vars];
            return $this->emailResult;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function ask(string $body, int $id = 40)
    {
        $r = new WP_REST_Request();
        $r->set_param('id', $id);
        $r->set_param('body', $body);
        return cdcf_rest_review_post_question($r);
    }

    public function test_question_is_saved_the_stage_moves_and_the_submitter_is_emailed(): void
    {
        $response = $this->ask('Which licence will you use?');

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['id' => 55, 'kind' => 'question', 'warnings' => []], $response->get_data());
        $this->assertSame([['project_id' => 40, 'kind' => 'question', 'author_user_id' => 7, 'body_md' => 'Which licence will you use?']], $this->inserted);
        $this->assertSame('awaiting_submitter', $this->meta[40][CDCF_REVIEW_STAGE_META]);
        $this->assertSame([[40, 'question_sent', 7, 55]], $this->events);
        $this->assertSame([[40, 'question', ['question' => 'Which licence will you use?']]], $this->emails);
    }

    public function test_an_email_failure_is_a_warning_not_an_error(): void
    {
        $this->emailResult = 'failed';

        $response = $this->ask('Which licence?');

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['email_failed'], $response->get_data()['warnings']);
    }

    public function test_locked_reviews_and_missing_addresses_refuse_before_saving(): void
    {
        $this->meta[40][CDCF_REVIEW_STAGE_META] = 'decided';
        $this->assertSame('review_locked', $this->ask('Q?')->get_error_code());

        $this->meta[40][CDCF_REVIEW_STAGE_META] = 'in_review';
        $this->meta[40]['_submission_submitter_email'] = '';
        $result = $this->ask('Q?');
        $this->assertSame('no_submitter_email', $result->get_error_code());
        $this->assertSame(409, $result->get_error_data()['status']);

        $this->assertSame([], $this->inserted);
        $this->assertSame([], $this->emails);
    }

    public function test_empty_questions_are_refused(): void
    {
        $this->assertSame('empty_body', $this->ask("  \n ")->get_error_code());
        $this->assertSame([], $this->inserted);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php8.3 vendor/bin/phpunit tests/ReviewQuestionHandlerTest.php`
Expected: errors, `Call to undefined function cdcf_rest_review_post_question()`.

- [ ] **Step 3: Implement** — in `includes/handlers/review.php`, after
      `cdcf_rest_review_patch_entry`:

```php
/** POST /review/projects/{id}/question — ask the submitter (emailed verbatim). */
function cdcf_rest_review_post_question(WP_REST_Request $request) {
    $pid  = (int) $request['id'];
    $body = trim((string) $request['body']);
    $ok   = cdcf_review_guard_write($pid, $body);
    if (is_wp_error($ok)) {
        return $ok;
    }
    if (!is_email((string) get_post_meta($pid, '_submission_submitter_email', true))) {
        return new WP_Error('no_submitter_email', 'This submission has no submitter email to send the question to.', ['status' => 409]);
    }
    $uid = get_current_user_id();
    $id  = cdcf_review_insert_entry([
        'project_id' => $pid, 'kind' => 'question', 'author_user_id' => $uid, 'body_md' => $body,
    ]);
    if ($id <= 0) {
        return new WP_Error('insert_failed', 'Could not save the question.', ['status' => 500]);
    }
    update_post_meta($pid, CDCF_REVIEW_STAGE_META, 'awaiting_submitter');
    cdcf_review_record_event($pid, 'question_sent', $uid, $id);

    $email    = cdcf_review_send_submitter_email($pid, 'question', ['question' => $body]);
    $response = cdcf_review_entry_response($id, 201);
    $response->set_data($response->get_data() + [
        'warnings' => in_array($email, ['failed', 'no_email'], true) ? ['email_failed'] : [],
    ]);
    return $response;
}
```

In `cdcf_review_route_args()` add:

```php
        '/review/projects/(?P<id>\d+)/question' => [
            'id'   => $id,
            'body' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
        ],
```

and in `functions.php`'s `$routes` map:

```php
        '/review/projects/(?P<id>\d+)/question'  => ['POST', 'cdcf_rest_review_post_question', 'cdcf_rest_review_permission_discuss'],
```

- [ ] **Step 4: Run the test, then the suite**

Run: `php8.3 vendor/bin/phpunit tests/ReviewQuestionHandlerTest.php` — Expected: OK (4 tests).
Run: `php8.3 vendor/bin/phpunit` — Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add wordpress/themes/cdcf-headless/includes/handlers/review.php wordpress/themes/cdcf-headless/functions.php \
  wordpress/themes/cdcf-headless/tests/ReviewQuestionHandlerTest.php
git commit -m "feat(review): council members can ask the submitter a question"
```

---

### Task 6: Next.js server layer and route handlers

**Files:**

- Create: `lib/submission-status-types.ts`, `lib/submission-token.ts` (+ `.test.ts`),
  `lib/submission-status-api.ts` (+ `.test.ts`), `lib/ip-rate-limit.ts` (+ `.test.ts`)
- Modify: `lib/review-api.ts` (export `getWpRestUrl`; add `askSubmitter`), `lib/review-api.test.ts`
- Create: `app/api/submission-status/[id]/open/route.ts`,
  `app/api/submission-status/[id]/answer/route.ts`,
  `app/api/submission-status/resend-link/route.ts`,
  `app/api/review/projects/[id]/question/route.ts`

**Interfaces:**

- Consumes: Task 4 and Task 5 endpoints.
- Produces:
  - `type SubmissionStage`, `type SubmissionQuestion`, `type SubmissionStatus`, `type AnswerResult` (types file)
  - `STATUS_TOKEN_PATTERN: RegExp`, `statusCookieName(id: number): string`,
    `statusCookieOptions(secure: boolean)`, `statusPagePath(lang: string, id: number): string`
  - `fetchSubmissionStatus(id, token): Promise<SubmissionStatus>`,
    `postSubmissionAnswer(id, token, {question_id, body}): Promise<AnswerResult>`,
    `requestStatusLink({project_id, email}): Promise<void>` — throw `ReviewApiError`
  - `askSubmitter(session, projectId, {body}): Promise<ReviewEntry & {warnings: string[]}>`
  - `createIpRateLimiter(limit, windowMs): (ip: string, now?: number) => boolean` (true = limited), `clientIp(request: Request): string`

- [ ] **Step 1: Types** — `lib/submission-status-types.ts`:

```ts
export type SubmissionStage = "in_review" | "awaiting_submitter" | "decided";

export type SubmissionQuestion = {
  id: number;
  body: string;
  asked_at: string;
  answer: { body: string; answered_at: string } | null;
};

export type SubmissionStatus = {
  id: number;
  title: string;
  submitted_at: string;
  language: string;
  stage: SubmissionStage;
  questions: SubmissionQuestion[];
  decision: {
    outcome: "cdcf" | "community" | "reject";
    summary: string;
    decided_at: number;
  } | null;
  public_url: string | null;
};

export type AnswerResult = {
  id: number;
  question_id: number;
  stage: SubmissionStage;
};
```

- [ ] **Step 2: Write the failing tests**

`lib/submission-token.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import {
  STATUS_TOKEN_PATTERN,
  statusCookieName,
  statusCookieOptions,
  statusPagePath,
} from "./submission-token";

describe("submission token helpers", () => {
  it("accepts only 64 lowercase hex characters", () => {
    expect(STATUS_TOKEN_PATTERN.test("a".repeat(64))).toBe(true);
    expect(STATUS_TOKEN_PATTERN.test("A".repeat(64))).toBe(false);
    expect(STATUS_TOKEN_PATTERN.test("a".repeat(63))).toBe(false);
    expect(STATUS_TOKEN_PATTERN.test(`${"a".repeat(64)}x`)).toBe(false);
  });

  it("names one cookie per project, httpOnly and site-wide", () => {
    expect(statusCookieName(42)).toBe("cdcf_status_42");
    expect(statusCookieOptions(true)).toEqual({
      httpOnly: true,
      secure: true,
      sameSite: "lax",
      path: "/",
      maxAge: 60 * 60 * 24 * 180,
    });
  });

  it("builds the clean page path without the token, in the submission language", () => {
    expect(statusPagePath("en", 42)).toBe("/submission-status/42");
    expect(statusPagePath("it", 42)).toBe("/it/submission-status/42");
    expect(statusPagePath("xx", 42)).toBe("/submission-status/42");
  });
});
```

`lib/ip-rate-limit.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { clientIp, createIpRateLimiter } from "./ip-rate-limit";

describe("createIpRateLimiter", () => {
  it("limits each IP separately within the window", () => {
    const limited = createIpRateLimiter(2, 1000);
    expect(limited("a", 0)).toBe(false);
    expect(limited("a", 10)).toBe(false);
    expect(limited("a", 20)).toBe(true);
    expect(limited("b", 20)).toBe(false);
    expect(limited("a", 1015)).toBe(false);
  });
});

describe("clientIp", () => {
  it("takes the first forwarded address, then x-real-ip", () => {
    const req = (h: Record<string, string>) =>
      new Request("http://x/", { headers: h });
    expect(clientIp(req({ "x-forwarded-for": "203.0.113.5, 10.0.0.1" }))).toBe(
      "203.0.113.5",
    );
    expect(clientIp(req({ "x-real-ip": "203.0.113.6" }))).toBe("203.0.113.6");
    expect(clientIp(req({}))).toBe("unknown");
  });
});
```

`lib/submission-status-api.test.ts`:

```ts
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from "vitest";
import { ReviewApiError } from "./review-api";
import {
  fetchSubmissionStatus,
  postSubmissionAnswer,
  requestStatusLink,
} from "./submission-status-api";

const TOKEN = "a".repeat(64);
let fetchMock: Mock;

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });

beforeEach(() => {
  process.env.WP_REST_URL = "https://wp.example.org/wp-json/";
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => vi.unstubAllGlobals());

describe("submission status client", () => {
  it("sends the token in a header, never in the URL", async () => {
    fetchMock.mockResolvedValue(json(200, { id: 40 }));

    await fetchSubmissionStatus(40, TOKEN);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/submission-status/40",
    );
    expect(url).not.toContain(TOKEN);
    expect(
      (init.headers as Record<string, string>)["X-CDCF-Submission-Token"],
    ).toBe(TOKEN);
    expect(init.cache).toBe("no-store");
  });

  it("posts answers as JSON with the token", async () => {
    fetchMock.mockResolvedValue(
      json(201, { id: 9, question_id: 1, stage: "in_review" }),
    );

    const out = await postSubmissionAnswer(40, TOKEN, {
      question_id: 1,
      body: "MIT",
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/submission-status/40/answer",
    );
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({
      question_id: 1,
      body: "MIT",
    });
    expect(out.stage).toBe("in_review");
  });

  it("requests a new link without any token", async () => {
    fetchMock.mockResolvedValue(json(202, { accepted: true }));

    await requestStatusLink({ project_id: 40, email: "sam@example.org" });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/submission-status/resend-link",
    );
    expect(init.headers as Record<string, string>).not.toHaveProperty(
      "X-CDCF-Submission-Token",
    );
  });

  it("turns WordPress errors into ReviewApiError with code and status", async () => {
    fetchMock.mockResolvedValue(
      json(404, {
        code: "not_found",
        message: "No submission matches this link.",
      }),
    );

    const err = await fetchSubmissionStatus(40, TOKEN).catch((e) => e);

    expect(err).toBeInstanceOf(ReviewApiError);
    expect(err.status).toBe(404);
    expect(err.code).toBe("not_found");
  });
});
```

Add to `lib/review-api.test.ts` (import `askSubmitter`):

```ts
describe("askSubmitter", () => {
  it("posts the question with the bearer token", async () => {
    mockedGetAccessToken.mockReturnValue("tok");
    fetchMock.mockResolvedValue(
      jsonResponse(201, { id: 5, kind: "question", warnings: [] }),
    );

    const out = await askSubmitter(session, 40, { body: "Which licence?" });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(
      "https://wp.example.org/wp-json/cdcf/v1/review/projects/40/question",
    );
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({ body: "Which licence?" });
    expect(out.warnings).toEqual([]);
  });
});
```

(Match the existing file's way of installing `fetchMock`; the snippet uses
its `jsonResponse`, `session` and `mockedGetAccessToken`.)

- [ ] **Step 3: Run them to verify they fail**

Run: `npx vitest run lib/submission-token.test.ts lib/ip-rate-limit.test.ts lib/submission-status-api.test.ts lib/review-api.test.ts`
Expected: module-not-found failures for the three new modules, and `askSubmitter` not exported.

- [ ] **Step 4: Implement**

`lib/submission-token.ts`:

```ts
import { routing } from "@/src/i18n/routing";

/** The raw status token WordPress emails: 64 lowercase hex characters. */
export const STATUS_TOKEN_PATTERN = /^[0-9a-f]{64}$/;

const COOKIE_MAX_AGE = 60 * 60 * 24 * 180;

/**
 * One cookie per submission, readable by the status page under any locale
 * prefix and by its /api routes, hence path "/" and a per-project name.
 */
export function statusCookieName(projectId: number): string {
  return `cdcf_status_${projectId}`;
}

export function statusCookieOptions(secure: boolean) {
  return {
    httpOnly: true,
    secure,
    sameSite: "lax" as const,
    path: "/",
    maxAge: COOKIE_MAX_AGE,
  };
}

/** The status page path, without the token, in the submission language. */
export function statusPagePath(lang: string, projectId: number): string {
  const known = (routing.locales as readonly string[]).includes(lang);
  const prefix = !known || lang === routing.defaultLocale ? "" : `/${lang}`;
  return `${prefix}/submission-status/${projectId}`;
}
```

`lib/ip-rate-limit.ts`:

```ts
/**
 * A per-process sliding-window limiter (the same in-memory approach as the
 * submit-project route). Returns true when `ip` is over the limit.
 */
export function createIpRateLimiter(limit: number, windowMs: number) {
  const hits = new Map<string, number[]>();
  return (ip: string, now: number = Date.now()): boolean => {
    const recent = (hits.get(ip) ?? []).filter((t) => now - t < windowMs);
    if (recent.length >= limit) {
      hits.set(ip, recent);
      return true;
    }
    recent.push(now);
    hits.set(ip, recent);
    return false;
  };
}

export function clientIp(request: Request): string {
  return (
    request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
    request.headers.get("x-real-ip") ||
    "unknown"
  );
}
```

`lib/review-api.ts`: change `function getWpRestUrl()` to
`export function getWpRestUrl()`, and add after `postReviewEntry`:

```ts
export async function askSubmitter(
  session: Session | null,
  projectId: number,
  payload: { body: string },
): Promise<ReviewEntry & { warnings: string[] }> {
  assertId(projectId);
  return wpRequest<ReviewEntry & { warnings: string[] }>(
    session,
    `/cdcf/v1/review/projects/${projectId}/question`,
    { method: "POST", json: payload },
  );
}
```

`lib/submission-status-api.ts`:

```ts
import "server-only";
import { ReviewApiError, getWpRestUrl } from "@/lib/review-api";
import type {
  AnswerResult,
  SubmissionStatus,
} from "@/lib/submission-status-types";

/**
 * Calls to the public submission-status endpoints. The token goes in a
 * header (never the URL, so it stays out of WordPress access logs).
 */
async function statusRequest<T>(
  path: string,
  init: { method?: string; token?: string; json?: unknown } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (init.token) headers["X-CDCF-Submission-Token"] = init.token;
  if (init.json !== undefined) headers["Content-Type"] = "application/json";
  const response = await fetch(`${getWpRestUrl()}${path}`, {
    method: init.method ?? "GET",
    headers,
    body: init.json === undefined ? undefined : JSON.stringify(init.json),
    cache: "no-store",
  });
  const body = (await response.json().catch(() => null)) as {
    code?: string;
    message?: string;
  } | null;
  if (!response.ok) {
    throw new ReviewApiError(
      body?.message ?? `HTTP ${response.status}`,
      response.status,
      body?.code,
    );
  }
  return body as T;
}

export function fetchSubmissionStatus(
  projectId: number,
  token: string,
): Promise<SubmissionStatus> {
  return statusRequest<SubmissionStatus>(
    `/cdcf/v1/submission-status/${projectId}`,
    { token },
  );
}

export function postSubmissionAnswer(
  projectId: number,
  token: string,
  payload: { question_id: number; body: string },
): Promise<AnswerResult> {
  return statusRequest<AnswerResult>(
    `/cdcf/v1/submission-status/${projectId}/answer`,
    { method: "POST", token, json: payload },
  );
}

export async function requestStatusLink(payload: {
  project_id: number;
  email: string;
}): Promise<void> {
  await statusRequest<unknown>("/cdcf/v1/submission-status/resend-link", {
    method: "POST",
    json: payload,
  });
}
```

Route handlers:

`app/api/submission-status/[id]/open/route.ts`:

```ts
import { cookies } from "next/headers";
import { notFound, redirect } from "next/navigation";
import type { NextRequest } from "next/server";
import { parseId } from "@/lib/review-route";
import {
  STATUS_TOKEN_PATTERN,
  statusCookieName,
  statusCookieOptions,
  statusPagePath,
} from "@/lib/submission-token";

/**
 * The emailed link. Moves the token into an httpOnly cookie and redirects
 * to the clean page, so the token leaves the address bar and history.
 */
export async function GET(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) notFound();
  const { searchParams } = request.nextUrl;
  const token = searchParams.get("t") ?? "";
  if (STATUS_TOKEN_PATTERN.test(token)) {
    (await cookies()).set(
      statusCookieName(id),
      token,
      statusCookieOptions(process.env.NODE_ENV === "production"),
    );
  }
  redirect(statusPagePath(searchParams.get("lang") ?? "", id));
}
```

`app/api/submission-status/[id]/answer/route.ts`:

```ts
import { cookies } from "next/headers";
import { NextResponse, type NextRequest } from "next/server";
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
} from "@/lib/review-route";
import { postSubmissionAnswer } from "@/lib/submission-status-api";
import { STATUS_TOKEN_PATTERN, statusCookieName } from "@/lib/submission-token";

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const token = (await cookies()).get(statusCookieName(id))?.value ?? "";
  if (!STATUS_TOKEN_PATTERN.test(token)) {
    return NextResponse.json({ error: "not_found" }, { status: 404 });
  }
  const body = await readJsonObject(request);
  if (!body) return badRequest("invalid_body");
  const { question_id, body: text } = body;
  if (
    typeof question_id !== "number" ||
    !Number.isInteger(question_id) ||
    question_id <= 0
  ) {
    return badRequest("invalid_field", "question_id");
  }
  if (typeof text !== "string") return badRequest("invalid_field", "body");
  try {
    return NextResponse.json(
      await postSubmissionAnswer(id, token, { question_id, body: text }),
      { status: 201 },
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

`app/api/submission-status/resend-link/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { clientIp, createIpRateLimiter } from "@/lib/ip-rate-limit";
import { readJsonObject } from "@/lib/review-route";
import { requestStatusLink } from "@/lib/submission-status-api";

const limited = createIpRateLimiter(5, 60 * 60 * 1000);

/** Always the same answer, as in WordPress, whatever happened. */
export async function POST(request: NextRequest) {
  const accepted = NextResponse.json({ accepted: true }, { status: 202 });
  const body = await readJsonObject(request);
  const { project_id, email } = body ?? {};
  if (
    limited(clientIp(request)) ||
    typeof project_id !== "number" ||
    !Number.isInteger(project_id) ||
    project_id <= 0 ||
    typeof email !== "string" ||
    email.length > 254
  ) {
    return accepted;
  }
  try {
    await requestStatusLink({ project_id, email });
  } catch (err) {
    console.error(
      "[submission-status] resend-link failed",
      err instanceof Error ? err.message : err,
    );
  }
  return accepted;
}
```

`app/api/review/projects/[id]/question/route.ts`:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { auth } from "@/lib/auth";
import { askSubmitter } from "@/lib/review-api";
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from "@/lib/review-route";

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const id = parseId((await params).id);
  if (id === null) return badRequest("invalid_id");
  const session = await auth();
  if (!session?.user) return unauthorized();
  const body = await readJsonObject(request);
  if (!body) return badRequest("invalid_body");
  if (typeof body.body !== "string") return badRequest("invalid_field", "body");
  try {
    return NextResponse.json(
      await askSubmitter(session, id, { body: body.body }),
      {
        status: 201,
      },
    );
  } catch (err) {
    return reviewErrorResponse(err);
  }
}
```

- [ ] **Step 5: Run tests, types and lint**

Run: `npx prettier --single-quote --no-semi --trailing-comma es5 --write lib/submission-*.ts lib/ip-rate-limit*.ts lib/review-api*.ts app/api/submission-status app/api/review/projects/\[id\]/question`
Run: `npx vitest run` — Expected: all pass. `npx tsc --noEmit -p .` and `npm run lint` — Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add lib/submission-status-types.ts lib/submission-token.ts lib/submission-token.test.ts \
  lib/submission-status-api.ts lib/submission-status-api.test.ts lib/ip-rate-limit.ts lib/ip-rate-limit.test.ts \
  lib/review-api.ts lib/review-api.test.ts app/api/submission-status app/api/review/projects/\[id\]/question
git commit -m "feat(review): Next.js server layer for the submission status page and questions"
```

---

### Task 7: The submission status page (Next.js)

**Files:**

- Create: `lib/submission-status-ui.ts` (+ `.test.ts`)
- Create: `components/submission-status/StatusTimeline.tsx`, `AnswerForm.tsx`, `ResendLinkForm.tsx`
- Create: `components/submission-status/AnswerForm.test.tsx`, `ResendLinkForm.test.tsx`
- Create: `app/[lang]/submission-status/[id]/page.tsx`
- Modify: `messages/{en,it,es,fr,pt,de}.json` (new `SubmissionStatus` namespace; `projects.successMessage`)

**Interfaces:**

- Consumes: Task 6 (`fetchSubmissionStatus`, `statusCookieName`, `STATUS_TOKEN_PATTERN`, types).
- Produces: `timelineSteps(status): TimelineStep[]`,
  `answerErrorKey(code?: string): AnswerErrorKey`, `pageMetadata` export from the page.

- [ ] **Step 1: Messages** — add to every locale, as text edits that keep
      each file's existing escaping (never re-serialize the JSON), a top-level
      `SubmissionStatus` object, and update `projects.successMessage`. English:

```json
"SubmissionStatus": {
  "title": "Submission status",
  "submittedOn": "Submitted on {date}",
  "progress": "Progress",
  "steps": {
    "received": "Received",
    "review": "Under review",
    "question": "Question awaiting your reply",
    "decision": "Decision"
  },
  "questionsHeading": "Questions from the CDCF review committee",
  "noQuestions": "The committee has no questions for you at the moment.",
  "yourAnswer": "Your answer",
  "answerLabel": "Your answer",
  "answerSubmit": "Send answer",
  "answerSending": "Sending…",
  "decisionHeading": "Decision",
  "outcome": {
    "cdcf": "Accepted as a CDCF project",
    "community": "Accepted as a community project",
    "reject": "Not accepted"
  },
  "viewPublic": "View the project page",
  "linkInvalid": "This link is not valid any more. Only the most recent link we emailed you works.",
  "resendIntro": "Enter the email address you used when you submitted the project, and we will send a new link to it.",
  "resendEmail": "Email address",
  "resendSubmit": "Email me a new link",
  "resendSending": "Sending…",
  "resendDone": "If that address matches the submission, a new link is on its way.",
  "errors": {
    "alreadyAnswered": "This question has already been answered.",
    "closed": "The review has been decided, so answers are closed.",
    "rejected": "This answer could not be accepted. Please rephrase it and try again.",
    "tooLong": "The answer is too long (10,000 characters at most).",
    "empty": "Please write an answer.",
    "expired": "This link is not valid any more. Reload the page to request a new one.",
    "rateLimited": "Too many attempts. Please try again later.",
    "generic": "Something went wrong. Please try again."
  }
}
```

`projects.successMessage` (en): `"Your project has been submitted for review. We've emailed you a link where you can follow the review."`

Italian:

```json
"SubmissionStatus": {
  "title": "Stato della proposta",
  "submittedOn": "Proposto il {date}",
  "progress": "Avanzamento",
  "steps": { "received": "Ricevuto", "review": "In revisione", "question": "Domanda in attesa della tua risposta", "decision": "Decisione" },
  "questionsHeading": "Domande del comitato di revisione della CDCF",
  "noQuestions": "Al momento il comitato non ha domande per te.",
  "yourAnswer": "La tua risposta",
  "answerLabel": "La tua risposta",
  "answerSubmit": "Invia risposta",
  "answerSending": "Invio in corso…",
  "decisionHeading": "Decisione",
  "outcome": { "cdcf": "Accettato come progetto CDCF", "community": "Accettato come progetto della comunità", "reject": "Non accettato" },
  "viewPublic": "Vai alla pagina del progetto",
  "linkInvalid": "Questo link non è più valido. Funziona solo il link più recente che ti abbiamo inviato.",
  "resendIntro": "Inserisci l'indirizzo email che hai usato per proporre il progetto e ti invieremo un nuovo link.",
  "resendEmail": "Indirizzo email",
  "resendSubmit": "Inviami un nuovo link",
  "resendSending": "Invio in corso…",
  "resendDone": "Se l'indirizzo corrisponde alla proposta, ti stiamo inviando un nuovo link.",
  "errors": {
    "alreadyAnswered": "Questa domanda ha già una risposta.",
    "closed": "La revisione è stata decisa, quindi non si accettano più risposte.",
    "rejected": "Questa risposta non può essere accettata. Riformulala e riprova.",
    "tooLong": "La risposta è troppo lunga (al massimo 10.000 caratteri).",
    "empty": "Scrivi una risposta.",
    "expired": "Questo link non è più valido. Ricarica la pagina per richiederne uno nuovo.",
    "rateLimited": "Troppi tentativi. Riprova più tardi.",
    "generic": "Qualcosa è andato storto. Riprova."
  }
}
```

`projects.successMessage` (it): `"Il tuo progetto è stato inviato per la revisione. Ti abbiamo inviato via email un link per seguirne la revisione."`

Spanish:

```json
"SubmissionStatus": {
  "title": "Estado de la propuesta",
  "submittedOn": "Enviado el {date}",
  "progress": "Progreso",
  "steps": { "received": "Recibido", "review": "En revisión", "question": "Pregunta pendiente de tu respuesta", "decision": "Decisión" },
  "questionsHeading": "Preguntas del comité de revisión de la CDCF",
  "noQuestions": "Por ahora el comité no tiene preguntas para ti.",
  "yourAnswer": "Tu respuesta",
  "answerLabel": "Tu respuesta",
  "answerSubmit": "Enviar respuesta",
  "answerSending": "Enviando…",
  "decisionHeading": "Decisión",
  "outcome": { "cdcf": "Aceptado como proyecto de la CDCF", "community": "Aceptado como proyecto comunitario", "reject": "No aceptado" },
  "viewPublic": "Ver la página del proyecto",
  "linkInvalid": "Este enlace ya no es válido. Solo funciona el enlace más reciente que te enviamos.",
  "resendIntro": "Escribe la dirección de correo que usaste al enviar el proyecto y te enviaremos un enlace nuevo.",
  "resendEmail": "Correo electrónico",
  "resendSubmit": "Envíame un enlace nuevo",
  "resendSending": "Enviando…",
  "resendDone": "Si la dirección coincide con la propuesta, te estamos enviando un enlace nuevo.",
  "errors": {
    "alreadyAnswered": "Esta pregunta ya tiene respuesta.",
    "closed": "La revisión ya tiene una decisión, así que no se aceptan más respuestas.",
    "rejected": "No se ha podido aceptar esta respuesta. Reformúlala e inténtalo de nuevo.",
    "tooLong": "La respuesta es demasiado larga (10.000 caracteres como máximo).",
    "empty": "Escribe una respuesta.",
    "expired": "Este enlace ya no es válido. Recarga la página para pedir uno nuevo.",
    "rateLimited": "Demasiados intentos. Inténtalo más tarde.",
    "generic": "Algo ha salido mal. Inténtalo de nuevo."
  }
}
```

`projects.successMessage` (es): `"Tu proyecto se ha enviado para su revisión. Te hemos enviado por correo un enlace para seguir la revisión."`

French:

```json
"SubmissionStatus": {
  "title": "Suivi de la proposition",
  "submittedOn": "Proposé le {date}",
  "progress": "Avancement",
  "steps": { "received": "Reçu", "review": "En cours d'examen", "question": "Question en attente de votre réponse", "decision": "Décision" },
  "questionsHeading": "Questions du comité d'examen de la CDCF",
  "noQuestions": "Le comité n'a pas de question pour vous pour le moment.",
  "yourAnswer": "Votre réponse",
  "answerLabel": "Votre réponse",
  "answerSubmit": "Envoyer la réponse",
  "answerSending": "Envoi…",
  "decisionHeading": "Décision",
  "outcome": { "cdcf": "Accepté comme projet de la CDCF", "community": "Accepté comme projet communautaire", "reject": "Non retenu" },
  "viewPublic": "Voir la page du projet",
  "linkInvalid": "Ce lien n'est plus valide. Seul le lien le plus récent que nous vous avons envoyé fonctionne.",
  "resendIntro": "Saisissez l'adresse e-mail utilisée pour proposer le projet, et nous y enverrons un nouveau lien.",
  "resendEmail": "Adresse e-mail",
  "resendSubmit": "M'envoyer un nouveau lien",
  "resendSending": "Envoi…",
  "resendDone": "Si l'adresse correspond à la proposition, un nouveau lien est en route.",
  "errors": {
    "alreadyAnswered": "Cette question a déjà reçu une réponse.",
    "closed": "L'examen est terminé : les réponses sont closes.",
    "rejected": "Cette réponse n'a pas pu être acceptée. Reformulez-la et réessayez.",
    "tooLong": "La réponse est trop longue (10 000 caractères au maximum).",
    "empty": "Veuillez écrire une réponse.",
    "expired": "Ce lien n'est plus valide. Rechargez la page pour en demander un nouveau.",
    "rateLimited": "Trop de tentatives. Veuillez réessayer plus tard.",
    "generic": "Une erreur s'est produite. Veuillez réessayer."
  }
}
```

`projects.successMessage` (fr): `"Votre projet a été soumis à l'examen. Nous vous avons envoyé par e-mail un lien pour suivre l'examen."`

Portuguese:

```json
"SubmissionStatus": {
  "title": "Estado da submissão",
  "submittedOn": "Submetido em {date}",
  "progress": "Progresso",
  "steps": { "received": "Recebido", "review": "Em revisão", "question": "Pergunta à espera da sua resposta", "decision": "Decisão" },
  "questionsHeading": "Perguntas do comité de revisão da CDCF",
  "noQuestions": "De momento, o comité não tem perguntas para si.",
  "yourAnswer": "A sua resposta",
  "answerLabel": "A sua resposta",
  "answerSubmit": "Enviar resposta",
  "answerSending": "A enviar…",
  "decisionHeading": "Decisão",
  "outcome": { "cdcf": "Aceite como projeto CDCF", "community": "Aceite como projeto da comunidade", "reject": "Não aceite" },
  "viewPublic": "Ver a página do projeto",
  "linkInvalid": "Este link já não é válido. Só funciona o link mais recente que lhe enviámos.",
  "resendIntro": "Indique o endereço de email que usou ao submeter o projeto e enviaremos um novo link para esse endereço.",
  "resendEmail": "Endereço de email",
  "resendSubmit": "Enviar-me um novo link",
  "resendSending": "A enviar…",
  "resendDone": "Se o endereço corresponder à submissão, está a caminho um novo link.",
  "errors": {
    "alreadyAnswered": "Esta pergunta já tem resposta.",
    "closed": "A revisão já foi decidida, por isso não são aceites mais respostas.",
    "rejected": "Não foi possível aceitar esta resposta. Reformule-a e tente de novo.",
    "tooLong": "A resposta é demasiado longa (no máximo 10 000 caracteres).",
    "empty": "Escreva uma resposta.",
    "expired": "Este link já não é válido. Recarregue a página para pedir um novo.",
    "rateLimited": "Demasiadas tentativas. Tente mais tarde.",
    "generic": "Algo correu mal. Tente de novo."
  }
}
```

`projects.successMessage` (pt): `"O seu projeto foi submetido para revisão. Enviámos-lhe por email um link para acompanhar a revisão."`

German (formal "Sie", matching the existing review strings):

```json
"SubmissionStatus": {
  "title": "Status der Einreichung",
  "submittedOn": "Eingereicht am {date}",
  "progress": "Fortschritt",
  "steps": { "received": "Eingegangen", "review": "In Prüfung", "question": "Frage wartet auf Ihre Antwort", "decision": "Entscheidung" },
  "questionsHeading": "Fragen des Prüfungsausschusses der CDCF",
  "noQuestions": "Der Ausschuss hat derzeit keine Fragen an Sie.",
  "yourAnswer": "Ihre Antwort",
  "answerLabel": "Ihre Antwort",
  "answerSubmit": "Antwort senden",
  "answerSending": "Wird gesendet…",
  "decisionHeading": "Entscheidung",
  "outcome": { "cdcf": "Als CDCF-Projekt angenommen", "community": "Als Community-Projekt angenommen", "reject": "Nicht angenommen" },
  "viewPublic": "Zur Projektseite",
  "linkInvalid": "Dieser Link ist nicht mehr gültig. Nur der neueste Link, den wir Ihnen geschickt haben, funktioniert.",
  "resendIntro": "Geben Sie die E-Mail-Adresse ein, mit der Sie das Projekt eingereicht haben, und wir senden einen neuen Link an diese Adresse.",
  "resendEmail": "E-Mail-Adresse",
  "resendSubmit": "Neuen Link senden",
  "resendSending": "Wird gesendet…",
  "resendDone": "Wenn die Adresse zur Einreichung passt, ist ein neuer Link unterwegs.",
  "errors": {
    "alreadyAnswered": "Diese Frage wurde bereits beantwortet.",
    "closed": "Über die Einreichung wurde entschieden; Antworten sind nicht mehr möglich.",
    "rejected": "Diese Antwort konnte nicht angenommen werden. Bitte formulieren Sie sie um und versuchen Sie es erneut.",
    "tooLong": "Die Antwort ist zu lang (höchstens 10.000 Zeichen).",
    "empty": "Bitte schreiben Sie eine Antwort.",
    "expired": "Dieser Link ist nicht mehr gültig. Laden Sie die Seite neu, um einen neuen anzufordern.",
    "rateLimited": "Zu viele Versuche. Bitte versuchen Sie es später erneut.",
    "generic": "Etwas ist schiefgelaufen. Bitte versuchen Sie es erneut."
  }
}
```

`projects.successMessage` (de): `"Ihr Projekt wurde zur Prüfung eingereicht. Wir haben Ihnen per E-Mail einen Link geschickt, über den Sie die Prüfung verfolgen können."`

Check: `for l in en it es fr pt de; do python3 -c "import json;json.load(open('messages/$l.json'))"; done` — Expected: no output.

- [ ] **Step 2: Write the failing tests**

`lib/submission-status-ui.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { answerErrorKey, timelineSteps } from "./submission-status-ui";

describe("timelineSteps", () => {
  it("shows review in progress after receipt", () => {
    expect(timelineSteps({ stage: "in_review" })).toEqual([
      { key: "received", state: "done" },
      { key: "review", state: "current" },
      { key: "decision", state: "upcoming" },
    ]);
  });

  it("adds the question step while a reply is awaited", () => {
    expect(timelineSteps({ stage: "awaiting_submitter" })).toEqual([
      { key: "received", state: "done" },
      { key: "review", state: "done" },
      { key: "question", state: "current" },
      { key: "decision", state: "upcoming" },
    ]);
  });

  it("marks everything done once decided", () => {
    expect(timelineSteps({ stage: "decided" })).toEqual([
      { key: "received", state: "done" },
      { key: "review", state: "done" },
      { key: "decision", state: "done" },
    ]);
  });
});

describe("answerErrorKey", () => {
  it("maps WordPress codes to messages and defaults to generic", () => {
    expect(answerErrorKey("already_answered")).toBe("alreadyAnswered");
    expect(answerErrorKey("review_closed")).toBe("closed");
    expect(answerErrorKey("answer_rejected")).toBe("rejected");
    expect(answerErrorKey("body_too_long")).toBe("tooLong");
    expect(answerErrorKey("empty_body")).toBe("empty");
    expect(answerErrorKey("not_found")).toBe("expired");
    expect(answerErrorKey("rate_limited")).toBe("rateLimited");
    expect(answerErrorKey("something_else")).toBe("generic");
    expect(answerErrorKey(undefined)).toBe("generic");
  });
});
```

`components/submission-status/AnswerForm.test.tsx`:

```tsx
// @vitest-environment happy-dom
import { act } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const refresh = vi.fn();
vi.mock("@/src/i18n/navigation", () => ({ useRouter: () => ({ refresh }) }));

import { AnswerForm } from "./AnswerForm";
import {
  buttonByText,
  renderDom,
  typeInto,
} from "@/components/testing/renderDom";

let unmount = () => {};
let fetchMock: ReturnType<typeof vi.fn>;
beforeEach(() => {
  refresh.mockReset();
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => {
  unmount();
  vi.unstubAllGlobals();
});

async function submit(container: HTMLElement, text: string) {
  typeInto(container.querySelector("textarea"), text);
  await act(async () => {
    buttonByText(container, "Send answer")?.click();
  });
}

describe("AnswerForm", () => {
  it("posts the answer and refreshes the page", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ stage: "in_review" }), { status: 201 }),
    );
    const r = renderDom(<AnswerForm projectId={40} questionId={1} />);
    unmount = r.unmount;

    await submit(r.container, "MIT licence");

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/submission-status/40/answer");
    expect(JSON.parse(init.body as string)).toEqual({
      question_id: 1,
      body: "MIT licence",
    });
    expect(refresh).toHaveBeenCalled();
  });

  it("shows why an answer was refused and keeps the text", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: "answer_rejected" }), {
        status: 400,
      }),
    );
    const r = renderDom(<AnswerForm projectId={40} questionId={1} />);
    unmount = r.unmount;

    await submit(r.container, "buy now");

    expect(r.container.textContent).toContain("could not be accepted");
    expect(r.container.querySelector("textarea")?.value).toBe("buy now");
    expect(refresh).not.toHaveBeenCalled();
  });
});
```

`components/submission-status/ResendLinkForm.test.tsx`:

```tsx
// @vitest-environment happy-dom
import { act } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ResendLinkForm } from "./ResendLinkForm";
import {
  buttonByText,
  renderDom,
  typeInto,
} from "@/components/testing/renderDom";

afterEach(() => vi.unstubAllGlobals());

describe("ResendLinkForm", () => {
  it("always shows the same neutral confirmation", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response("{}", { status: 202 }));
    vi.stubGlobal("fetch", fetchMock);
    const r = renderDom(<ResendLinkForm projectId={40} />);

    typeInto(
      r.container.querySelector('input[type="email"]'),
      "sam@example.org",
    );
    await act(async () => {
      buttonByText(r.container, "Email me a new link")?.click();
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/submission-status/resend-link");
    expect(JSON.parse(init.body as string)).toEqual({
      project_id: 40,
      email: "sam@example.org",
    });
    expect(r.container.textContent).toContain(
      "If that address matches the submission",
    );
    r.unmount();
  });
});
```

Add a metadata test, `lib/submission-status-page.test.ts`:

```ts
import { describe, expect, it, vi } from "vitest";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));
vi.mock("@/lib/submission-status-api", () => ({
  fetchSubmissionStatus: vi.fn(),
}));

import { metadata } from "@/app/[lang]/submission-status/[id]/page";

describe("submission status page metadata", () => {
  it("is never indexed and sends no referrer", () => {
    expect(metadata.robots).toEqual({ index: false, follow: false });
    expect(metadata.referrer).toBe("no-referrer");
  });
});
```

- [ ] **Step 3: Run them to verify they fail**

Run: `npx vitest run lib/submission-status-ui.test.ts lib/submission-status-page.test.ts components/submission-status`
Expected: module-not-found failures.

- [ ] **Step 4: Implement the helpers** — `lib/submission-status-ui.ts`:

```ts
import type { SubmissionStatus } from "@/lib/submission-status-types";

export type TimelineStep = {
  key: "received" | "review" | "question" | "decision";
  state: "done" | "current" | "upcoming";
};

export function timelineSteps(
  status: Pick<SubmissionStatus, "stage">,
): TimelineStep[] {
  const decided = status.stage === "decided";
  const awaiting = status.stage === "awaiting_submitter";
  const steps: TimelineStep[] = [
    { key: "received", state: "done" },
    { key: "review", state: decided || awaiting ? "done" : "current" },
  ];
  if (awaiting) steps.push({ key: "question", state: "current" });
  steps.push({ key: "decision", state: decided ? "done" : "upcoming" });
  return steps;
}

export type AnswerErrorKey =
  | "alreadyAnswered"
  | "closed"
  | "rejected"
  | "tooLong"
  | "empty"
  | "expired"
  | "rateLimited"
  | "generic";

const ANSWER_ERRORS: Record<string, AnswerErrorKey> = {
  already_answered: "alreadyAnswered",
  review_closed: "closed",
  answer_rejected: "rejected",
  body_too_long: "tooLong",
  empty_body: "empty",
  not_found: "expired",
  rate_limited: "rateLimited",
};

export function answerErrorKey(code: string | undefined): AnswerErrorKey {
  return (code && ANSWER_ERRORS[code]) || "generic";
}
```

- [ ] **Step 5: Implement the components**

`components/submission-status/StatusTimeline.tsx`:

```tsx
import clsx from "clsx";
import { useTranslations } from "next-intl";
import type { TimelineStep } from "@/lib/submission-status-ui";

export function StatusTimeline({ steps }: { steps: TimelineStep[] }) {
  const t = useTranslations("SubmissionStatus");
  return (
    <ol
      aria-label={t("progress")}
      className="flex flex-wrap gap-x-6 gap-y-2 text-sm"
    >
      {steps.map((s) => (
        <li
          key={s.key}
          aria-current={s.state === "current" ? "step" : undefined}
          className={clsx(
            "flex items-center gap-2",
            s.state === "upcoming" ? "text-gray-400" : "text-gray-800",
            s.state === "current" && "font-semibold text-cdcf-navy",
          )}
        >
          <span
            aria-hidden="true"
            className={clsx(
              "inline-block h-2.5 w-2.5 rounded-full",
              s.state === "done" && "bg-cdcf-navy",
              s.state === "current" && "bg-cdcf-gold ring-2 ring-cdcf-navy",
              s.state === "upcoming" && "bg-gray-300",
            )}
          />
          {t(`steps.${s.key}`)}
        </li>
      ))}
    </ol>
  );
}
```

(If `bg-cdcf-gold` is not a defined brand colour, use the colour the
"Dona"/primary button uses in `css/globals.css`.)

`components/submission-status/AnswerForm.tsx`:

```tsx
"use client";

import { useId, useState, type FormEvent } from "react";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { useRouter } from "@/src/i18n/navigation";
import {
  answerErrorKey,
  type AnswerErrorKey,
} from "@/lib/submission-status-ui";

export function AnswerForm({
  projectId,
  questionId,
}: {
  projectId: number;
  questionId: number;
}) {
  const t = useTranslations("SubmissionStatus");
  const router = useRouter();
  const id = useId();
  const [body, setBody] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<AnswerErrorKey | null>(null);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`/api/submission-status/${projectId}/answer`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ question_id: questionId, body }),
      });
      if (res.ok) {
        router.refresh();
        return;
      }
      const data = (await res.json().catch(() => null)) as {
        error?: string;
      } | null;
      setError(answerErrorKey(data?.error));
    } catch {
      setError("generic");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-2">
      <label
        htmlFor={id}
        className="block text-xs font-medium uppercase tracking-wide text-gray-500"
      >
        {t("answerLabel")}
      </label>
      <textarea
        id={id}
        value={body}
        onChange={(e) => setBody(e.target.value)}
        rows={5}
        maxLength={10000}
        disabled={busy}
        className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
      />
      <button
        type="submit"
        disabled={busy || !body.trim()}
        className={clsx(
          "cdcf-btn-primary px-4 py-2 text-sm",
          (busy || !body.trim()) && "cursor-not-allowed opacity-60",
        )}
      >
        {busy ? t("answerSending") : t("answerSubmit")}
      </button>
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {t(`errors.${error}`)}
        </p>
      )}
    </form>
  );
}
```

`components/submission-status/ResendLinkForm.tsx`:

```tsx
"use client";

import { useId, useState, type FormEvent } from "react";
import { useTranslations } from "next-intl";

export function ResendLinkForm({ projectId }: { projectId: number }) {
  const t = useTranslations("SubmissionStatus");
  const id = useId();
  const [email, setEmail] = useState("");
  const [state, setState] = useState<"idle" | "busy" | "done">("idle");

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setState("busy");
    try {
      await fetch("/api/submission-status/resend-link", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ project_id: projectId, email }),
      });
    } catch {
      // The answer is the same whatever happened.
    }
    setState("done");
  }

  if (state === "done") {
    return (
      <p role="status" className="text-sm text-gray-700">
        {t("resendDone")}
      </p>
    );
  }
  return (
    <form onSubmit={onSubmit} className="space-y-2">
      <p className="text-sm text-gray-700">{t("resendIntro")}</p>
      <label
        htmlFor={id}
        className="block text-xs font-medium uppercase tracking-wide text-gray-500"
      >
        {t("resendEmail")}
      </label>
      <input
        id={id}
        type="email"
        required
        autoComplete="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
      />
      <button
        type="submit"
        disabled={state === "busy"}
        className="cdcf-btn-primary px-4 py-2 text-sm"
      >
        {state === "busy" ? t("resendSending") : t("resendSubmit")}
      </button>
    </form>
  );
}
```

- [ ] **Step 6: Implement the page** — `app/[lang]/submission-status/[id]/page.tsx`:

```tsx
import type { Metadata } from "next";
import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import { getFormatter, getTranslations } from "next-intl/server";
import { ReviewApiError } from "@/lib/review-api";
import { parseWpGmt } from "@/lib/review-ui";
import { fetchSubmissionStatus } from "@/lib/submission-status-api";
import type { SubmissionStatus } from "@/lib/submission-status-types";
import { timelineSteps } from "@/lib/submission-status-ui";
import { STATUS_TOKEN_PATTERN, statusCookieName } from "@/lib/submission-token";
import { AnswerForm } from "@/components/submission-status/AnswerForm";
import { ResendLinkForm } from "@/components/submission-status/ResendLinkForm";
import { StatusTimeline } from "@/components/submission-status/StatusTimeline";

/** Private to the submitter: never indexed, and no Referer to linked pages. */
export const metadata: Metadata = {
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default async function SubmissionStatusPage({
  params,
}: {
  params: Promise<{ lang: string; id: string }>;
}) {
  const { id: rawId } = await params;
  if (!/^[1-9]\d{0,15}$/.test(rawId)) notFound();
  const id = Number(rawId);
  const t = await getTranslations("SubmissionStatus");
  const token = (await cookies()).get(statusCookieName(id))?.value ?? "";
  const status = await loadStatus(id, token);

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-2xl space-y-8">
        <h1 className="cdcf-heading text-3xl">{t("title")}</h1>
        {status ? (
          <StatusBody status={status} />
        ) : (
          <section className="cdcf-card space-y-4">
            <p className="text-sm text-gray-700">{t("linkInvalid")}</p>
            <ResendLinkForm projectId={id} />
          </section>
        )}
      </div>
    </main>
  );
}

// Loading stays outside the component so no JSX is built inside try/catch.
async function loadStatus(
  id: number,
  token: string,
): Promise<SubmissionStatus | null> {
  if (!STATUS_TOKEN_PATTERN.test(token)) return null;
  try {
    return await fetchSubmissionStatus(id, token);
  } catch (err) {
    if (err instanceof ReviewApiError && err.status === 404) return null;
    throw err;
  }
}

async function StatusBody({ status }: { status: SubmissionStatus }) {
  const t = await getTranslations("SubmissionStatus");
  const format = await getFormatter();
  const open = status.stage !== "decided";
  return (
    <>
      <section className="cdcf-card space-y-4">
        <h2 className="cdcf-heading text-2xl">{status.title}</h2>
        <p className="text-sm text-gray-600">
          {t("submittedOn", {
            date: format.dateTime(parseWpGmt(status.submitted_at), {
              dateStyle: "long",
            }),
          })}
        </p>
        <StatusTimeline steps={timelineSteps(status)} />
      </section>

      <section className="space-y-4">
        <h2 className="cdcf-heading text-xl">{t("questionsHeading")}</h2>
        {status.questions.length === 0 ? (
          <p className="text-sm text-gray-500">{t("noQuestions")}</p>
        ) : (
          <ul className="space-y-4">
            {status.questions.map((q) => (
              <li key={q.id} className="cdcf-card space-y-3">
                <p className="whitespace-pre-line text-sm text-gray-800">
                  {q.body}
                </p>
                {q.answer ? (
                  <div className="border-l-2 border-cdcf-navy pl-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                      {t("yourAnswer")}
                    </p>
                    <p className="whitespace-pre-line text-sm text-gray-800">
                      {q.answer.body}
                    </p>
                  </div>
                ) : (
                  open && <AnswerForm projectId={status.id} questionId={q.id} />
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      {status.decision && (
        <section className="cdcf-card space-y-3">
          <h2 className="cdcf-heading text-xl">{t("decisionHeading")}</h2>
          <p className="font-semibold text-gray-900">
            {t(`outcome.${status.decision.outcome}`)}
          </p>
          {status.decision.summary && (
            <p className="whitespace-pre-line text-sm text-gray-800">
              {status.decision.summary}
            </p>
          )}
          {status.public_url && (
            <a
              href={status.public_url}
              className="text-sm font-medium text-cdcf-navy hover:underline"
            >
              {t("viewPublic")}
            </a>
          )}
        </section>
      )}
    </>
  );
}
```

- [ ] **Step 7: Run tests, types, lint, build**

Run: `npx prettier --single-quote --no-semi --trailing-comma es5 --write lib/submission-status-ui*.ts lib/submission-status-page.test.ts components/submission-status app/\[lang\]/submission-status`
Run: `npx vitest run` — Expected: all pass. `npx tsc --noEmit -p .`, `npm run lint`, `npm run build` — Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add lib/submission-status-ui.ts lib/submission-status-ui.test.ts lib/submission-status-page.test.ts \
  components/submission-status app/\[lang\]/submission-status messages/*.json
git commit -m "feat(review): submission status page for submitters"
```

---

### Task 8: "Ask the submitter" in the workspace (Next.js)

**Files:**

- Create: `components/review/AskSubmitter.tsx`, `components/review/AskSubmitter.test.tsx`
- Modify: `components/review/QuestionsList.tsx` (questions as plain text)
- Modify: `app/[lang]/review/[id]/page.tsx` (Questions section)
- Modify: `messages/*.json` (`Review.questions.*` additions)

**Interfaces:**

- Consumes: `POST /api/review/projects/{id}/question` (Task 6), `reviewFetch`, `jsonInit`.
- Produces: `<AskSubmitter projectId={number} />`.

- [ ] **Step 1: Messages** — add inside `Review.questions` in every locale
      (text edits; keep existing escaping):

| key           | en                                                                                                                    | it                                                                                                                                           | es                                                                                                                              | fr                                                                                                                           | pt                                                                                                                               | de                                                                                                                        |
| ------------- | --------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `askHeading`  | Ask the submitter                                                                                                     | Fai una domanda al proponente                                                                                                                | Pregunta al proponente                                                                                                          | Poser une question au proposant                                                                                              | Perguntar ao proponente                                                                                                          | Den Einreichenden fragen                                                                                                  |
| `askLabel`    | Question                                                                                                              | Domanda                                                                                                                                      | Pregunta                                                                                                                        | Question                                                                                                                     | Pergunta                                                                                                                         | Frage                                                                                                                     |
| `askWarning`  | The submitter receives this text by email exactly as written, from "the CDCF review committee" and without your name. | Il proponente riceve questo testo via email esattamente come è scritto, da parte del "comitato di revisione della CDCF" e senza il tuo nome. | El proponente recibe este texto por correo tal como está escrito, de parte del «comité de revisión de la CDCF» y sin tu nombre. | Le proposant reçoit ce texte par e-mail tel qu'il est écrit, de la part du « comité d'examen de la CDCF » et sans votre nom. | O proponente recebe este texto por email exatamente como está escrito, da parte do "comité de revisão da CDCF" e sem o seu nome. | Die einreichende Person erhält diesen Text unverändert per E-Mail, vom „Prüfungsausschuss der CDCF“ und ohne Ihren Namen. |
| `askSubmit`   | Send question                                                                                                         | Invia domanda                                                                                                                                | Enviar pregunta                                                                                                                 | Envoyer la question                                                                                                          | Enviar pergunta                                                                                                                  | Frage senden                                                                                                              |
| `askSending`  | Sending…                                                                                                              | Invio in corso…                                                                                                                              | Enviando…                                                                                                                       | Envoi…                                                                                                                       | A enviar…                                                                                                                        | Wird gesendet…                                                                                                            |
| `emailFailed` | The question was saved, but the email to the submitter could not be sent.                                             | La domanda è stata salvata, ma non è stato possibile inviare l'email al proponente.                                                          | La pregunta se ha guardado, pero no se ha podido enviar el correo al proponente.                                                | La question a été enregistrée, mais l'e-mail au proposant n'a pas pu être envoyé.                                            | A pergunta foi guardada, mas não foi possível enviar o email ao proponente.                                                      | Die Frage wurde gespeichert, aber die E-Mail an die einreichende Person konnte nicht gesendet werden.                     |

- [ ] **Step 2: Write the failing test** — `components/review/AskSubmitter.test.tsx`:

```tsx
// @vitest-environment happy-dom
import { act } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const refresh = vi.fn();
vi.mock("@/src/i18n/navigation", () => ({ useRouter: () => ({ refresh }) }));

import { AskSubmitter } from "./AskSubmitter";
import {
  buttonByText,
  renderDom,
  typeInto,
} from "@/components/testing/renderDom";

let unmount = () => {};
let fetchMock: ReturnType<typeof vi.fn>;
beforeEach(() => {
  refresh.mockReset();
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => {
  unmount();
  vi.unstubAllGlobals();
});

async function ask(text: string) {
  const r = renderDom(<AskSubmitter projectId={40} />);
  unmount = r.unmount;
  typeInto(r.container.querySelector("textarea"), text);
  await act(async () => {
    buttonByText(r.container, "Send question")?.click();
  });
  return r.container;
}

describe("AskSubmitter", () => {
  it("warns that the text is emailed, posts it, clears and refreshes", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 5, warnings: [] }), { status: 201 }),
    );

    const c = await ask("Which licence?");

    expect(c.textContent).toContain("exactly as written");
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/review/projects/40/question");
    expect(JSON.parse(init.body as string)).toEqual({ body: "Which licence?" });
    expect(c.querySelector("textarea")?.value).toBe("");
    expect(refresh).toHaveBeenCalled();
  });

  it("tells the reviewer when the email could not be sent", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 5, warnings: ["email_failed"] }), {
        status: 201,
      }),
    );

    const c = await ask("Which licence?");

    expect(c.textContent).toContain("could not be sent");
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `npx vitest run components/review/AskSubmitter.test.tsx` — Expected: module-not-found failure.

- [ ] **Step 4: Implement** — `components/review/AskSubmitter.tsx`:

```tsx
"use client";

import { useId, useState, type FormEvent } from "react";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { jsonInit, reviewFetch } from "@/lib/review-client";
import type { ReviewEntry } from "@/lib/review-types";
import type { ReviewErrorKey } from "@/lib/review-ui";
import { useRouter } from "@/src/i18n/navigation";

/** Plain text: the submitter receives it by email exactly as written. */
export function AskSubmitter({ projectId }: { projectId: number }) {
  const t = useTranslations("Review");
  const router = useRouter();
  const id = useId();
  const [body, setBody] = useState("");
  const [busy, setBusy] = useState(false);
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null);
  const [emailFailed, setEmailFailed] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrorKey(null);
    setEmailFailed(false);
    const result = await reviewFetch<ReviewEntry & { warnings: string[] }>(
      `/api/review/projects/${projectId}/question`,
      jsonInit("POST", { body }),
    );
    setBusy(false);
    if (!result.ok) {
      setErrorKey(result.errorKey);
      return;
    }
    setBody("");
    setEmailFailed(result.data.warnings.includes("email_failed"));
    router.refresh();
  }

  return (
    <form onSubmit={onSubmit} className="cdcf-card space-y-3">
      <h3 className="cdcf-heading text-lg">{t("questions.askHeading")}</h3>
      <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
        {t("questions.askWarning")}
      </p>
      <label
        htmlFor={id}
        className="block text-xs font-medium uppercase tracking-wide text-gray-500"
      >
        {t("questions.askLabel")}
      </label>
      <textarea
        id={id}
        value={body}
        onChange={(e) => setBody(e.target.value)}
        rows={4}
        disabled={busy}
        className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
      />
      <button
        type="submit"
        disabled={busy || !body.trim()}
        className={clsx(
          "cdcf-btn-primary px-4 py-2 text-sm",
          (busy || !body.trim()) && "cursor-not-allowed opacity-60",
        )}
      >
        {busy ? t("questions.askSending") : t("questions.askSubmit")}
      </button>
      {errorKey && (
        <p role="alert" className="text-sm text-red-700">
          {t(`errors.${errorKey}`)}
        </p>
      )}
      {emailFailed && (
        <p role="status" className="text-sm text-amber-800">
          {t("questions.emailFailed")}
        </p>
      )}
    </form>
  );
}
```

(`errors.${errorKey}` uses the existing `Review.errors` keys that
`ComposerStatus` already renders; if `ReviewErrorKey` includes a key
without a message there, reuse `ComposerStatus` instead of the inline
paragraph.)

`components/review/QuestionsList.tsx`: questions are plain text now (they
are emailed verbatim), so replace `<MarkdownView source={q.body_md} />` with
`<p className="whitespace-pre-line text-sm text-gray-800">{q.body_md}</p>`,
drop the `MarkdownView` import, and update the doc comment ("Questions and
answers are plain text.").

`app/[lang]/review/[id]/page.tsx`, Questions section:

```tsx
<section className="space-y-4">
  <h2 className="cdcf-heading text-2xl">{t("questions.heading")}</h2>
  <QuestionsList questions={project.questions} />
  {me.can.discuss && !locked && <AskSubmitter projectId={project.id} />}
</section>
```

with `import { AskSubmitter } from '@/components/review/AskSubmitter'`.

- [ ] **Step 5: Run tests, types, lint**

Run: `npx prettier --single-quote --no-semi --trailing-comma es5 --write components/review/AskSubmitter*.tsx components/review/QuestionsList.tsx app/\[lang\]/review/\[id\]/page.tsx`
Run: `npx vitest run`, `npx tsc --noEmit -p .`, `npm run lint` — Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add components/review/AskSubmitter.tsx components/review/AskSubmitter.test.tsx \
  components/review/QuestionsList.tsx app/\[lang\]/review/\[id\]/page.tsx messages/*.json
git commit -m "feat(review): ask the submitter from the review workspace"
```

---

### Task 9: Documentation, local walkthrough, PR

**Files:**

- Modify: `AGENTS.md`

- [ ] **Step 1: AGENTS.md** — add rows to the `cdcf/v1` table:

| Method | Route                            | Description                                                                                                                                                                                                   |
| ------ | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `POST` | `/review/projects/{id}/question` | Ask the submitter (`body`, plain text, emailed verbatim). Sets stage `awaiting_submitter`, rotates the status token and emails the fresh link. `warnings: ["email_failed"]` when the email could not be sent. |
| `GET`  | `/submission-status/{id}`        | Public, token in `X-CDCF-Submission-Token`. Stage, unattributed questions with answers, decision and public URL when decided. Every token failure is a generic `404`.                                         |
| `POST` | `/submission-status/{id}/answer` | Public, token header. `question_id`, `body` (≤ 10,000 chars, plain text). One answer per question; `409` once decided.                                                                                        |
| `POST` | `/submission-status/resend-link` | Public. `project_id`, `email`; emails a new link only when the address matches. Always `202`.                                                                                                                 |

and a paragraph in "Council review":

> Submitters follow their review at `/[lang]/submission-status/[id]` with no
> account. Every submitter email (`includes/review/email-strings.php`, six
> languages) rotates a random token stored only as a SHA-256 hash and links
> to `/api/submission-status/{id}/open`, which moves the token into an
> `httpOnly` cookie (`cdcf_status_{id}`) and redirects to the clean page.
> Review emails go through the Redis queue (`cdcf_review_email` job) when
> the queue plugin is active, otherwise inline — never WP-Cron.

Run: `npm run format:md && npm run lint:md` — Expected: clean.

- [ ] **Step 2: Local walkthrough** (Docker stack running, `npm run dev`,
      signed in as a council member). Local WordPress has no SMTP, so read the
      link from a one-off CLI script instead of an inbox:

1. As the council member, open `/review/<id>` for a submission, ask a
   question; confirm the stage badge becomes "awaiting submitter" and the
   question appears as plain text.
2. Get a fresh link for the same project (this rotates the token, as an
   email would):
   `docker compose exec -T wordpress php -r 'define("WP_USE_THEMES", false); require "/var/www/html/wp-load.php"; echo cdcf_review_status_url(<id>, cdcf_review_post_language(<id>), cdcf_review_issue_token(<id>)), PHP_EOL;'`
3. In a private window open the link: the address bar ends at
   `/submission-status/<id>` with no `t=`; the page shows the timeline with
   "Question awaiting your reply"; answer it; the step disappears and the
   answer shows.
4. Back in the workspace the answer appears under the question.
5. Run step 2 again, then reload the private window: "This link is not valid
   any more" with the resend form; submit any address: the same neutral
   message.
6. Check the page source has `<meta name="robots" content="noindex, nofollow">`
   and `<meta name="referrer" content="no-referrer">`.

- [ ] **Step 3: Full checks** — `php8.3 vendor/bin/phpunit` (theme),
      `npx vitest run`, `npx tsc --noEmit -p .`, `npm run lint`, `npm run build`.

- [ ] **Step 4: Commit, push, PR**

```bash
git add AGENTS.md
git commit -m "docs(review): document the submitter status page and endpoints"
git push -u origin feat/project-review-phase3
gh pr create --base main --title "feat(review): Phase 3 — submitter status page, questions and emails" --body-file <body>
```

The PR body lists the rulings above, the test counts, the walkthrough, a
"Deploy notes" section (theme changes → `gh workflow run deploy.yml -f
environment=production`; `CDCF_FRONTEND_URL` must be set in production
`wp-config.php` for correct links; the `redis-queue` plugin must be active
for queued delivery), and notes the pre-existing per-IP limit flaw in
`submit-project` as a follow-up.

## Summary

Nine tasks: token (1), emails and queue job (2), confirmation on submit (3),
public endpoints (4), ask endpoint (5), Next.js server layer (6), status
page (7), workspace composer (8), docs and walkthrough (9). WordPress tasks
are independent of the Next.js tasks except through the REST contracts in
each task's Interfaces block.
