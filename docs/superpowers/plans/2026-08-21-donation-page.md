# Donation Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a `/donate` page that accepts one-time and monthly gifts through Stripe Checkout, capped at $200 per contribution, with optional non-binding project designation and a localized acknowledgment email.

**Architecture:** Editorial copy lives in WordPress ACF (Polylang-translated); the form is a React client component rendered by `PageRenderer` under a new `Donate` page template. Next.js route handlers create the Checkout Session and receive Stripe's webhook; both outbound emails are sent by WordPress through `wp_mail`, matching every other transactional email in this repository. Stripe is the system of record — no donor PII is stored locally.

**Tech Stack:** Next.js 16 (App Router), `stripe` (new dependency), next-intl, WordPress + ACF + Polylang + WPGraphQL, Vitest, PHPUnit + Brain Monkey + Mockery.

**Spec:** `docs/superpowers/specs/2026-08-21-donation-page-design.md`

## Global Constraints

- **Minimum donation: 500 cents ($5). Maximum: 20000 cents ($200).** Enforced server-side in `create-session` before any Stripe call.
- **Currency is always `usd`.** Adaptive Pricing is not enabled.
- **Frequencies are exactly `once` and `monthly`.** No other interval.
- **Locales are `en`, `it`, `es`, `fr`, `pt`, `de`** — matching `src/i18n/routing.ts`. Stripe Checkout accepts all six.
- **Never fulfill on the `success_url` redirect.** The webhook is the only trigger for an acknowledgment.
- **`payment` mode acknowledges on `checkout.session.completed`; `subscription` mode acknowledges only on `invoice.paid`.**
- **New PHP files use the single-line `defined('ABSPATH') || exit;` guard** — not the multi-line `if (defined('ABSPATH') === false) { return; }` form used by older handlers. Codecov patch coverage drops ~2 lines otherwise.
- **Every REST field declares its `sanitize_callback` in the `args` block at registration.** Handlers do not re-sanitize on entry.
- **The IRS substantiation sentence is English in every locale.** Never translate it.
- **Theme changes require `gh workflow run deploy.yml -f environment=production`.** A bare dispatch defaults to staging, skips all WordPress steps, and goes green anyway.

---

### Task 1: Donation input validation

Pure functions with no Stripe dependency, so the cap and floor are testable without network or mocks. Everything downstream imports these constants rather than redeclaring numbers.

**Files:**

- Create: `lib/donate/validation.ts`
- Test: `lib/donate/validation.test.ts`

**Interfaces:**

- Consumes: nothing.
- Produces: `MIN_DONATION_CENTS: 500`, `MAX_DONATION_CENTS: 20000`, `DonationFrequency = 'once' | 'monthly'`, `validateAmountCents(value: unknown): AmountValidation`, `isDonationFrequency(value: unknown): value is DonationFrequency`, `validateProjectSlug(value: unknown, allowed: readonly string[]): ProjectSlugValidation`.

- [ ] **Step 1: Write the failing test**

```typescript
// lib/donate/validation.test.ts
import { describe, expect, it } from "vitest";

import {
  MAX_DONATION_CENTS,
  MIN_DONATION_CENTS,
  isDonationFrequency,
  validateAmountCents,
  validateProjectSlug,
} from "./validation";

describe("validateAmountCents", () => {
  it("accepts an amount at the floor", () => {
    expect(validateAmountCents(500)).toEqual({ ok: true, amountCents: 500 });
  });

  it("accepts an amount at the cap", () => {
    expect(validateAmountCents(20000)).toEqual({
      ok: true,
      amountCents: 20000,
    });
  });

  it("rejects one cent above the cap", () => {
    expect(validateAmountCents(20001)).toEqual({
      ok: false,
      reason: "above_maximum",
    });
  });

  it("rejects one cent below the floor", () => {
    expect(validateAmountCents(499)).toEqual({
      ok: false,
      reason: "below_minimum",
    });
  });

  it("rejects a fractional amount", () => {
    expect(validateAmountCents(500.5)).toEqual({
      ok: false,
      reason: "not_an_integer",
    });
  });

  it("rejects a numeric string, so a hand-written body cannot smuggle one", () => {
    expect(validateAmountCents("5000")).toEqual({
      ok: false,
      reason: "not_an_integer",
    });
  });

  it("rejects NaN and Infinity", () => {
    expect(validateAmountCents(Number.NaN).ok).toBe(false);
    expect(validateAmountCents(Number.POSITIVE_INFINITY).ok).toBe(false);
  });

  it("rejects a negative amount", () => {
    expect(validateAmountCents(-500)).toEqual({
      ok: false,
      reason: "below_minimum",
    });
  });

  it("exports the cap and floor the spec fixes", () => {
    expect(MIN_DONATION_CENTS).toBe(500);
    expect(MAX_DONATION_CENTS).toBe(20000);
  });
});

describe("isDonationFrequency", () => {
  it("accepts the two supported frequencies", () => {
    expect(isDonationFrequency("once")).toBe(true);
    expect(isDonationFrequency("monthly")).toBe(true);
  });

  it("rejects any other interval", () => {
    expect(isDonationFrequency("weekly")).toBe(false);
    expect(isDonationFrequency("yearly")).toBe(false);
    expect(isDonationFrequency(undefined)).toBe(false);
  });
});

describe("validateProjectSlug", () => {
  const allowed = ["liturgical-calendar", "bibleget"] as const;

  it("treats an absent designation as the general fund", () => {
    expect(validateProjectSlug(undefined, allowed)).toEqual({
      ok: true,
      slug: null,
    });
    expect(validateProjectSlug("", allowed)).toEqual({ ok: true, slug: null });
    expect(validateProjectSlug(null, allowed)).toEqual({
      ok: true,
      slug: null,
    });
  });

  it("accepts a slug on the allowlist", () => {
    expect(validateProjectSlug("bibleget", allowed)).toEqual({
      ok: true,
      slug: "bibleget",
    });
  });

  it("rejects a slug that is not on the allowlist", () => {
    expect(validateProjectSlug("not-a-project", allowed)).toEqual({
      ok: false,
      reason: "unknown_project",
    });
  });

  it("rejects a non-string designation", () => {
    expect(validateProjectSlug(42, allowed)).toEqual({
      ok: false,
      reason: "unknown_project",
    });
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run lib/donate/validation.test.ts`
Expected: FAIL — `Failed to resolve import "./validation"`.

- [ ] **Step 3: Write the implementation**

```typescript
// lib/donate/validation.ts

/** Stripe's technical minimum is 50 cents, but a $1 gift nets ~$0.67 after
 *  the 30c fixed fee. The floor also raises the per-attempt cost of card
 *  testing, which is the main abuse vector on a public donation form. */
export const MIN_DONATION_CENTS = 500;

/** The cap keeps every contribution below the $250 IRS written-
 *  acknowledgment threshold. Separate contributions are not aggregated,
 *  so a $200/month recurring gift stays under it too. */
export const MAX_DONATION_CENTS = 20_000;

export type DonationFrequency = "once" | "monthly";

export type AmountValidation =
  | { ok: true; amountCents: number }
  | { ok: false; reason: "not_an_integer" | "below_minimum" | "above_maximum" };

export type ProjectSlugValidation =
  { ok: true; slug: string | null } | { ok: false; reason: "unknown_project" };

export function validateAmountCents(value: unknown): AmountValidation {
  if (typeof value !== "number" || !Number.isInteger(value)) {
    return { ok: false, reason: "not_an_integer" };
  }
  if (value < MIN_DONATION_CENTS) {
    return { ok: false, reason: "below_minimum" };
  }
  if (value > MAX_DONATION_CENTS) {
    return { ok: false, reason: "above_maximum" };
  }
  return { ok: true, amountCents: value };
}

export function isDonationFrequency(
  value: unknown,
): value is DonationFrequency {
  return value === "once" || value === "monthly";
}

export function validateProjectSlug(
  value: unknown,
  allowed: readonly string[],
): ProjectSlugValidation {
  if (value === undefined || value === null || value === "") {
    return { ok: true, slug: null };
  }
  if (typeof value !== "string" || !allowed.includes(value)) {
    return { ok: false, reason: "unknown_project" };
  }
  return { ok: true, slug: value };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run lib/donate/validation.test.ts`
Expected: PASS, 15 tests.

- [ ] **Step 5: Commit**

```bash
git add lib/donate/validation.ts lib/donate/validation.test.ts
git commit -m "feat(donate): add server-side amount, frequency and designation validation"
```

---

### Task 2: Webhook acknowledgment routing and payload

The rule that prevents a double thank-you on the first subscription charge, plus the payload the WordPress mailer receives. Pure, so the rule is pinned by tests rather than discovered in production.

**Files:**

- Create: `lib/donate/acknowledgment.ts`
- Test: `lib/donate/acknowledgment.test.ts`

**Interfaces:**

- Consumes: `DonationFrequency` from `lib/donate/validation.ts`.
- Produces: `shouldAcknowledge(eventType: string, checkoutMode: 'payment' | 'subscription' | null): boolean`, `AcknowledgmentPayload` interface, `buildAcknowledgmentPayload(input: AcknowledgmentInput): AcknowledgmentPayload`.

- [ ] **Step 1: Write the failing test**

