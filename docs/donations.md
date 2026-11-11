# Donations

The `/donate` page takes one-time and monthly gifts through Stripe-hosted
Checkout, capped at $200 per contribution. Design:
[`superpowers/specs/2026-08-21-donation-page-design.md`](superpowers/specs/2026-08-21-donation-page-design.md).

## How a gift flows

1. The form (`components/sections/DonationForm.tsx`) posts to
   `POST /api/donate/create-session`, which checks the floor, the cap, the
   frequency and the designation against the live allowlist, then creates a
   Checkout Session and returns its URL.
2. The donor pays on Stripe's page and returns to `/donate?status=success`.
   That redirect only changes what the page shows; nothing is fulfilled on
   it.
3. Stripe calls `POST /api/donate/webhook`. A one-time gift is thanked on
   `checkout.session.completed`; a monthly gift only on `invoice.paid`, which
   covers the first charge and every renewal, so the first one is not thanked
   twice. Only invoices of subscriptions this form created
   (`frequency=monthly` in their metadata) count.
4. The webhook hands the acknowledgment to WordPress
   (`POST /cdcf/v1/donation-acknowledgment`), which sends it with `wp_mail`,
   once per Stripe event. A failure to send is a non-2xx, so Stripe retries
   for up to three days.
5. A monthly donor can ask for a link to manage their gift at
   `POST /api/donate/portal`. It always answers `{ "success": true }`, before
   looking the address up, so neither the answer nor its timing shows who has
   donated.

## Reporting

Each gift's metadata carries `project_slug` (the English post's slug, empty
for the general fund), `project_title_en` (its English title at the time of
the gift) and `project_title` (the title in the donor's language, used only
for the email), plus `frequency` and `locale`. Group Dashboard reports by
`project_slug`: Polylang gives each language its own slug, so the English
one is the only key that is the same for every gift to a project.

## Configuration

### Next.js (`.env.local` locally; the Plesk panel for each Node.js app)

| Variable                    | Purpose                                                                                                             |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `STRIPE_SECRET_KEY`         | `sk_test_…` everywhere except production, which uses the live key.                                                  |
| `STRIPE_WEBHOOK_SECRET`     | The `whsec_…` of the webhook endpoint for this environment (or of `stripe listen` locally).                         |
| `CDCF_DONATION_MAIL_SECRET` | Signs the requests to the two WordPress donation endpoints. Must equal the WordPress value. `openssl rand -hex 32`. |
| `NEXT_PUBLIC_SITE_URL`      | Base of the success, cancel and portal return URLs (`http://localhost:3000` locally).                               |

`WP_REST_URL`, `WP_APP_USERNAME` and `WP_APP_PASSWORD` are reused for the
WordPress calls. That account needs `edit_posts`.

Staging and production share one WordPress backend, so **both frontends use
the same `CDCF_DONATION_MAIL_SECRET`**.

### WordPress (`wp-config.php`)

```php
// Signs the donation mail requests (same value as the Next.js variable).
// Unset or empty: both donation endpoints refuse every request.
define('CDCF_DONATION_MAIL_SECRET', '…');

// Donation acknowledgment email.
define('CDCF_FOUNDATION_EIN', '00-0000000');
define('CDCF_FOUNDATION_ADDRESS', '000 Example St, City, ST 00000, USA');

// Staging shares this backend. Without this, a test-mode Stripe event
// would mail the real donor; with it, test-mode mail is redirected here.
// Unset: test-mode acknowledgments are not sent at all.
define('CDCF_DONATION_TEST_INBOX', 'qa@example.org');
```

The local Docker stack defines `CDCF_DONATION_MAIL_SECRET` from `.env`.

### Why the WordPress endpoints are signed

They send email in the Foundation's name, and `edit_posts` alone is held by
every author and contributor. The signature (`X-CDCF-Signature`,
HMAC-SHA256 of the raw body) proves the request came from the Next.js
server. The endpoints also refuse any portal link outside
`https://billing.stripe.com`.

## Local development

1. Put `STRIPE_SECRET_KEY` (from the sandbox you configured, under
   Developers → API keys), `CDCF_DONATION_MAIL_SECRET` and
   `NEXT_PUBLIC_SITE_URL=http://localhost:3000` in `.env.local`, and the same
   `CDCF_DONATION_MAIL_SECRET` in `.env` for the WordPress container.
2. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli), run
   `stripe login` and choose the sandbox.
3. `stripe listen --forward-to localhost:3000/api/donate/webhook` prints a
   `whsec_…`; put it in `.env.local` as `STRIPE_WEBHOOK_SECRET` and restart
   `npm run dev`.
4. `stripe trigger checkout.session.completed` should be answered with `200`.
   For the whole path, make a gift on `/donate` with a
   [test card](https://docs.stripe.com/testing).

## Stripe Dashboard checklist

Do it in test mode first, then again in live mode: the two keep separate
settings.

- [ ] **Nonprofit rate.** Apply for the
      [nonprofit fee discount](https://support.stripe.com/questions/fee-discount-for-nonprofit-organizations)
      with the EIN or the IRS determination letter. It is not applied
      automatically.
- [ ] **Wallets.** Nothing to register: Settings → Payment method domains
      applies only to Elements on our own pages. Hosted Checkout runs on
      Stripe's domain, so Apple Pay and Google Pay appear without it; confirm
      they are enabled under Settings → Payment methods.
- [ ] **Payment methods.** Leave them to Stripe (the session sets no
      `payment_method_types`), with Link on.
- [ ] **Adaptive Pricing off.** Settings → Payments → Adaptive Pricing. Gifts
      are USD only, which keeps the cap and the acknowledged amount
      unambiguous.
- [ ] **Radar Lite.** The included plan covers card-testing prevention.
      Custom rules need Radar Plus, so the per-IP limit lives in
      `create-session`. Upgrade only if monitoring shows card testing getting
      through.
- [ ] **Billing mode.** Settings → Billing → Subscriptions and emails →
      Default billing mode: Flexible. The API sets it explicitly; this covers
      anything created in the Dashboard.
- [ ] **Failed renewals.** Revenue recovery: Smart Retries on, and **cancel
      the subscription** once all retries fail. Under Settings → Billing →
      Subscriptions and emails, turn on the emails for failed card payments
      and expiring cards.
- [ ] **Customer Portal.** Settings → Billing → Customer portal: cancellation
      at the end of the period and payment-method updates on; plan switching
      **off** (subscriptions use inline prices, so there is nothing to switch
      to). Set the privacy policy and terms links in Public details.
- [ ] **Stripe's own receipts off.** Settings → Customer emails → Successful
      payments. The Foundation's acknowledgment is the receipt; with both on,
      every donor gets two emails for one gift. Refund emails may stay on.
- [ ] **Branding.** Settings → Branding: logo, icon and colours for the
      Checkout and Portal pages.
- [ ] **Not needed.** Stripe Tax, Invoicing and Managed Payments stay off:
      donations to a 501(c)(3) are not taxable sales.

## Going live

1. **Webhook endpoints.** Register
   `https://catholicdigitalcommons.org/api/donate/webhook` in live mode and
   the staging URL in test mode, each subscribed to
   `checkout.session.completed` and `invoice.paid` only. Put each signing
   secret in the matching environment's `STRIPE_WEBHOOK_SECRET`.
2. **Content.** Create the `donate` page in each language with the `Donate`
   template, fill its five fields (appeal, preset amounts, tax disclaimer,
   above-cap text, thank-you text), and tick "Accepts Donations" on the
   **English** post of each project that should appear in the picker. The
   flag is read from the English post for every language (a project with no
   English translation uses its own flag).
3. **Deploy** with `gh workflow run deploy.yml -f environment=production`,
   then confirm the WordPress steps ran rather than being skipped:

   ```bash
   gh run view <run-id> --json jobs \
     -q '.jobs[].steps[] | select(.name|test("WP theme|OPcache|plugins")) | "\(.conclusion)\t\(.name)"'
   ```

4. **Verify.** Make a live $5 one-time gift and a live $5 monthly gift.
   Confirm both acknowledgments arrive with the sentence "No goods or
   services were provided in exchange for this contribution.", cancel the
   subscription through the portal link, and refund both in the Dashboard.

## Monitoring

In the Dashboard, filter Payments to `Failed`. A card-testing burst reads as
dozens of declines within minutes, mostly at the same amount, from many
cards and one or few IPs, while successful charges stay flat. Lower the
`create-session` rate limit and, if needed, raise the donation floor for a
while; if bursts keep getting through, move to Radar Plus and add a velocity
rule.
