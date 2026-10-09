# Donation page

**Status:** design, awaiting review.

## Problem

`components/Header.tsx` links to `/donate` in both the desktop and mobile
navigation, and `messages/*.json` carries a translated `donate` label in all
six locales. The destination does not exist. The Catholic Digital Commons
Foundation has no way to accept donations through the website.

The Foundation needs to accept both one-time and recurring gifts, either to
the Foundation generally or designated toward a specific project.

## Scope

**A `/donate` page backed by Stripe Checkout**, with per-donation amounts
capped at $200, optional non-binding project designation, and a localized
acknowledgment email.

Out of scope:

- **Year-end statements and donor CRM.** The $200 cap keeps every gift below
  the IRS written-acknowledgment threshold, which removes the need. See
  [Decisions](#decisions).
- **Restricted-fund accounting.** Designation is a stated preference, not a
  legally restricted contribution.
- **Donor recognition and supporter badges.** Anything given in return for a
  gift triggers quid-pro-quo disclosure rules at $75 and forfeits the
  compliance simplicity the cap buys.
- **ACH, donor-advised funds, and Adaptive Pricing / multi-currency.**
- **Per-project "Support this project" buttons** on project detail pages. The
  ACF flag introduced here makes that a small later addition.
- **Liberapay integration.** A plain footer link only. See
  [Why not Liberapay](#why-not-liberapay).

## Decisions

Six decisions constrain everything below. They were settled before design and
are the things to push back on first if the design looks wrong.

1. **Stripe Checkout, not Liberapay.** Liberapay fails three stated
   requirements outright. See [Why not Liberapay](#why-not-liberapay).
2. **$200 maximum per donation, enforced server-side.** Gifts above the cap
   route to the administrative offices rather than through the website.
3. **The cap is what lets us skip receipting infrastructure.** IRS
   Publication 1771 requires a written acknowledgment for contributions of
   $250 or more, and separate contributions are **not** aggregated — so a
   $200/month recurring gift is twelve separate sub-threshold contributions,
   not a $2,400 one. We still send a thank-you email carrying the
   substantiation sentence, because it costs one template and makes every
   gift substantiated regardless of how a donor stacks them.
4. **Project designation is a curated, non-binding preference.** An ACF flag
   on the existing `project` CPT controls which projects appear in the picker.
5. **Guest checkout.** Donating requires no account. Recurring donors manage
   their gift through an emailed link into Stripe's hosted Customer Portal.
   Zitadel is not coupled to the donation flow.
6. **Copy lives in WordPress; the form lives in React.** Not one or the
   other. See [Where the page lives](#where-the-page-lives).

## Why not Liberapay

Liberapay was evaluated as the alternative to Stripe and rejected on
requirements, not preference. Per [its FAQ](https://liberapay.com/about/faq):

- **No one-time donations.** "One-time donations aren't properly supported
  yet, but you can discontinue your donation immediately after initiating the
  first payment."
- **No per-project designation** within a single account. Each project would
  need its own separate Liberapay team.
- **No receipting**, and on deductibility: "Probably not, but it depends on
  the tax rules of your country."
- **Worse effective rate.** Liberapay routes through Stripe and PayPal
  underneath, averaging ~3% and ~5% respectively, and cannot pass through a
  501(c)(3) nonprofit discount.

It remains worth a footer link: the FOSS audience recognizes it and it costs
nothing to maintain.

## Where the page lives

The WordPress-versus-Next.js framing is a false choice. `SubmitProjectModal`
and `ReferLocalGroupModal` already establish the pattern this page follows —
interactive React inside a WordPress-driven section.

| Concern                                         | Home                          | Why                                                                                             |
| ----------------------------------------------- | ----------------------------- | ----------------------------------------------------------------------------------------------- |
| Appeal copy, impact statements, campaign text   | WordPress ACF + Polylang      | Editorial content that changes often. Editors must change it in six languages without a deploy. |
| Project picker options                          | WordPress (`project` CPT)     | Already Polylang-translated. The curated list is one checkbox field, no new content model.      |
| Form chrome — "per month", "Donate now", errors | `messages/*.json` via Weblate | UI strings, developer-owned, already covered by an existing translation pipeline.               |
| Amount selection, frequency, Checkout redirect  | React client component        | Interactive either way.                                                                         |

The IRS substantiation sentence stays **in English** even inside a localized
email. It substantiates a US tax deduction for a US taxpayer, and machine
translation of legal boilerplate adds risk for no benefit.

## Architecture

### WordPress theme

- `templates/donate.php`, registered in the `theme_page_templates` filter at
  `functions.php:1910`.
- A `donateFields` ACF group matching the shape of the other per-template
  groups: appeal copy, suggested amount presets, tax disclaimer, above-cap
  contact copy, thank-you copy.
- A `project_accepts_donations` true/false ACF field on the `project` CPT,
  exposed via GraphQL. This is the entire curated-list mechanism.
- `includes/handlers/donation-acknowledgment.php` →
  `POST /cdcf/v1/donation-acknowledgment`, Application-Password authed,
  called server-to-server by the webhook handler. Sends via `wp_mail`, which
  the theme already routes through authenticated SMTP (`functions.php:55`).
- `includes/handlers/donation-portal-link.php` →
  `POST /cdcf/v1/donation-portal-link`, same auth, mailing the Billing
  Portal link. Both outbound emails go through WordPress rather than a Node
  mailer, matching how every existing transactional email in this repository
  is sent.

Per the sanitization convention, every field declares its `sanitize_callback`
in the `args` block at registration; the handler does not re-sanitize.

New PHP files use the single-line `defined('ABSPATH') || exit;` guard.

### Next.js

- `lib/stripe.ts` — server-only Stripe client. Adds the repository's first
  `stripe` dependency.
- `lib/donate/validation.ts` — pure functions for amount, frequency, and
  project-slug validation, extracted so they test without mocking Stripe.
- `app/api/donate/create-session/route.ts` — validates, then creates the
  Checkout Session.
- `app/api/donate/webhook/route.ts` — signature-verified Stripe receiver.
- `app/api/donate/portal/route.ts` — accepts an email address, looks up the
  matching Stripe customer, creates a Billing Portal session, and hands the
  link to WordPress to mail. It always responds identically whether or not
  the address matched a donor, so the endpoint cannot be used to probe who
  has given.
- `components/sections/DonationSection.tsx` (server, reads ACF) wrapping
  `DonationForm.tsx` (client).
- `renderDonate()` in `components/sections/PageRenderer.tsx`.

### Request flow

1. Donor on `/it/donate` selects amount, frequency, and optionally a project.
2. `POST /api/donate/create-session` with amount in cents, frequency,
   project slug, and locale.
3. The route validates server-side, then creates a Checkout Session with
   inline `price_data`, `currency: 'usd'`, `locale: 'it'`, and metadata.
4. Client redirects to Stripe's hosted page, rendered in Italian.
5. Donor returns to `/it/donate?status=success`.
6. Stripe delivers a webhook; the webhook — not the redirect — triggers the
   acknowledgment.

The return landing is a **query parameter on the same WordPress page**, not a
separate `/donate/thank-you` page, so all copy stays in one ACF group and
there is no second page to translate.

### Localization

Stripe Checkout supports all six locales — `en`, `it`, `es`, `fr`, `pt`, `de`
— per [the supported-locales
list](https://docs.stripe.com/js/appendix/supported_locales). One caveat:
Stripe maps `pt` to Portuguese **(Brazil)**; there is no European Portuguese
option.

### Two correctness details

These are easy to get wrong and both produce silent, donor-visible bugs:

1. **Session metadata does not propagate to the subscription.** In
   `subscription` mode the project designation must also be set in
   `subscription_data.metadata`, or renewal charges arrive with no record of
   which project they were designated toward.
2. **Subscription mode fires both `checkout.session.completed` and
   `invoice.paid` for the first payment.** Handling both double-sends the
   first acknowledgment. The rule: **`payment` mode acknowledges on
   `checkout.session.completed`; `subscription` mode acknowledges only on
   `invoice.paid`**, which covers the first charge and every renewal through
   one code path.

## Money handling

### The cap

Enforced server-side in `create-session`, before any Stripe call, returning 400. The form enforces it too, but that is UX only — a client-side cap is
bypassed by posting a hand-written body.

**Above the cap is a route, not an error.** When the donor enters more than
$200 the form replaces the Checkout button with the administrative office's
contact details, drawn from an ACF field so it is translated and editable.
It is framed as "gifts above $200 are handled personally by our team," which
is both a better donor experience and how major gifts should actually arrive.

### Floor

$5 minimum. Stripe's technical minimum is $0.50, but against a 30¢ fixed fee
a $1 gift nets roughly $0.67. The floor also raises the per-attempt cost of
card testing.

### Currency

Settle in USD. Adaptive Pricing is deliberately **not** enabled in v1: it
adds a conversion fee and makes the cap fuzzy at the point of sale, since the
donor would see a converted figure while the cap is enforced pre-conversion.
Revisit once donor geography is observable.

### Fees

Stripe [offers a discount to
501(c)(3)s](https://support.stripe.com/questions/fee-discount-for-nonprofit-organizations)
on application with an EIN or IRS determination letter, but does not publish
the rate. Budget against standard 2.9% + 30¢ until Stripe confirms.

### Card testing

Public donation forms are the most targeted surface for validating stolen
cards, precisely because they accept arbitrary amounts from anonymous users.
Layered mitigation:

- Stripe Radar, plus a rule blocking high-velocity attempts.
- The $5 floor.
- IP rate limiting on `create-session`, reusing the `rateMap` pattern already
  in `app/api/submit-project/route.ts`.
- An alert on a spike in **failed** payment intents. The first sign of an
  attack is a burst of declines, not a burst of charges.

## Data

**No donor PII is stored.** Stripe is the system of record — it holds name,
email, and card, and its Dashboard reports totals grouped by
`metadata.project_slug`. With the cap removing the need for year-end
statements and designation being non-binding, a local donor table would buy
nothing Stripe does not already have while creating a GDPR obligation.

Metadata carries `project_slug`, `project_title_en`, and `locale`. The
English title is stored alongside the slug so a gift's record still reads
correctly if the project is later unpublished or renamed. Locale is set by us
rather than read from Stripe's customer object, which is not reliably present
in the webhook payload.

### Idempotency

Stripe retries failed webhooks for up to three days, so without a guard one
transient WordPress outage becomes a stack of duplicate thank-you emails.

The guard lives **at the side-effect boundary — in the WordPress handler**,
not at the Next.js edge, because that is where the email is actually sent. A
transient keyed on the Stripe event id with a 72-hour TTL. At this volume the
get/set race is acceptable; its worst case is one duplicate email, never a
duplicate charge.

### Acknowledgment email

Localized greeting, amount, date, one-time versus monthly, the designated
project with non-binding wording, Foundation legal name and address and EIN,
the manage-your-giving link for recurring gifts, and — in English — the
substantiation sentence stating that no goods or services were provided in
exchange for the contribution.

## Failure modes

**Never fulfill on the `success_url` redirect.** The browser returning to
`/donate?status=success` is a UI cue and nothing more: it can be forged by
typing the URL, and it never arrives if the donor closes the tab. The webhook
is the only source of truth. The success state renders ACF thank-you copy and
says a receipt is on its way; it triggers nothing.

| Failure                                | Handling                                                                                                             |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Invalid webhook signature              | 400, no processing. Never parse an unverified body.                                                                  |
| WordPress unreachable from webhook     | Return non-2xx **deliberately** so Stripe retries. Returning 200 on a failed email loses the acknowledgment forever. |
| WordPress down beyond 3 days           | Stripe stops retrying. Accept, but log loudly — this case needs a human.                                             |
| Stripe unavailable at session creation | 502; the form offers a retry. Nothing was charged.                                                                   |
| Recurring payment fails later          | Stripe Smart Retries and dunning emails own this. We build nothing.                                                  |
| Refund or dispute                      | Manual, in the Dashboard. A refunded gift leaves an acknowledgment standing — acceptable at ≤$200 and low volume.    |

### Staging shares the production WordPress backend

Per `CLAUDE.md`, staging deploys ship only the Next.js frontend and point at
the **production** WordPress backend. A staging Stripe webhook would
therefore reach the production acknowledgment handler and mail real donors.

Two requirements, both built in from the first commit rather than added after
the first embarrassing email:

- Staging uses Stripe **test** keys and a separate webhook secret.
- The WordPress handler branches on the event's `livemode` flag, refusing to
  mail the real donor address when false and routing to a configured test
  inbox instead.

## Testing

Mapping onto the three suites the repository already runs:

- **Vitest** (`npm test`) — everything in `lib/donate/`: amount floor, cap,
  integer and non-numeric rejection; frequency allowlist; project-slug
  allowlist; the mode-to-event rule from
  [Two correctness details](#two-correctness-details); acknowledgment payload
  construction.
- **PHPUnit + Brain Monkey** (theme) — the `donation-acknowledgment` handler,
  mirroring `tests/SendCodeHandlerTestBase.php`: auth rejection, missing
  fields, the idempotency guard, `wp_mail` called exactly once with expected
  content, 500 on `wp_mail` failure, and the `livemode: false` branch.
- **Stripe CLI** — `stripe listen --forward-to
localhost:3000/api/donate/webhook` plus `stripe trigger` for the
  end-to-end path. A documented manual dev procedure, not CI, since it
  requires credentials.

## Deployment

- New environment variables: `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`,
  with distinct test-mode values on staging. The success and cancel
  redirects reuse the existing `NEXT_PUBLIC_SITE_URL`; no new URL variable
  is needed.
- Apple Pay and Google Pay come free with Checkout but require a
  domain-verification step in the Stripe Dashboard.
- Theme changes mean the deploy must run
  `gh workflow run deploy.yml -f environment=production`. A bare
  `workflow_dispatch` defaults to staging, which skips every WordPress step
  and leaves the new REST route 404ing behind a green run.

## Open questions

None blocking. Two items need a value rather than a decision before
implementation lands:

- The Foundation's EIN and legal mailing address, for the acknowledgment
  email.
- The administrative office contact details shown above the cap.