```typescript
// lib/donate/acknowledgment.test.ts
import { describe, expect, it } from "vitest";

import {
  buildAcknowledgmentPayload,
  shouldAcknowledge,
} from "./acknowledgment";

describe("shouldAcknowledge", () => {
  it("acknowledges a one-time gift on checkout.session.completed", () => {
    expect(shouldAcknowledge("checkout.session.completed", "payment")).toBe(
      true,
    );
  });

  it("does NOT acknowledge a subscription on checkout.session.completed", () => {
    // invoice.paid fires for the same first charge; acknowledging both
    // double-sends the first thank-you.
    expect(
      shouldAcknowledge("checkout.session.completed", "subscription"),
    ).toBe(false);
  });

  it("acknowledges every invoice.paid, first charge and renewal alike", () => {
    expect(shouldAcknowledge("invoice.paid", null)).toBe(true);
  });

  it("ignores every other event type", () => {
    expect(shouldAcknowledge("payment_intent.succeeded", null)).toBe(false);
    expect(shouldAcknowledge("invoice.payment_failed", null)).toBe(false);
    expect(shouldAcknowledge("customer.subscription.deleted", null)).toBe(
      false,
    );
  });
});

describe("buildAcknowledgmentPayload", () => {
  const base = {
    eventId: "evt_123",
    livemode: true,
    email: "donor@example.org",
    donorName: "Jane Donor",
    amountCents: 5000,
    frequency: "once" as const,
    locale: "it",
    projectTitle: "BibleGet",
    occurredAt: 1755777600,
    portalUrl: "",
  };

  it("maps every field onto the WordPress request shape", () => {
    expect(buildAcknowledgmentPayload(base)).toEqual({
      event_id: "evt_123",
      livemode: true,
      email: "donor@example.org",
      donor_name: "Jane Donor",
      amount_cents: 5000,
      currency: "usd",
      frequency: "once",
      locale: "it",
      project_title: "BibleGet",
      occurred_at: 1755777600,
      portal_url: "",
    });
  });

  it("falls back to English when the locale is absent or unsupported", () => {
    expect(buildAcknowledgmentPayload({ ...base, locale: "" }).locale).toBe(
      "en",
    );
    expect(buildAcknowledgmentPayload({ ...base, locale: "ja" }).locale).toBe(
      "en",
    );
  });

  it("sends an empty project title for an undesignated gift", () => {
    const payload = buildAcknowledgmentPayload({ ...base, projectTitle: "" });
    expect(payload.project_title).toBe("");
  });

  it("falls back to an empty donor name rather than emitting undefined", () => {
    const payload = buildAcknowledgmentPayload({ ...base, donorName: "" });
    expect(payload.donor_name).toBe("");
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run lib/donate/acknowledgment.test.ts`
Expected: FAIL — `Failed to resolve import "./acknowledgment"`.

- [ ] **Step 3: Write the implementation**

```typescript
// lib/donate/acknowledgment.ts
import type { DonationFrequency } from "./validation";

/** Locales the acknowledgment email has copy for. Mirrors
 *  src/i18n/routing.ts; anything else falls back to English. */
const SUPPORTED_LOCALES = ["en", "it", "es", "fr", "pt", "de"] as const;

export interface AcknowledgmentInput {
  eventId: string;
  livemode: boolean;
  email: string;
  donorName: string;
  amountCents: number;
  frequency: DonationFrequency;
  locale: string;
  projectTitle: string;
  occurredAt: number;
  portalUrl: string;
}

export interface AcknowledgmentPayload {
  event_id: string;
  livemode: boolean;
  email: string;
  donor_name: string;
  amount_cents: number;
  currency: string;
  frequency: DonationFrequency;
  locale: string;
  project_title: string;
  occurred_at: number;
  portal_url: string;
}

/**
 * Subscription mode fires BOTH checkout.session.completed and invoice.paid
 * for the first payment. Acknowledging on invoice.paid alone covers the
 * first charge and every renewal through one code path, with no duplicate.
 */
export function shouldAcknowledge(
  eventType: string,
  checkoutMode: "payment" | "subscription" | null,
): boolean {
  if (eventType === "checkout.session.completed") {
    return checkoutMode === "payment";
  }
  return eventType === "invoice.paid";
}

export function buildAcknowledgmentPayload(
  input: AcknowledgmentInput,
): AcknowledgmentPayload {
  const locale = (SUPPORTED_LOCALES as readonly string[]).includes(input.locale)
    ? input.locale
    : "en";

  return {
    event_id: input.eventId,
    livemode: input.livemode,
    email: input.email,
    donor_name: input.donorName || "",
    amount_cents: input.amountCents,
    currency: "usd",
    frequency: input.frequency,
    locale,
    project_title: input.projectTitle || "",
    occurred_at: input.occurredAt,
    portal_url: input.portalUrl || "",
  };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run lib/donate/acknowledgment.test.ts`
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add lib/donate/acknowledgment.ts lib/donate/acknowledgment.test.ts
git commit -m "feat(donate): route acknowledgments so subscriptions thank once, not twice"
```

---

### Task 3: WordPress content model

The Donate page template, its ACF group, and the one checkbox that makes a project donatable. After this task an editor can build the page in wp-admin and flag projects, even though nothing renders it yet.

**Files:**

- Create: `wordpress/themes/cdcf-headless/templates/donate.php`
- Modify: `wordpress/themes/cdcf-headless/functions.php` — `theme_page_templates` filter (~line 1910), `projectFields` ACF group (~line 1252), and the ACF group block ending at ~line 1904.

**Interfaces:**

- Consumes: nothing.
- Produces: GraphQL field `donateFields` on `Page` with `donateAppealBody`, `donatePresetAmounts`, `donateTaxDisclaimer`, `donateAboveCapBody`, `donateThankYouBody`; GraphQL field `projectFields.projectAcceptsDonations` (boolean) on `Project`; template name string `Donate`.

- [ ] **Step 1: Create the template stub**

The theme is headless — templates exist only so WordPress can offer the name in the page-template dropdown. Match the existing stubs.

```php
<?php
/**
 * Template Name: Donate
 *
 * Headless: rendering happens in Next.js (renderDonate in
 * components/sections/PageRenderer.tsx). This file exists so WordPress
 * registers the template name.
 */

defined('ABSPATH') || exit;
```

- [ ] **Step 2: Register the template name**

In `functions.php`, inside the `theme_page_templates` filter, add the line after `governance-toc.php`:

```php
    $templates['templates/donate.php'] = 'Donate';
