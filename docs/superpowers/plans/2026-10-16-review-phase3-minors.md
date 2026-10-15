# Council Project Review — Phase 3 follow-up minors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the deferred minor findings from PR #351's reviews that are
worth fixing: accessibility on the submitter page, request-size and
response guards in the Next.js routes, missing tests, and one overstated
UI sentence.

**Architecture:** No design changes. WordPress gets tests only. Next.js
gets small guards in existing route handlers and the status client, a
route-handler test for the link-opening route, and focus/live-region fixes
in two client components.

**Tech Stack:** PHP 8.3 + PHPUnit/Brain Monkey (theme); Next.js 16,
next-intl 4, Vitest 5 + happy-dom.

**Spec:** `docs/superpowers/specs/2026-10-15-project-review-design.md`
(Phase 3 shipped in PR #351; plan
`docs/superpowers/plans/2026-10-17-project-review-phase3.md`).

## Global Constraints

- No behaviour change beyond what each task names; existing tests keep
  passing.
- Error codes stay WordPress-compatible: an over-long body is
  `400 body_too_long` (already mapped to a message on the status page).
- Answer bodies are capped at 10,000 characters (WordPress's
  `CDCF_SUBMISSION_ANSWER_MAX`); question bodies at 50,000
  (`CDCF_REVIEW_BODY_MAX`).
- messages/\*.json are edited as text (never parse-and-re-serialize).
- Formatting: `npx prettier --single-quote --no-semi --trailing-comma es5`.
- Commit messages end with the model's own `Co-Authored-By` line and
  `Claude-Session: https://claude.ai/code/session_01FucMioRUfo7AdXMMAjbN4k`.

## Review Focus

1. A malformed `t` on the emailed link must never set a cookie, and the
   redirect `Location` must never contain the token (Task 2 tests).
2. Keyboard users must keep focus in the answer box after a refused answer
   (Task 3).

---

### Task 1: Missing WordPress tests

**Files:** Modify `wordpress/themes/cdcf-headless/tests/SubmissionStatusHandlerTest.php`

- [ ] **Step 1:** Add `test_answer_insert_failure_is_a_500_and_changes_nothing`:
      alias `cdcf_review_insert_entry` to return `0`; a valid answer returns a
      `WP_Error` with code `insert_failed`, status 500; the stage meta stays
      `awaiting_submitter`; no event is recorded.
- [ ] **Step 2:** Add `test_resend_with_no_stored_address_sends_nothing`:
      set `_submission_submitter_email` to `''`; a resend request with any
      address returns `202 {accepted: true}` and sends no email.
- [ ] **Step 3:** Run `php8.3 vendor/bin/phpunit tests/SubmissionStatusHandlerTest.php`
      (both new tests pass against existing code — these pin current
      behaviour; state that in the report), then the full theme suite; commit
      `test(review): pin answer insert failure and resend without a stored address`.

---

### Task 2: Next.js route guards and the open-route test

**Files:**

- Modify: `app/api/submission-status/[id]/answer/route.ts`, `app/api/review/projects/[id]/question/route.ts`, `lib/submission-status-api.ts`, `lib/submission-status-api.test.ts`, `vitest.config.ts`
- Create: `app/api/submission-status/[id]/open/route.test.ts`

- [ ] **Step 1 (test first):** In `lib/submission-status-api.test.ts` add a
      test: a `200` response with a non-JSON body makes `fetchSubmissionStatus`
      reject with `ReviewApiError` status `502`, code `bad_response`. Run it —
      expect FAIL (currently resolves `null`).
- [ ] **Step 2:** In `statusRequest`, after the `!response.ok` branch:

```ts
if (body === null) {
  throw new ReviewApiError(
    "WordPress returned an unreadable response",
    502,
    "bad_response",
  );
}
```

Run — PASS.

- [ ] **Step 3:** Add `'app/**/*.test.ts'` to `include` in
      `vitest.config.ts`. Create `app/api/submission-status/[id]/open/route.test.ts`
      (node environment) that mocks `next/headers` (`cookies()` returning an
      object whose `set` is a `vi.fn()`) and `next/navigation` (`redirect`
      throwing `new Error('REDIRECT:' + path)`), imports `GET`, and asserts:
  - valid 64-hex token, `lang=it`, id `42`: `set` called once with
    `('cdcf_status_42', token, {httpOnly: true, secure: false, sameSite: 'lax', path: '/', maxAge: 15552000})`
    and the redirect path is `/it/submission-status/42` (no `t=`, token not
    in the path);
  - malformed token (`'abc'`, and 64 upper-case hex): `set` not called;
    redirect still `/it/submission-status/42`;
  - unknown `lang` (`xx`): redirect `/submission-status/42`;
  - id `0` or `abc`: response status 404, no `set`, no redirect.
    Build the request with `new NextRequest('http://localhost/api/submission-status/42/open?lang=it&t=…')`
    and `{ params: Promise.resolve({ id: '42' }) }`. Run — PASS (pins
    current behaviour).