```

- [ ] **Step 3: Add the donatable flag to the project CPT**

In the `group_project_fields` ACF group in `functions.php`, add this entry to the `fields` array immediately after `field_project_leads`:

```php
            [
                'key'           => 'field_project_accepts_donations',
                'label'         => 'Accepts Donations',
                'name'          => 'project_accepts_donations',
                'type'          => 'true_false',
                'instructions'  => 'Show this project in the donation page designation picker. Designation is a stated donor preference, not a restricted fund.',
                'default_value' => 0,
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
```

- [ ] **Step 4: Add the donateFields group**

In `functions.php`, inside the same `acf/init` action, add this group immediately after the `group_contact_page` group:

```php
    // ── Donate Page Specific Fields ──

    acf_add_local_field_group([
        'key'   => 'group_donate_page',
        'title' => 'Donate Page Content',
        'fields' => [
            [
                'key'   => 'field_donate_appeal_body',
                'label' => 'Appeal Body',
                'name'  => 'donate_appeal_body',
                'type'  => 'wysiwyg',
                'instructions' => 'The main appeal copy shown above the donation form.',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_donate_preset_amounts',
                'label' => 'Preset Amounts (USD)',
                'name'  => 'donate_preset_amounts',
                'type'  => 'text',
                'instructions' => 'Comma-separated whole dollars offered as quick-pick buttons, e.g. "10,25,50,100". Values above 200 are ignored — 200 is the hard cap.',
                'default_value' => '10,25,50,100',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_donate_tax_disclaimer',
                'label' => 'Tax Disclaimer',
                'name'  => 'donate_tax_disclaimer',
                'type'  => 'wysiwyg',
                'instructions' => 'Shown beneath the form. Include the Foundation legal name and EIN.',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_donate_above_cap_body',
                'label' => 'Above-Cap Body',
                'name'  => 'donate_above_cap_body',
                'type'  => 'wysiwyg',
                'instructions' => 'Shown instead of the checkout button when a donor enters more than $200. Include how to reach the administrative offices.',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
            [
                'key'   => 'field_donate_thank_you_body',
                'label' => 'Thank You Body',
                'name'  => 'donate_thank_you_body',
                'type'  => 'wysiwyg',
                'instructions' => 'Shown when a donor returns from Stripe with ?status=success.',
                'show_in_graphql' => true,
                'show_in_rest' => true,
            ],
        ],
        'location' => [
            [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/donate.php']],
        ],
        'show_in_graphql' => true,
        'graphql_field_name' => 'donateFields',
        'graphql_types' => ['Page'],
        'menu_order' => 10,
    ]);
```

- [ ] **Step 5: Verify PHP syntax and that the existing suite still passes**

```bash
php -l wordpress/themes/cdcf-headless/templates/donate.php
php -l wordpress/themes/cdcf-headless/functions.php
composer test --working-dir=wordpress/themes/cdcf-headless
```

Expected: `No syntax errors detected` twice, and the existing PHPUnit suite green (this task adds no handler, so no new tests).

- [ ] **Step 6: Commit**

```bash
git add wordpress/themes/cdcf-headless/templates/donate.php wordpress/themes/cdcf-headless/functions.php
git commit -m "feat(donate): add Donate page template, ACF fields and the donatable project flag"
```

---

### Task 4: Next.js data layer

Teach the GraphQL layer about `donateFields` and add the query that returns the curated designation list for a locale. Task 7 needs the allowlist; Task 10 needs the copy.

**Files:**

- Modify: `lib/wordpress/types.ts:186-220` (the `WPPage` interface)
- Modify: `lib/wordpress/queries.ts` — the page-fields fragment near the `contactFields` block (~line 276), plus a new exported query
- Modify: `lib/wordpress/api.ts` — new exported function
- Test: `lib/wordpress/api-donate.test.ts`

**Interfaces:**

- Consumes: `donateFields` / `projectFields.projectAcceptsDonations` from Task 3; `wpQuery` and `langCode` from the existing client.
- Produces: `WPPage.donateFields` (nullable object), `GET_DONATABLE_PROJECTS` query string, `getDonatableProjects(locale: string): Promise<DonatableProject[]>` where `DonatableProject = { slug: string; title: string }`.

- [ ] **Step 1: Write the failing test**

```typescript
// lib/wordpress/api-donate.test.ts
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("./client", () => ({ wpQuery: vi.fn() }));

import { getDonatableProjects } from "./api";
import { wpQuery } from "./client";

const mockedQuery = vi.mocked(wpQuery);

afterEach(() => {
  vi.resetAllMocks();
});

describe("getDonatableProjects", () => {
  it("returns only projects flagged as accepting donations", async () => {
    mockedQuery.mockResolvedValue({
      projects: {
        nodes: [
          {
            slug: "bibleget",
            title: "BibleGet",
            projectFields: { projectAcceptsDonations: true },
          },
          {
            slug: "dormant",
            title: "Dormant Project",
            projectFields: { projectAcceptsDonations: false },
          },
          {
            slug: "unset",
            title: "Unset Project",
            projectFields: null,
          },
        ],
      },
    });

    await expect(getDonatableProjects("it")).resolves.toEqual([
      { slug: "bibleget", title: "BibleGet" },
    ]);
  });

  it("maps the next-intl locale onto the Polylang language code", async () => {
    mockedQuery.mockResolvedValue({ projects: { nodes: [] } });

    await getDonatableProjects("pt");

    expect(mockedQuery).toHaveBeenCalledWith(expect.any(String), {
      language: "PT",
    });
  });

  it("returns an empty list rather than throwing when WordPress is down", async () => {
    mockedQuery.mockRejectedValue(new Error("ECONNREFUSED"));

    await expect(getDonatableProjects("en")).resolves.toEqual([]);
  });

  it("drops a node with no slug, which cannot be designated", async () => {
    mockedQuery.mockResolvedValue({
      projects: {
        nodes: [
          {
            slug: "",
            title: "Broken",
            projectFields: { projectAcceptsDonations: true },
          },
        ],
      },
    });

    await expect(getDonatableProjects("en")).resolves.toEqual([]);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run lib/wordpress/api-donate.test.ts`
Expected: FAIL — `getDonatableProjects is not a function`.

- [ ] **Step 3: Add the query**

In `lib/wordpress/queries.ts`, add `donateFields` to the page-fields fragment immediately after the `contactFields` block:

```graphql
  donateFields {
    donateAppealBody
    donatePresetAmounts
    donateTaxDisclaimer
    donateAboveCapBody
    donateThankYouBody
  }
```

Then append this exported query to the same file:

```typescript
// ─── Donatable projects (designation picker) ─────────────────────────

export const GET_DONATABLE_PROJECTS = `
  query GetDonatableProjects($language: LanguageCodeFilterEnum) {
    projects(where: { language: $language }, first: 100) {
      nodes {
        slug
        title
        projectFields {
          projectAcceptsDonations
        }
      }
    }
  }
`;
```

- [ ] **Step 4: Add the type**

In `lib/wordpress/types.ts`, add this member to the `WPPage` interface immediately after `contactFields`:

```typescript
  donateFields: {
    donateAppealBody: string | null
    donatePresetAmounts: string | null
    donateTaxDisclaimer: string | null
    donateAboveCapBody: string | null
    donateThankYouBody: string | null
  } | null
```

- [ ] **Step 5: Add the fetch function**

In `lib/wordpress/api.ts`, add `GET_DONATABLE_PROJECTS` to the existing import from `./queries`, then append:

```typescript
export interface DonatableProject {
  slug: string;
  title: string;
}

interface DonatableProjectNode {
  slug: string | null;
  title: string | null;
  projectFields: { projectAcceptsDonations: boolean | null } | null;
}

/**
 * The curated designation list. A project appears only when an editor has
 * ticked "Accepts Donations" on it, so dormant projects never become
 * fundraising destinations by default.
 */
export async function getDonatableProjects(
  locale: string,
): Promise<DonatableProject[]> {
  try {
    const data = await wpQuery<{
      projects: { nodes: DonatableProjectNode[] };
    }>(GET_DONATABLE_PROJECTS, { language: langCode(locale) });

    return data.projects.nodes
      .filter((node) => node.projectFields?.projectAcceptsDonations === true)
      .filter((node): node is DonatableProjectNode & { slug: string } =>
        Boolean(node.slug),
      )
      .map((node) => ({ slug: node.slug, title: node.title ?? node.slug }));
  } catch (error) {
    console.error("Failed to fetch donatable projects:", error);
    return [];
  }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `npx vitest run lib/wordpress/api-donate.test.ts`
Expected: PASS, 4 tests.

- [ ] **Step 7: Run the whole suite and the type check**

```bash
npm test
npx tsc --noEmit
```

Expected: all Vitest tests pass; no TypeScript errors.

- [ ] **Step 8: Commit**

```bash
git add lib/wordpress/types.ts lib/wordpress/queries.ts lib/wordpress/api.ts lib/wordpress/api-donate.test.ts
git commit -m "feat(donate): expose donateFields and the curated designation list"
```

---

### Task 5: WordPress acknowledgment mailer

The endpoint the webhook calls. Owns idempotency, the test-mode branch, and all six locales of email copy. This is the only place the IRS substantiation sentence is written.

**Files:**

- Create: `wordpress/themes/cdcf-headless/includes/handlers/donation-acknowledgment.php`
- Modify: `wordpress/themes/cdcf-headless/functions.php` — new `require_once` + `register_rest_route` beside the `/submit-project` block (~line 930)
- Modify: `wordpress/themes/cdcf-headless/tests/bootstrap.php:205` — add the `require_once`
- Modify: `CLAUDE.md` — add the row to the REST endpoint table
- Test: `wordpress/themes/cdcf-headless/tests/DonationAcknowledgmentHandlerTest.php`

**Interfaces:**

- Consumes: `AcknowledgmentPayload` field names from Task 2 (`event_id`, `livemode`, `email`, `donor_name`, `amount_cents`, `currency`, `frequency`, `locale`, `project_title`, `occurred_at`, `portal_url`).
- Produces: `POST /cdcf/v1/donation-acknowledgment` returning `['success' => true]`, `['success' => true, 'duplicate' => true]`, or `['success' => true, 'skipped' => 'test_mode_no_inbox']`; PHP function `cdcf_rest_donation_acknowledgment(WP_REST_Request $request)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/donation-acknowledgment handler.
 */
final class DonationAcknowledgmentHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('date_i18n')->alias(
            fn(string $fmt, int $ts) => gmdate($fmt, $ts)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function request(array $overrides = []): WP_REST_Request
    {
        $params = array_merge([
            'event_id'      => 'evt_123',
            'livemode'      => true,
            'email'         => 'donor@example.org',
            'donor_name'    => 'Jane Donor',
            'amount_cents'  => 5000,
            'currency'      => 'usd',
            'frequency'     => 'once',
            'locale'        => 'en',
            'project_title' => 'BibleGet',
            'occurred_at'   => 1755777600,
            'portal_url'    => '',
        ], $overrides);

        $req = new WP_REST_Request();
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }
        return $req;
    }

    public function test_permission_check_requires_edit_posts(): void
    {
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_posts')
            ->andReturn(true);

        $this->assertTrue(cdcf_donation_permission_check());
    }

    public function test_sends_one_email_on_the_happy_path(): void
    {
        Functions\expect('wp_mail')
            ->once()
            ->with(
                'donor@example.org',
                Mockery::type('string'),
                Mockery::type('string')
            )
            ->andReturn(true);

        $res = cdcf_rest_donation_acknowledgment($this->request());

        $this->assertSame(['success' => true], $res);
    }

    public function test_body_carries_the_english_substantiation_sentence(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['locale' => 'it']));

        // Never translated: it substantiates a US tax deduction.
        $this->assertStringContainsString(
            'No goods or services were provided in exchange for this contribution.',
            $captured
        );
    }

    public function test_body_is_localized_around_the_english_sentence(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['locale' => 'it']));

        $this->assertStringContainsString('Grazie', $captured);
    }

    public function test_falls_back_to_english_for_an_unknown_locale(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['locale' => 'ja']));

        $this->assertStringContainsString('Thank you', $captured);
    }

    public function test_formats_the_amount_as_dollars_not_cents(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['amount_cents' => 12345]));

        $this->assertStringContainsString('$123.45', $captured);
    }

    public function test_omits_the_designation_line_for_an_undesignated_gift(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request(['project_title' => '']));

        $this->assertStringNotContainsString('Designated', $captured);
    }

    public function test_includes_the_portal_link_for_a_recurring_gift(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_acknowledgment($this->request([
            'frequency'  => 'monthly',
            'portal_url' => 'https://billing.stripe.com/p/session/xyz',
        ]));

        $this->assertStringContainsString(
            'https://billing.stripe.com/p/session/xyz',
            $captured
        );
    }

    public function test_second_delivery_of_the_same_event_sends_nothing(): void
    {
        Functions\when('get_transient')->justReturn(1);
        Functions\expect('wp_mail')->never();

        $res = cdcf_rest_donation_acknowledgment($this->request());

        $this->assertSame(['success' => true, 'duplicate' => true], $res);
    }

    public function test_test_mode_redirects_mail_to_the_configured_inbox(): void
    {
        if (!defined('CDCF_DONATION_TEST_INBOX')) {
            define('CDCF_DONATION_TEST_INBOX', 'qa@example.org');
        }

        Functions\expect('wp_mail')
            ->once()
            ->with('qa@example.org', Mockery::type('string'), Mockery::type('string'))
            ->andReturn(true);

        $res = cdcf_rest_donation_acknowledgment($this->request(['livemode' => false]));

        $this->assertSame(['success' => true], $res);
    }

    public function test_returns_500_and_clears_the_guard_when_mail_fails(): void
    {
        Functions\when('wp_mail')->justReturn(false);
        // Without clearing the guard, Stripe's retry would be swallowed as
        // a duplicate and the acknowledgment lost forever.
        Functions\expect('delete_transient')->once();

        $res = cdcf_rest_donation_acknowledgment($this->request());

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('mail_failed', $res->get_error_code());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
composer test --working-dir=wordpress/themes/cdcf-headless -- --filter DonationAcknowledgmentHandlerTest
```

Expected: FAIL — `Call to undefined function cdcf_rest_donation_acknowledgment()`.

- [ ] **Step 3: Write the handler**

```php
<?php
/**
 * REST route handler for /cdcf/v1/donation-acknowledgment.
 *
 * Called server-to-server by the Next.js Stripe webhook handler, which
 * has already verified Stripe's signature. This file owns three things
 * the webhook deliberately does not:
 *
 *   1. Idempotency. Stripe retries a failed webhook for up to three days,
 *      so the guard lives here, at the side-effect boundary, rather than
 *      at the Next.js edge.
 *   2. The test-mode branch. Staging shares the PRODUCTION WordPress
 *      backend, so a staging webhook reaches this handler. Without the
 *      livemode check it would mail real donors from a test payment.
 *   3. All six locales of copy, including the English substantiation
 *      sentence that must never be translated.
 */

defined('ABSPATH') || exit;

/**
 * Shared permission callback for both donation endpoints.
 */
function cdcf_donation_permission_check(): bool {
    return current_user_can('edit_posts');
}

/**
 * Per-locale email copy. The substantiation sentence is deliberately
 * absent — it is appended in English regardless of locale.
 */
function cdcf_donation_email_strings(string $locale): array {
    $strings = [
        'en' => [
            'subject'   => 'Thank you for your gift to the Catholic Digital Commons Foundation',
            'intro'     => 'Thank you for your generous support. The details of your gift are below.',
            'amount'    => 'Amount',
            'date'      => 'Date',
            'frequency' => 'Frequency',
            'once'      => 'One-time gift',
            'monthly'   => 'Monthly gift',
            'project'   => 'Designated toward',
            'manage'    => 'You can update or cancel your monthly gift at any time here:',
        ],
        'it' => [
            'subject'   => 'Grazie per il tuo dono alla Catholic Digital Commons Foundation',
            'intro'     => 'Grazie per il tuo generoso sostegno. Di seguito i dettagli del tuo dono.',
            'amount'    => 'Importo',
            'date'      => 'Data',
            'frequency' => 'Frequenza',
            'once'      => 'Dono una tantum',
            'monthly'   => 'Dono mensile',
            'project'   => 'Destinato a',
            'manage'    => 'Puoi modificare o annullare il tuo dono mensile in qualsiasi momento qui:',
        ],
        'es' => [
            'subject'   => 'Gracias por su donativo a la Catholic Digital Commons Foundation',
            'intro'     => 'Gracias por su generoso apoyo. A continuación encontrará los detalles de su donativo.',
            'amount'    => 'Importe',
            'date'      => 'Fecha',
            'frequency' => 'Frecuencia',
            'once'      => 'Donativo único',
            'monthly'   => 'Donativo mensual',
            'project'   => 'Destinado a',
            'manage'    => 'Puede modificar o cancelar su donativo mensual en cualquier momento aquí:',
        ],
        'fr' => [
            'subject'   => 'Merci pour votre don à la Catholic Digital Commons Foundation',
            'intro'     => 'Merci pour votre généreux soutien. Les détails de votre don figurent ci-dessous.',
            'amount'    => 'Montant',
            'date'      => 'Date',
            'frequency' => 'Fréquence',
            'once'      => 'Don ponctuel',
            'monthly'   => 'Don mensuel',
            'project'   => 'Affecté à',
            'manage'    => 'Vous pouvez modifier ou annuler votre don mensuel à tout moment ici :',
        ],
        'pt' => [
            'subject'   => 'Obrigado pela sua doação à Catholic Digital Commons Foundation',
            'intro'     => 'Obrigado pelo seu generoso apoio. Os detalhes da sua doação estão abaixo.',
            'amount'    => 'Valor',
            'date'      => 'Data',
            'frequency' => 'Frequência',
            'once'      => 'Doação única',
            'monthly'   => 'Doação mensal',
            'project'   => 'Destinada a',
            'manage'    => 'Pode alterar ou cancelar a sua doação mensal a qualquer momento aqui:',
        ],
        'de' => [
            'subject'   => 'Vielen Dank für Ihre Spende an die Catholic Digital Commons Foundation',
            'intro'     => 'Vielen Dank für Ihre großzügige Unterstützung. Die Angaben zu Ihrer Spende finden Sie unten.',
            'amount'    => 'Betrag',
            'date'      => 'Datum',
            'frequency' => 'Häufigkeit',
            'once'      => 'Einmalige Spende',
            'monthly'   => 'Monatliche Spende',
            'project'   => 'Bestimmt für',
            'manage'    => 'Sie können Ihre monatliche Spende jederzeit hier ändern oder kündigen:',
        ],
    ];

    return $strings[$locale] ?? $strings['en'];
}

function cdcf_rest_donation_acknowledgment(WP_REST_Request $request) {
    $event_id  = (string) $request['event_id'];
    $guard_key = 'cdcf_stripe_evt_' . md5($event_id);

    // Stripe retries for up to three days. Without this, one transient
    // outage becomes a stack of duplicate thank-yous.
    if (get_transient($guard_key)) {
        return rest_ensure_response(['success' => true, 'duplicate' => true]);
    }
    set_transient($guard_key, 1, 3 * DAY_IN_SECONDS);

    $recipient = (string) $request['email'];

    // Staging shares the production WordPress backend, so a test-mode
    // event must never reach the real donor address.
    if (!$request['livemode']) {
        $test_inbox = defined('CDCF_DONATION_TEST_INBOX') ? CDCF_DONATION_TEST_INBOX : '';
        if ($test_inbox === '') {
            return rest_ensure_response([
                'success' => true,
                'skipped' => 'test_mode_no_inbox',
            ]);
        }
        $recipient = $test_inbox;
    }

    $s      = cdcf_donation_email_strings((string) $request['locale']);
    $amount = '$' . number_format(((int) $request['amount_cents']) / 100, 2);
    $date   = date_i18n('j F Y', (int) $request['occurred_at']);
    $freq   = $request['frequency'] === 'monthly' ? $s['monthly'] : $s['once'];

    $lines = [
        $s['intro'],
        '',
        $s['amount'] . ': ' . $amount,
        $s['date'] . ': ' . $date,
        $s['frequency'] . ': ' . $freq,
    ];

    $project_title = (string) $request['project_title'];
    if ($project_title !== '') {
        $lines[] = $s['project'] . ': ' . $project_title;
    }

    $portal_url = (string) $request['portal_url'];
    if ($request['frequency'] === 'monthly' && $portal_url !== '') {
        $lines[] = '';
        $lines[] = $s['manage'];
        $lines[] = $portal_url;
    }

    $lines[] = '';
    $lines[] = '—';
    $lines[] = 'Catholic Digital Commons Foundation';
    if (defined('CDCF_FOUNDATION_ADDRESS')) {
        $lines[] = CDCF_FOUNDATION_ADDRESS;
    }
    if (defined('CDCF_FOUNDATION_EIN')) {
        $lines[] = 'EIN: ' . CDCF_FOUNDATION_EIN;
    }
    $lines[] = '';
    // Never translated: this substantiates a US tax deduction for a US
    // taxpayer, and machine-translated legal boilerplate adds risk for
    // no benefit.
    $lines[] = 'No goods or services were provided in exchange for this contribution.';

    $sent = wp_mail($recipient, $s['subject'], implode("\n", $lines));

    if (!$sent) {
        // Clear the guard so Stripe's retry is actually processed rather
        // than swallowed as a duplicate.
        delete_transient($guard_key);
        return new WP_Error(
            'mail_failed',
            'Failed to send the donation acknowledgment.',
            ['status' => 500]
        );
    }

    return rest_ensure_response(['success' => true]);
}
```

- [ ] **Step 4: Register the route**

In `functions.php`, beside the `/submit-project` registration block, add the require:

```php
require_once __DIR__ . '/includes/handlers/donation-acknowledgment.php';
```

and inside a `rest_api_init` action:

```php
    register_rest_route('cdcf/v1', '/donation-acknowledgment', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_donation_acknowledgment',
        'permission_callback' => 'cdcf_donation_permission_check',
        'args' => [
            'event_id'      => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'livemode'      => ['required' => true,  'type' => 'boolean'],
            'email'         => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_email'],
            'donor_name'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'amount_cents'  => ['required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint'],
            'currency'      => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => 'usd'],
            'frequency'     => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'locale'        => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => 'en'],
            'project_title' => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'occurred_at'   => ['required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint'],
            'portal_url'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'esc_url_raw', 'default' => ''],
        ],
    ]);
```

- [ ] **Step 5: Load the handler in the test bootstrap**

In `wordpress/themes/cdcf-headless/tests/bootstrap.php`, after line 205, add:

```php
require_once __DIR__ . '/../includes/handlers/donation-acknowledgment.php';
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
composer test --working-dir=wordpress/themes/cdcf-headless -- --filter DonationAcknowledgmentHandlerTest
```

Expected: PASS, 11 tests.

- [ ] **Step 7: Document the endpoint**

In `CLAUDE.md`, add this row to the `cdcf/v1` endpoint table, and note in the prose above it that this endpoint requires `edit_posts`:

```markdown
| `POST` | `/donation-acknowledgment` | Mail a donor their gift acknowledgment. Called server-to-server by the Stripe webhook handler; owns idempotency (72h transient on the Stripe event id), the `livemode` test-mode branch, and all six locales of copy. |
```

- [ ] **Step 8: Run the full theme suite and commit**

```bash
composer test --working-dir=wordpress/themes/cdcf-headless
npm run format:md && npm run lint:md
git add wordpress/themes/cdcf-headless/includes/handlers/donation-acknowledgment.php \
        wordpress/themes/cdcf-headless/functions.php \
        wordpress/themes/cdcf-headless/tests/bootstrap.php \
        wordpress/themes/cdcf-headless/tests/DonationAcknowledgmentHandlerTest.php \
        CLAUDE.md
git commit -m "feat(donate): add the acknowledgment mailer with idempotency and a test-mode branch"
```

---

### Task 6: WordPress portal-link mailer

The second outbound email: a one-time link into Stripe's hosted Customer Portal. Separate endpoint because its abuse profile is different — it is reachable by anyone who knows a donor's email address, so it must never reveal whether that address gave.

**Files:**

- Create: `wordpress/themes/cdcf-headless/includes/handlers/donation-portal-link.php`
- Modify: `wordpress/themes/cdcf-headless/functions.php` — route registration beside Task 5's
- Modify: `wordpress/themes/cdcf-headless/tests/bootstrap.php` — add the `require_once`
- Modify: `CLAUDE.md` — endpoint table row
- Test: `wordpress/themes/cdcf-headless/tests/DonationPortalLinkHandlerTest.php`

**Interfaces:**

- Consumes: `cdcf_donation_permission_check()` and `cdcf_donation_email_strings()` from Task 5.
- Produces: `POST /cdcf/v1/donation-portal-link` taking `email`, `locale`, `portal_url`; returns `['success' => true]`. PHP function `cdcf_rest_donation_portal_link(WP_REST_Request $request)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/donation-portal-link handler.
 */
final class DonationPortalLinkHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('wp_mail')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function request(array $overrides = []): WP_REST_Request
    {
        $params = array_merge([
            'email'      => 'donor@example.org',
            'locale'     => 'en',
            'portal_url' => 'https://billing.stripe.com/p/session/xyz',
        ], $overrides);

        $req = new WP_REST_Request();
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }
        return $req;
    }

    public function test_mails_the_portal_link_to_the_donor(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        $res = cdcf_rest_donation_portal_link($this->request());

        $this->assertSame(['success' => true], $res);
        $this->assertStringContainsString(
            'https://billing.stripe.com/p/session/xyz',
            $captured
        );
    }

    public function test_localizes_the_message(): void
    {
        $captured = '';
        Functions\when('wp_mail')->alias(
            function ($to, $subject, $body) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        cdcf_rest_donation_portal_link($this->request(['locale' => 'de']));

        $this->assertStringContainsString('monatliche Spende', $captured);
    }

    public function test_sends_nothing_when_no_portal_url_was_resolved(): void
    {
        // The caller found no Stripe customer for this address. Sending
        // nothing is correct; the endpoint still reports success so the
        // response cannot be used to probe who has donated.
        Functions\expect('wp_mail')->never();

        $res = cdcf_rest_donation_portal_link($this->request(['portal_url' => '']));

        $this->assertSame(['success' => true], $res);
    }

    public function test_returns_500_when_mail_fails(): void
    {
        Functions\when('wp_mail')->justReturn(false);

        $res = cdcf_rest_donation_portal_link($this->request());

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('mail_failed', $res->get_error_code());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
composer test --working-dir=wordpress/themes/cdcf-headless -- --filter DonationPortalLinkHandlerTest
```

Expected: FAIL — `Call to undefined function cdcf_rest_donation_portal_link()`.

- [ ] **Step 3: Write the handler**

```php
<?php
/**
 * REST route handler for /cdcf/v1/donation-portal-link.
 *
 * Mails a donor a link into Stripe's hosted Customer Portal so they can
 * update or cancel a monthly gift without an account on this site.
 *
 * An empty portal_url means the caller found no Stripe customer for that
 * address. That is not an error: the endpoint reports success either way
 * so its response cannot be used to probe who has donated.
 */

defined('ABSPATH') || exit;

function cdcf_donation_portal_email_strings(string $locale): array {
    $strings = [
        'en' => [
            'subject' => 'Manage your monthly gift',
            'intro'   => 'Use the link below to update or cancel your monthly gift to the Catholic Digital Commons Foundation. It expires shortly, so open it soon.',
        ],
        'it' => [
            'subject' => 'Gestisci il tuo dono mensile',
            'intro'   => 'Usa il link qui sotto per modificare o annullare il tuo dono mensile alla Catholic Digital Commons Foundation. Il link scade a breve, quindi aprilo presto.',
        ],
        'es' => [
            'subject' => 'Gestione su donativo mensual',
            'intro'   => 'Use el enlace siguiente para modificar o cancelar su donativo mensual a la Catholic Digital Commons Foundation. Caduca en breve, ábralo pronto.',
        ],
        'fr' => [
            'subject' => 'Gérer votre don mensuel',
            'intro'   => 'Utilisez le lien ci-dessous pour modifier ou annuler votre don mensuel à la Catholic Digital Commons Foundation. Il expire bientôt, ouvrez-le rapidement.',
        ],
        'pt' => [
            'subject' => 'Gerir a sua doação mensal',
            'intro'   => 'Use a ligação abaixo para alterar ou cancelar a sua doação mensal à Catholic Digital Commons Foundation. Expira em breve, abra-a rapidamente.',
        ],
        'de' => [
            'subject' => 'Ihre monatliche Spende verwalten',
            'intro'   => 'Über den folgenden Link können Sie Ihre monatliche Spende an die Catholic Digital Commons Foundation ändern oder kündigen. Der Link läuft bald ab, öffnen Sie ihn zeitnah.',
        ],
    ];

    return $strings[$locale] ?? $strings['en'];
}

function cdcf_rest_donation_portal_link(WP_REST_Request $request) {
    $portal_url = (string) $request['portal_url'];

    // No customer matched. Report success without sending, so the
    // response is identical either way.
    if ($portal_url === '') {
        return rest_ensure_response(['success' => true]);
    }

    $s    = cdcf_donation_portal_email_strings((string) $request['locale']);
    $body = $s['intro'] . "\n\n" . $portal_url . "\n";

    $sent = wp_mail((string) $request['email'], $s['subject'], $body);

    if (!$sent) {
        return new WP_Error(
            'mail_failed',
            'Failed to send the portal link.',
            ['status' => 500]
        );
    }

    return rest_ensure_response(['success' => true]);
}
```

- [ ] **Step 4: Register the route**

In `functions.php`, add the require beside Task 5's:

```php
require_once __DIR__ . '/includes/handlers/donation-portal-link.php';
```

and the route inside the same `rest_api_init` action:

```php
    register_rest_route('cdcf/v1', '/donation-portal-link', [
        'methods'             => 'POST',
        'callback'            => 'cdcf_rest_donation_portal_link',
        'permission_callback' => 'cdcf_donation_permission_check',
        'args' => [
            'email'      => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email'],
            'locale'     => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'en'],
            'portal_url' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
        ],
    ]);
```

- [ ] **Step 5: Load the handler in the test bootstrap**

In `tests/bootstrap.php`, beside Task 5's line:

```php
require_once __DIR__ . '/../includes/handlers/donation-portal-link.php';
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
composer test --working-dir=wordpress/themes/cdcf-headless -- --filter DonationPortalLinkHandlerTest
```

Expected: PASS, 4 tests.

- [ ] **Step 7: Document the endpoint**

In `CLAUDE.md`, add to the endpoint table:

```markdown
| `POST` | `/donation-portal-link` | Mail a donor a one-time link into Stripe's Customer Portal. An empty `portal_url` (no matching Stripe customer) sends nothing and still reports success, so the response cannot reveal who has donated. |
```

- [ ] **Step 8: Run the full theme suite and commit**

```bash
composer test --working-dir=wordpress/themes/cdcf-headless
npm run format:md && npm run lint:md
git add wordpress/themes/cdcf-headless/includes/handlers/donation-portal-link.php \
        wordpress/themes/cdcf-headless/functions.php \
        wordpress/themes/cdcf-headless/tests/bootstrap.php \
        wordpress/themes/cdcf-headless/tests/DonationPortalLinkHandlerTest.php \
        CLAUDE.md
git commit -m "feat(donate): add the Customer Portal link mailer"
```

---

### Task 7: Stripe client and Checkout Session creation

The endpoint the form posts to. Enforces the cap server-side, resolves the designation against the live allowlist, and creates the session.

**Files:**

- Create: `lib/stripe.ts`
- Create: `lib/donate/wp-mailer.ts`
- Create: `app/api/donate/create-session/route.ts`
- Modify: `package.json` — add `stripe`

**Interfaces:**

- Consumes: Task 1's validators, Task 4's `getDonatableProjects`.
- Produces: `getStripe(): Stripe`, `postToWordPress(path: string, body: unknown): Promise<boolean>`, and `POST /api/donate/create-session` accepting `{ amountCents: number, frequency: 'once'|'monthly', projectSlug?: string|null, locale: string }` returning `{ url: string }` or `{ error: string }`.

- [ ] **Step 1: Install the dependency**

```bash
npm install stripe
```

- [ ] **Step 2: Write the Stripe client**

```typescript
// lib/stripe.ts
import "server-only";

import Stripe from "stripe";

let client: Stripe | null = null;

/**
 * Lazily constructed so importing this module in a context without the
 * secret (a build step, a test) does not throw at import time.
 */
export function getStripe(): Stripe {
  if (client) return client;

  const secret = process.env.STRIPE_SECRET_KEY;
  if (!secret) {
    throw new Error("STRIPE_SECRET_KEY is not set");
  }

  client = new Stripe(secret);
  return client;
}
```

- [ ] **Step 3: Write the WordPress mailer bridge**

```typescript
// lib/donate/wp-mailer.ts
import "server-only";

/**
 * Both donation emails are sent by WordPress via wp_mail, matching every
 * other transactional email in this repository. Returns false rather
 * than throwing so callers can decide whether the failure should be
 * surfaced to Stripe as a retryable error.
 */
export async function postToWordPress(
  path: string,
  body: unknown,
): Promise<boolean> {
  const base = process.env.WP_REST_URL;
  const user = process.env.WP_APP_USERNAME;
  const pass = process.env.WP_APP_PASSWORD;

  if (!base || !user || !pass) {
    console.error("WordPress credentials are not configured");
    return false;
  }

  try {
    const response = await fetch(`${base.replace(/\/$/, "")}/${path}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Basic ${Buffer.from(`${user}:${pass}`).toString("base64")}`,
      },
      body: JSON.stringify(body),
      cache: "no-store",
    });

    if (!response.ok) {
      console.error(`WordPress ${path} returned ${response.status}`);
      return false;
    }
    return true;
  } catch (error) {
    console.error(`WordPress ${path} request failed:`, error);
    return false;
  }
}
```

- [ ] **Step 4: Write the route**

```typescript
// app/api/donate/create-session/route.ts
import { NextRequest, NextResponse } from "next/server";
import type Stripe from "stripe";

import { getStripe } from "@/lib/stripe";
import {
  isDonationFrequency,
  validateAmountCents,
  validateProjectSlug,
} from "@/lib/donate/validation";
import { getDonatableProjects } from "@/lib/wordpress/api";
import { defaultLocale, locales } from "@/src/i18n/routing";

export const runtime = "nodejs";

// Card testing is the main abuse vector on a public donation form, and
// it shows up as a burst of session creations. Same shape as the limiter
// in app/api/submit-project/route.ts.
const rateMap = new Map<string, number[]>();
const RATE_LIMIT = 10;
const RATE_WINDOW = 60 * 60 * 1000;

function isRateLimited(ip: string): boolean {
  const now = Date.now();
  const recent = (rateMap.get(ip) ?? []).filter((t) => now - t < RATE_WINDOW);
  rateMap.set(ip, recent);
  if (recent.length >= RATE_LIMIT) return true;
  recent.push(now);
  rateMap.set(ip, recent);
  return false;
}

export async function POST(request: NextRequest) {
  const ip =
    request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
    request.headers.get("x-real-ip") ||
    "unknown";

  if (isRateLimited(ip)) {
    return NextResponse.json(
      { error: "Too many attempts. Please try again later." },
      { status: 429 },
    );
  }

  let body: Record<string, unknown>;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json(
      { error: "Invalid request body." },
      { status: 400 },
    );
  }

  const locale = String(body.locale ?? defaultLocale);
  if (!(locales as readonly string[]).includes(locale)) {
    return NextResponse.json({ error: "Unsupported locale." }, { status: 400 });
  }

  const amount = validateAmountCents(body.amountCents);
  if (!amount.ok) {
    // above_maximum is a routing decision in the UI, not a user error —
    // but the server still refuses it, since the form can be bypassed.
    return NextResponse.json({ error: amount.reason }, { status: 400 });
  }

  if (!isDonationFrequency(body.frequency)) {
    return NextResponse.json(
      { error: "Unsupported frequency." },
      { status: 400 },
    );
  }
  const frequency = body.frequency;

  const donatable = await getDonatableProjects(locale);
  const designation = validateProjectSlug(
    body.projectSlug,
    donatable.map((p) => p.slug),
  );
  if (!designation.ok) {
    return NextResponse.json({ error: designation.reason }, { status: 400 });
  }

  const project = designation.slug
    ? donatable.find((p) => p.slug === designation.slug)
    : undefined;

  const origin = process.env.NEXT_PUBLIC_SITE_URL;
  if (!origin) {
    console.error("NEXT_PUBLIC_SITE_URL is not set");
    return NextResponse.json(
      { error: "Server configuration error." },
      { status: 500 },
    );
  }

  const donatePath = locale === defaultLocale ? "/donate" : `/${locale}/donate`;

  // Metadata must be set on the subscription too: session metadata does
  // NOT propagate, so renewals would otherwise arrive undesignated.
  const metadata: Record<string, string> = {
    project_slug: designation.slug ?? "",
    project_title: project?.title ?? "",
    locale,
    frequency,
  };

  const isRecurring = frequency === "monthly";

  const params: Stripe.Checkout.SessionCreateParams = {
    mode: isRecurring ? "subscription" : "payment",
    locale: locale as Stripe.Checkout.SessionCreateParams.Locale,
    line_items: [
      {
        quantity: 1,
        price_data: {
          currency: "usd",
          unit_amount: amount.amountCents,
          product_data: {
            name: project
              ? `Donation — ${project.title}`
              : "Donation — Catholic Digital Commons Foundation",
          },
          ...(isRecurring ? { recurring: { interval: "month" as const } } : {}),
        },
      },
    ],
    metadata,
    ...(isRecurring
      ? { subscription_data: { metadata } }
      : { submit_type: "donate" as const }),
    success_url: `${origin}${donatePath}?status=success`,
    cancel_url: `${origin}${donatePath}?status=cancelled`,
  };

  try {
    const session = await getStripe().checkout.sessions.create(params);
    if (!session.url) {
      return NextResponse.json(
        { error: "Checkout unavailable." },
        { status: 502 },
      );
    }
    return NextResponse.json({ url: session.url });
  } catch (error) {
    console.error("Failed to create Checkout Session:", error);
    return NextResponse.json(
      { error: "Checkout unavailable." },
      { status: 502 },
    );
  }
}
```

- [ ] **Step 5: Verify the build and types**

```bash
npx tsc --noEmit
npm run lint
```

Expected: no TypeScript errors, no ESLint errors.

- [ ] **Step 6: Verify the cap is enforced against a hand-written body**

Start the dev server (`npm run dev`), then:

```bash
curl -s -X POST http://localhost:3000/api/donate/create-session \
  -H 'Content-Type: application/json' \
  -d '{"amountCents":50000,"frequency":"once","locale":"en"}'
```

Expected: `{"error":"above_maximum"}` with HTTP 400 — no Stripe call made.

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json lib/stripe.ts lib/donate/wp-mailer.ts app/api/donate/create-session/route.ts
git commit -m "feat(donate): create Checkout Sessions with a server-enforced \$200 cap"
```

---

### Task 8: Stripe webhook receiver

The only thing that triggers an acknowledgment. Verifies the signature, applies Task 2's routing rule, and hands off to WordPress — propagating failure so Stripe retries.

**Files:**

- Create: `app/api/donate/webhook/route.ts`

**Interfaces:**

- Consumes: `getStripe()` from Task 7, `shouldAcknowledge` / `buildAcknowledgmentPayload` from Task 2, `postToWordPress` from Task 7.
- Produces: `POST /api/donate/webhook`.

- [ ] **Step 1: Write the route**

```typescript
// app/api/donate/webhook/route.ts
import { NextRequest, NextResponse } from "next/server";
import type Stripe from "stripe";

import {
  buildAcknowledgmentPayload,
  shouldAcknowledge,
} from "@/lib/donate/acknowledgment";
import type { DonationFrequency } from "@/lib/donate/validation";
import { postToWordPress } from "@/lib/donate/wp-mailer";
import { getStripe } from "@/lib/stripe";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  const signature = request.headers.get("stripe-signature");
  const secret = process.env.STRIPE_WEBHOOK_SECRET;

  if (!signature || !secret) {
    return NextResponse.json({ error: "Not configured." }, { status: 400 });
  }

  // Signature verification needs the exact bytes Stripe signed, so read
  // the raw text rather than request.json().
  const raw = await request.text();

  let event: Stripe.Event;
  try {
    event = getStripe().webhooks.constructEvent(raw, signature, secret);
  } catch (error) {
    console.error("Stripe webhook signature verification failed:", error);
    return NextResponse.json({ error: "Invalid signature." }, { status: 400 });
  }

  let mode: "payment" | "subscription" | null = null;
  if (event.type === "checkout.session.completed") {
    mode = (event.data.object as Stripe.Checkout.Session).mode as
      "payment" | "subscription";
  }

  if (!shouldAcknowledge(event.type, mode)) {
    return NextResponse.json({ received: true });
  }

  let email = "";
  let donorName = "";
  let amountCents = 0;
  let metadata: Record<string, string> = {};
  let customerId: string | null = null;

  if (event.type === "checkout.session.completed") {
    const session = event.data.object as Stripe.Checkout.Session;
    email = session.customer_details?.email ?? "";
    donorName = session.customer_details?.name ?? "";
    amountCents = session.amount_total ?? 0;
    metadata = (session.metadata ?? {}) as Record<string, string>;
    customerId = typeof session.customer === "string" ? session.customer : null;
  } else {
    const invoice = event.data.object as Stripe.Invoice;
    email = invoice.customer_email ?? "";
    donorName = invoice.customer_name ?? "";
    amountCents = invoice.amount_paid ?? 0;
    // Set from subscription_data.metadata at session creation, so it
    // survives onto every renewal invoice.
    metadata = (invoice.subscription_details?.metadata ?? {}) as Record<
      string,
      string
    >;
    customerId = typeof invoice.customer === "string" ? invoice.customer : null;
  }

  if (!email) {
    console.error(`Stripe event ${event.id} carried no donor email`);
    return NextResponse.json({ received: true });
  }

  const frequency: DonationFrequency =
    metadata.frequency === "monthly" ? "monthly" : "once";

  let portalUrl = "";
  if (frequency === "monthly" && customerId) {
    try {
      const portal = await getStripe().billingPortal.sessions.create({
        customer: customerId,
        return_url: process.env.NEXT_PUBLIC_SITE_URL ?? undefined,
      });
      portalUrl = portal.url;
    } catch (error) {
      // Non-fatal: the acknowledgment is still worth sending without it.
      console.error("Failed to create a billing portal session:", error);
    }
  }

  const payload = buildAcknowledgmentPayload({
    eventId: event.id,
    livemode: event.livemode,
    email,
    donorName,
    amountCents,
    frequency,
    locale: metadata.locale ?? "en",
    projectTitle: metadata.project_title ?? "",
    occurredAt: event.created,
    portalUrl,
  });

  const sent = await postToWordPress(
    "cdcf/v1/donation-acknowledgment",
    payload,
  );

  if (!sent) {
    // Deliberately non-2xx: returning 200 on a failed email would lose
    // the acknowledgment permanently. Stripe retries for three days.
    return NextResponse.json(
      { error: "Acknowledgment delivery failed." },
      { status: 500 },
    );
  }

  return NextResponse.json({ received: true });
}
```

- [ ] **Step 2: Verify types and lint**

```bash
npx tsc --noEmit
npm run lint
```

Expected: clean.

- [ ] **Step 3: Verify signature rejection**

With the dev server running:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:3000/api/donate/webhook \
  -H 'Content-Type: application/json' \
  -H 'stripe-signature: t=1,v1=deadbeef' \
  -d '{"id":"evt_forged","type":"checkout.session.completed"}'
```

Expected: `400`. A forged event must never reach WordPress.

- [ ] **Step 4: Verify the real path with the Stripe CLI**

In one terminal:

```bash
stripe listen --forward-to localhost:3000/api/donate/webhook
```

Copy the printed `whsec_…` into `.env.local` as `STRIPE_WEBHOOK_SECRET`, restart the dev server, then in another terminal:

```bash
stripe trigger checkout.session.completed
```

Expected: the listener reports `200`, and the dev-server log shows no error. (The triggered fixture has no donation metadata, so it exercises signature verification and routing, not the full payload.)

- [ ] **Step 5: Commit**

```bash
git add app/api/donate/webhook/route.ts
git commit -m "feat(donate): receive Stripe webhooks and hand acknowledgments to WordPress"
```

---

### Task 9: Manage-your-giving portal route

Lets a recurring donor get back into Stripe's portal from an email address alone, without revealing whether that address ever gave.

**Files:**

- Create: `app/api/donate/portal/route.ts`

**Interfaces:**

- Consumes: `getStripe()` and `postToWordPress` from Task 7.
- Produces: `POST /api/donate/portal` accepting `{ email: string, locale: string }`, always returning `{ success: true }`.

- [ ] **Step 1: Write the route**

```typescript
// app/api/donate/portal/route.ts
import { NextRequest, NextResponse } from "next/server";

import { postToWordPress } from "@/lib/donate/wp-mailer";
import { getStripe } from "@/lib/stripe";
import { defaultLocale, locales } from "@/src/i18n/routing";

export const runtime = "nodejs";

const rateMap = new Map<string, number[]>();
const RATE_LIMIT = 5;
const RATE_WINDOW = 60 * 60 * 1000;

function isRateLimited(ip: string): boolean {
  const now = Date.now();
  const recent = (rateMap.get(ip) ?? []).filter((t) => now - t < RATE_WINDOW);
  rateMap.set(ip, recent);
  if (recent.length >= RATE_LIMIT) return true;
  recent.push(now);
  rateMap.set(ip, recent);
  return false;
}

/**
 * Always responds { success: true }, whether or not the address matched a
 * Stripe customer. A response that differed would let anyone probe who
 * has donated.
 */
export async function POST(request: NextRequest) {
  const ip =
    request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
    request.headers.get("x-real-ip") ||
    "unknown";

  if (isRateLimited(ip)) {
    return NextResponse.json(
      { error: "Too many requests. Please try again later." },
      { status: 429 },
    );
  }

  let body: Record<string, unknown>;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json(
      { error: "Invalid request body." },
      { status: 400 },
    );
  }

  const email = typeof body.email === "string" ? body.email.trim() : "";
  if (!email) {
    return NextResponse.json({ error: "Email is required." }, { status: 400 });
  }

  const locale = String(body.locale ?? defaultLocale);
  const safeLocale = (locales as readonly string[]).includes(locale)
    ? locale
    : defaultLocale;

  let portalUrl = "";
  try {
    const stripe = getStripe();
    const customers = await stripe.customers.list({ email, limit: 1 });
    const customer = customers.data[0];

    if (customer) {
      const portal = await stripe.billingPortal.sessions.create({
        customer: customer.id,
        return_url: process.env.NEXT_PUBLIC_SITE_URL ?? undefined,
      });
      portalUrl = portal.url;
    }
  } catch (error) {
    // Swallowed on purpose: an error here must not distinguish a known
    // address from an unknown one.
    console.error("Failed to resolve a billing portal session:", error);
  }

  await postToWordPress("cdcf/v1/donation-portal-link", {
    email,
    locale: safeLocale,
    portal_url: portalUrl,
  });

  return NextResponse.json({ success: true });
}
```

- [ ] **Step 2: Verify types and lint**

```bash
npx tsc --noEmit
npm run lint
```

Expected: clean.

- [ ] **Step 3: Verify the uniform response**

With the dev server running and Stripe test keys configured:

```bash
curl -s -X POST http://localhost:3000/api/donate/portal \
  -H 'Content-Type: application/json' \
  -d '{"email":"definitely-not-a-donor@example.org","locale":"en"}'
```

Expected: `{"success":true}` — identical to the response for a real donor.

- [ ] **Step 4: Commit**

```bash
git add app/api/donate/portal/route.ts
git commit -m "feat(donate): add the manage-your-giving portal link endpoint"
```

---

### Task 10: The donation form and page section

The visible half. A client component for the form, a server component that reads ACF, `PageRenderer` wiring, and the UI strings in all six locales.

**Files:**

- Create: `components/sections/DonationForm.tsx`
- Create: `components/sections/DonationSection.tsx`
- Modify: `components/sections/PageRenderer.tsx:17` (imports), `:40-63` (dispatch), plus a new `renderDonate` function
- Modify: `components/Footer.tsx:93-107` — Liberapay link
- Modify: `app/[lang]/[[...slug]]/page.tsx` — fetch donatable projects for the Donate template
- Modify: `messages/en.json`, `messages/it.json`, `messages/es.json`, `messages/fr.json`, `messages/pt.json`, `messages/de.json`

**Interfaces:**

- Consumes: `WPPage.donateFields` and `getDonatableProjects` from Task 4; `POST /api/donate/create-session` from Task 7; `MIN_DONATION_CENTS` / `MAX_DONATION_CENTS` from Task 1.
- Produces: `renderDonate(page: WPPage, projects: DonatableProject[], searchParams: { status?: string })`.

- [ ] **Step 1: Add the UI strings**

Add this `donate` block to each `messages/*.json`. English:

```json
  "donate": {
    "amountLabel": "Amount (USD)",
    "frequencyOnce": "One-time",
    "frequencyMonthly": "Monthly",
    "designationLabel": "Support (optional)",
    "designationGeneral": "Where it's needed most",
    "submit": "Donate now",
    "submitting": "Redirecting…",
    "belowMinimum": "The minimum gift is $5.",
    "aboveMaximum": "Gifts above $200 are arranged directly with our administrative offices.",
    "invalidAmount": "Please enter a whole dollar amount.",
    "failed": "We could not start the checkout. Please try again.",
    "cancelled": "Your donation was cancelled. Nothing has been charged."
  }
```

Italian:

```json
  "donate": {
    "amountLabel": "Importo (USD)",
    "frequencyOnce": "Una tantum",
    "frequencyMonthly": "Mensile",
    "designationLabel": "Sostieni (facoltativo)",
    "designationGeneral": "Dove c'è più bisogno",
    "submit": "Dona ora",
    "submitting": "Reindirizzamento…",
    "belowMinimum": "Il dono minimo è di 5 $.",
    "aboveMaximum": "I doni superiori a 200 $ si concordano direttamente con i nostri uffici amministrativi.",
    "invalidAmount": "Inserisci un importo in dollari interi.",
    "failed": "Non è stato possibile avviare il pagamento. Riprova.",
    "cancelled": "La tua donazione è stata annullata. Non è stato addebitato nulla."
  }
```

Spanish:

```json
  "donate": {
    "amountLabel": "Importe (USD)",
    "frequencyOnce": "Único",
    "frequencyMonthly": "Mensual",
    "designationLabel": "Apoyar (opcional)",
    "designationGeneral": "Donde más se necesite",
    "submit": "Donar ahora",
    "submitting": "Redirigiendo…",
    "belowMinimum": "El donativo mínimo es de 5 $.",
    "aboveMaximum": "Los donativos superiores a 200 $ se gestionan directamente con nuestras oficinas administrativas.",
    "invalidAmount": "Introduzca un importe en dólares enteros.",
    "failed": "No se ha podido iniciar el pago. Inténtelo de nuevo.",
    "cancelled": "Su donativo se ha cancelado. No se ha cobrado nada."
  }
```

French:

```json
  "donate": {
    "amountLabel": "Montant (USD)",
    "frequencyOnce": "Ponctuel",
    "frequencyMonthly": "Mensuel",
    "designationLabel": "Soutenir (facultatif)",
    "designationGeneral": "Là où le besoin est le plus grand",
    "submit": "Faire un don",
    "submitting": "Redirection…",
    "belowMinimum": "Le don minimum est de 5 $.",
    "aboveMaximum": "Les dons supérieurs à 200 $ sont organisés directement avec nos bureaux administratifs.",
    "invalidAmount": "Veuillez saisir un montant en dollars entiers.",
    "failed": "Le paiement n'a pas pu être lancé. Veuillez réessayer.",
    "cancelled": "Votre don a été annulé. Rien n'a été débité."
  }
```

Portuguese:

```json
  "donate": {
    "amountLabel": "Valor (USD)",
    "frequencyOnce": "Única",
    "frequencyMonthly": "Mensal",
    "designationLabel": "Apoiar (opcional)",
    "designationGeneral": "Onde for mais necessário",
    "submit": "Doar agora",
    "submitting": "A redirecionar…",
    "belowMinimum": "A doação mínima é de 5 $.",
    "aboveMaximum": "Doações acima de 200 $ são tratadas diretamente com os nossos serviços administrativos.",
    "invalidAmount": "Introduza um valor em dólares inteiros.",
    "failed": "Não foi possível iniciar o pagamento. Tente novamente.",
    "cancelled": "A sua doação foi cancelada. Nada foi cobrado."
  }
```

German:

```json
  "donate": {
    "amountLabel": "Betrag (USD)",
    "frequencyOnce": "Einmalig",
    "frequencyMonthly": "Monatlich",
    "designationLabel": "Unterstützen (optional)",
    "designationGeneral": "Wo es am nötigsten ist",
    "submit": "Jetzt spenden",
    "submitting": "Weiterleitung…",
    "belowMinimum": "Die Mindestspende beträgt 5 $.",
    "aboveMaximum": "Spenden über 200 $ werden direkt mit unserer Verwaltung abgestimmt.",
    "invalidAmount": "Bitte geben Sie einen ganzen Dollarbetrag ein.",
    "failed": "Die Zahlung konnte nicht gestartet werden. Bitte versuchen Sie es erneut.",
    "cancelled": "Ihre Spende wurde abgebrochen. Es wurde nichts abgebucht."
  }
```

- [ ] **Step 2: Write the form component**

```tsx
// components/sections/DonationForm.tsx
"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import clsx from "clsx";

import {
  MAX_DONATION_CENTS,
  MIN_DONATION_CENTS,
  type DonationFrequency,
} from "@/lib/donate/validation";
import type { DonatableProject } from "@/lib/wordpress/api";

interface DonationFormProps {
  locale: string;
  projects: DonatableProject[];
  presetAmounts: number[];
  aboveCapHtml: string;
}

export default function DonationForm({
  locale,
  projects,
  presetAmounts,
  aboveCapHtml,
}: DonationFormProps) {
  const t = useTranslations("donate");
  const [dollars, setDollars] = useState<string>(
    presetAmounts[0] ? String(presetAmounts[0]) : "25",
  );
  const [frequency, setFrequency] = useState<DonationFrequency>("once");
  const [projectSlug, setProjectSlug] = useState<string>("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string>("");

  const parsed = Number(dollars);
  const amountCents = Number.isInteger(parsed) ? parsed * 100 : Number.NaN;
  const aboveCap =
    Number.isFinite(amountCents) && amountCents > MAX_DONATION_CENTS;
  const belowMin =
    Number.isFinite(amountCents) && amountCents < MIN_DONATION_CENTS;

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError("");

    if (!Number.isInteger(parsed)) {
      setError(t("invalidAmount"));
      return;
    }
    if (belowMin) {
      setError(t("belowMinimum"));
      return;
    }

    setSubmitting(true);
    try {
      const response = await fetch("/api/donate/create-session", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          amountCents,
          frequency,
          projectSlug: projectSlug || null,
          locale,
        }),
      });
      const data = await response.json();
      if (!response.ok || !data.url) {
        setError(t("failed"));
        setSubmitting(false);
        return;
      }
      window.location.href = data.url;
    } catch {
      setError(t("failed"));
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="mx-auto max-w-lg space-y-6">
      <div className="flex gap-2">
        {(["once", "monthly"] as const).map((option) => (
          <button
            key={option}
            type="button"
            onClick={() => setFrequency(option)}
            className={clsx(
              "flex-1 rounded-md border px-4 py-2 text-sm",
              frequency === option
                ? "cdcf-btn-primary"
                : "border-gray-300 bg-white",
            )}
          >
            {option === "once" ? t("frequencyOnce") : t("frequencyMonthly")}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        {presetAmounts.map((preset) => (
          <button
            key={preset}
            type="button"
            onClick={() => setDollars(String(preset))}
            className={clsx(
              "rounded-md border px-4 py-2 text-sm",
              dollars === String(preset)
                ? "cdcf-btn-primary"
                : "border-gray-300 bg-white",
            )}
          >
            ${preset}
          </button>
        ))}
      </div>

      <label className="block">
        <span className="mb-1 block text-sm font-medium">
          {t("amountLabel")}
        </span>
        <input
          type="number"
          inputMode="numeric"
          min={MIN_DONATION_CENTS / 100}
          step={1}
          value={dollars}
          onChange={(e) => setDollars(e.target.value)}
          className="w-full rounded-md border border-gray-300 px-3 py-2"
        />
      </label>

      {projects.length > 0 && (
        <label className="block">
          <span className="mb-1 block text-sm font-medium">
            {t("designationLabel")}
          </span>
          <select
            value={projectSlug}
            onChange={(e) => setProjectSlug(e.target.value)}
            className="w-full rounded-md border border-gray-300 px-3 py-2"
          >
            <option value="">{t("designationGeneral")}</option>
            {projects.map((project) => (
              <option key={project.slug} value={project.slug}>
                {project.title}
              </option>
            ))}
          </select>
        </label>
      )}

      {aboveCap ? (
        <div
          className="prose rounded-md bg-amber-50 p-4"
          dangerouslySetInnerHTML={{ __html: aboveCapHtml }}
        />
      ) : (
        <button
          type="submit"
          disabled={submitting}
          className="cdcf-btn-primary w-full disabled:opacity-60"
        >
          {submitting ? t("submitting") : t("submit")}
        </button>
      )}

      {error && <p className="text-sm text-red-700">{error}</p>}
    </form>
  );
}
```

- [ ] **Step 3: Write the section component**

```tsx
// components/sections/DonationSection.tsx
import { getTranslations } from "next-intl/server";

import { MAX_DONATION_CENTS } from "@/lib/donate/validation";
import type { DonatableProject } from "@/lib/wordpress/api";
import type { WPPage } from "@/lib/wordpress/types";

import DonationForm from "./DonationForm";

interface DonationSectionProps {
  page: WPPage;
  locale: string;
  projects: DonatableProject[];
  status?: string;
}

/** "10,25,50,100" -> [10, 25, 50, 100], dropping anything above the cap. */
function parsePresets(raw: string | null): number[] {
  const presets = (raw ?? "10,25,50,100")
    .split(",")
    .map((part) => Number(part.trim()))
    .filter(
      (value) =>
        Number.isInteger(value) &&
        value > 0 &&
        value * 100 <= MAX_DONATION_CENTS,
    );
  return presets.length > 0 ? presets : [10, 25, 50, 100];
}

export default async function DonationSection({
  page,
  locale,
  projects,
  status,
}: DonationSectionProps) {
  const t = await getTranslations("donate");
  const fields = page.donateFields;

  // The redirect is a UI cue only — the webhook is what actually
  // acknowledges the gift.
  if (status === "success") {
    return (
      <section className="cdcf-section">
        <div
          className="prose mx-auto"
          dangerouslySetInnerHTML={{
            __html: fields?.donateThankYouBody ?? "",
          }}
        />
      </section>
    );
  }

  return (
    <section className="cdcf-section">
      {fields?.donateAppealBody && (
        <div
          className="prose mx-auto mb-8"
          dangerouslySetInnerHTML={{ __html: fields.donateAppealBody }}
        />
      )}

      {status === "cancelled" && (
        <p className="mx-auto mb-6 max-w-lg text-sm text-gray-700">
          {t("cancelled")}
        </p>
      )}

      <DonationForm
        locale={locale}
        projects={projects}
        presetAmounts={parsePresets(fields?.donatePresetAmounts ?? null)}
        aboveCapHtml={fields?.donateAboveCapBody ?? ""}
      />

      {fields?.donateTaxDisclaimer && (
        <div
          className="prose mx-auto mt-8 text-sm"
          dangerouslySetInnerHTML={{ __html: fields.donateTaxDisclaimer }}
        />
      )}
    </section>
  );
}
```

- [ ] **Step 4: Wire it into PageRenderer**

Add the import beside the others in `components/sections/PageRenderer.tsx`:

```typescript
import DonationSection from "./DonationSection";
import type { DonatableProject } from "@/lib/wordpress/api";
```

Add three props to `PageRendererProps`:

```typescript
  donatableProjects?: DonatableProject[]
  locale?: string
  status?: string
```

Destructure them in the signature with defaults (`donatableProjects = []`, `locale = 'en'`, `status = undefined`), add the dispatch line after the `Contact` line:

```tsx
{
  template === "Donate" &&
    (await renderDonate(page, locale, donatableProjects, status));
}
```

and add the render function beside `renderContact`:

```tsx
async function renderDonate(
  page: WPPage,
  locale: string,
  projects: DonatableProject[],
  status?: string,
) {
  return (
    <DonationSection
      page={page}
      locale={locale}
      projects={projects}
      status={status}
    />
  );
}
```

- [ ] **Step 5: Fetch the projects in the page route**

In `app/[lang]/[[...slug]]/page.tsx`:

1. Add `getDonatableProjects` to the existing `@/lib/wordpress/api` import on line 4.
2. `template` is already computed on line 71. Add a sixth element to the
   `Promise.all` destructuring on line 75, following the same
   conditional shape the neighbouring entries use:

   ```typescript
   const [
     posts,
     projects,
     sponsors,
     fishExplanation,
     childPages,
     donatableProjects,
   ] = await Promise.all([
     // ...existing five entries unchanged...
     template === "Donate" ? getDonatableProjects(lang) : Promise.resolve([]),
   ]);
   ```

3. Pass the three new props to `<PageRenderer>`:

   ```tsx
   donatableProjects={donatableProjects}
   locale={lang}
   status={
     typeof resolvedSearchParams?.status === 'string'
       ? resolvedSearchParams.status
       : undefined
   }
   ```

If the component does not already receive `searchParams`, add it to the
route's props and `await` it — in Next.js 16 `searchParams` is a Promise:

```typescript
export default async function Page({ params, searchParams }: {
  params: Promise<{ lang: string; slug?: string[] }>
  searchParams: Promise<Record<string, string | string[] | undefined>>
}) {
  const resolvedSearchParams = await searchParams
```

- [ ] **Step 6: Verify build, lint, and tests**

```bash
npx tsc --noEmit
npm run lint
npm run build
npm test
```

Expected: all clean.

- [ ] **Step 7: Verify in the browser**

Start the stack (`docker compose up --build`), create a page in wp-admin with slug `donate` and template `Donate`, fill the ACF fields, tick "Accepts Donations" on at least one project, then visit `http://localhost:3000/donate` and `http://localhost:3000/it/donate`.

Expected: the appeal copy renders, the designation picker lists only flagged projects with localized titles, entering `250` swaps the button for the above-cap panel, and entering `50` redirects to a Stripe Checkout page rendered in the page's language.

- [ ] **Step 8: Add the Liberapay footer link**

The spec keeps Liberapay as a secondary, unintegrated channel. In
`components/Footer.tsx`, add one entry to the community link list beside
the existing GitHub and Discord anchors (`components/Footer.tsx:93-107`),
matching their classes exactly:

```tsx
<a
  href="https://liberapay.com/CatholicDigitalCommons"
  target="_blank"
  rel="noopener noreferrer"
  className="text-sm text-gray-300 transition-colors hover:text-white"
>
  Liberapay
</a>
```

"Liberapay" is a brand name, so it is not translated and needs no
`messages/*.json` key. Confirm the account exists at that URL before
committing; if it does not yet, skip this step and note it as
outstanding rather than shipping a dead link.

- [ ] **Step 9: Commit**

```bash
git add components/sections/DonationForm.tsx components/sections/DonationSection.tsx \
        components/sections/PageRenderer.tsx components/Footer.tsx \
        "app/[lang]/[[...slug]]/page.tsx" messages/
git commit -m "feat(donate): render the donation form under the Donate page template"
```

---

### Task 11: Configuration, documentation and deploy runbook

The steps that make the feature real outside a dev machine, and the constants the acknowledgment email needs.

**Files:**

- Modify: `.env.example` (and `.env.staging.example`, `.env.production.example` if the repo has them)
- Modify: `README.md` — environment variable section
- Modify: `CLAUDE.md` — environment variable section
- Create: `docs/donations.md`

**Interfaces:**

- Consumes: every prior task.
- Produces: no code.

- [ ] **Step 1: Add the environment variables to the examples**

```bash
# Stripe (donations). Use test-mode keys everywhere except production.
STRIPE_SECRET_KEY=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
```

- [ ] **Step 2: Document the WordPress constants**

Add to `docs/donations.md` and to the README env section — these go in `wp-config.php` on the WordPress host:

```php
// Donation acknowledgment email.
define('CDCF_FOUNDATION_EIN', '00-0000000');
define('CDCF_FOUNDATION_ADDRESS', '000 Example St, City, ST 00000, USA');

// Staging shares this backend. Without this, a test-mode Stripe event
// would mail the real donor; with it, test-mode mail is redirected here.
define('CDCF_DONATION_TEST_INBOX', 'qa@example.org');
```

- [ ] **Step 3: Write the runbook**

Create `docs/donations.md` covering:

1. **Stripe Dashboard setup** — apply for the [nonprofit fee discount](https://support.stripe.com/questions/fee-discount-for-nonprofit-organizations) with the EIN or IRS determination letter; enable Apple Pay and Google Pay by verifying `catholicdigitalcommons.org` under Settings → Payment method domains; confirm Radar is active and add a rule blocking high-velocity attempts from one IP.
2. **Webhook endpoints** — register `https://catholicdigitalcommons.org/api/donate/webhook` in live mode and the staging URL in test mode, subscribing each to `checkout.session.completed` and `invoice.paid` only. Record each signing secret into the matching environment.
3. **Customer Portal** — enable it in Settings → Billing → Customer portal, allowing subscription cancellation and payment-method updates.
4. **Content setup** — create the `donate` page per locale with the `Donate` template, fill all five ACF fields, and tick "Accepts Donations" on the projects that should appear.
5. **Deploy** — `gh workflow run deploy.yml -f environment=production`, then confirm the WordPress steps ran:

   ```bash
   gh run view <run-id> --json jobs \
     -q '.jobs[].steps[] | select(.name|test("WP theme|OPcache|plugins")) | "\(.conclusion)\t\(.name)"'
   ```

6. **Post-deploy verification** — make a live $5 one-time gift and a live $5 monthly gift, confirm both acknowledgment emails arrive with the substantiation sentence, then cancel the subscription through the emailed portal link and refund both in the Dashboard.
7. **Monitoring** — in the Stripe Dashboard, Payments filtered to `Failed`. A card-testing burst reads as dozens of declines within minutes, mostly at the same amount, from many different cards and one or few IPs. Successful charges stay flat while declines spike. The response is to tighten the Radar velocity rule and, if needed, temporarily raise the donation floor.

- [ ] **Step 4: Verify the markdown gates**

```bash
npm run format:md && npm run lint:md
```

Expected: prettier rewrites as needed; markdownlint reports 0 issues.

- [ ] **Step 5: Commit**

```bash
git add .env.example README.md CLAUDE.md docs/donations.md
git commit -m "docs(donate): add the donation runbook, env vars and WordPress constants"
```

---

## Notes for the reviewer

Three things in this plan are load-bearing and worth checking first if something looks wrong:

1. **The `shouldAcknowledge` rule (Task 2).** If it is inverted, every first recurring gift is thanked twice. The test named `does NOT acknowledge a subscription on checkout.session.completed` is the guard.
2. **`delete_transient` on mail failure (Task 5).** Without it, a failed send poisons the idempotency guard and Stripe's retry is silently swallowed as a duplicate — the acknowledgment is then lost permanently.
3. **`subscription_data.metadata` (Task 7).** Session metadata does not propagate to the subscription. Omitting this makes every renewal arrive with no designation, which is invisible until the second month.