- [ ] **Step 4:** Body caps. In the answer route, after the type check:
      `if (text.length > 10000) return badRequest('body_too_long')`. In the
      question route: `if (body.body.length > 50000) return badRequest('body_too_long')`.
      (Route handlers are thin and the WordPress tests already cover the same
      limits; no new route test needed.)
- [ ] **Step 5:** `npx vitest run` (report the Test Files line: 0 failed),
      `npx tsc --noEmit -p .`, `npm run lint`; commit
      `fix(review): cap forwarded bodies, reject unreadable WordPress responses, test the open route`.

---

### Task 3: Submitter-page accessibility and the ask composer

**Files:**

- Modify: `components/submission-status/AnswerForm.tsx`, `components/submission-status/AnswerForm.test.tsx`, `components/submission-status/ResendLinkForm.tsx`, `components/submission-status/ResendLinkForm.test.tsx`, `components/review/AskSubmitter.tsx`, `components/review/AskSubmitter.test.tsx`, `messages/{en,it,es,fr,pt,de}.json`

- [ ] **Step 1 (test first):** In `AnswerForm.test.tsx`, in the refused-answer
      test, focus the textarea before submitting and assert after the refusal
      that `document.activeElement` is still the textarea. Run — expect FAIL
      (the textarea is `disabled` while sending, which drops focus).
- [ ] **Step 2:** In `AnswerForm.tsx` replace the textarea's
      `disabled={busy}` with `readOnly={busy}` and add `aria-busy={busy}`.
      The submit button keeps `disabled`. Run — PASS.
- [ ] **Step 3 (test first):** In `ResendLinkForm.test.tsx` assert that an
      element with `role="status"` exists _before_ submitting (empty) and holds
      the confirmation text after. Run — expect FAIL.
- [ ] **Step 4:** In `ResendLinkForm.tsx` always render
      `<p role="status" className="text-sm text-gray-700">{state === 'done' ? t('resendDone') : null}</p>`
      and render the form only while `state !== 'done'` (so the live region
      exists before its text arrives and screen readers announce it). Run — PASS.
- [ ] **Step 5 (test first):** In `AskSubmitter.test.tsx` add an error-path
      test: fetch resolves `403 {"error":"rest_forbidden"}` → the
      `Review.errors.forbidden` message is shown (via `ComposerStatus`), the
      textarea keeps its text, and `router.refresh` is not called. Also add:
      a `201` response with no `warnings` field shows no email warning and does
      not throw. Run — expect the missing-`warnings` case to FAIL.
- [ ] **Step 6:** In `AskSubmitter.tsx` read
      `setEmailFailed(result.data.warnings?.includes('email_failed') ?? false)`
      and type the response as `ReviewEntry & { warnings?: string[] }`; remove
      the stray blank line in the import block. Run — PASS.
- [ ] **Step 7:** Replace `Review.questions.askWarning` in all six locales
      (text edits) with:
  - en: The submitter receives this text by email as plain text, from "the CDCF review committee" and without your name.
  - it: Il proponente riceve questo testo via email come testo semplice, da parte del "comitato di revisione della CDCF" e senza il tuo nome.
  - es: El proponente recibe este texto por correo como texto sin formato, de parte del «comité de revisión de la CDCF» y sin tu nombre.
  - fr: Le proposant reçoit ce texte par e-mail en texte brut, de la part du « comité d'examen de la CDCF » et sans votre nom.
  - pt: O proponente recebe este texto por email como texto simples, da parte do "comité de revisão da CDCF" e sem o seu nome.
  - de: Die einreichende Person erhält diesen Text per E-Mail als reinen Text, vom „Prüfungsausschuss der CDCF“ und ohne Ihren Namen.

  Keep each file's existing quote escaping (`\"` where the current value
  uses it). Update the AskSubmitter test's `'exactly as written'` assertion
  to `'as plain text'`.

- [ ] **Step 8:** `npx vitest run`, `npx tsc --noEmit -p .`, `npm run lint`,
      `npm run build`; commit
      `fix(review): keep focus after a refused answer, announce the resend confirmation, tidy the ask composer`.
