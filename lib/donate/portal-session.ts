import type Stripe from 'stripe'

/** The site's locales, all of which Stripe's Customer Portal supports. */
const PORTAL_LOCALES = ['en', 'it', 'es', 'fr', 'pt', 'de'] as const

/**
 * Parameters for a Customer Portal session that a donor opens from an email.
 * Session URLs are short-lived and the email may be read days later, so an
 * expired link falls back to Stripe's customer login (the donor confirms
 * their email and gets a fresh session) instead of a dead page.
 */
export function portalSessionParams(
  customer: string,
  locale: string
): Stripe.BillingPortal.SessionCreateParams {
  return {
    customer,
    return_url: process.env.NEXT_PUBLIC_SITE_URL ?? undefined,
    locale: (PORTAL_LOCALES as readonly string[]).includes(locale)
      ? (locale as Stripe.BillingPortal.SessionCreateParams.Locale)
      : 'auto',
    after_expiration: { type: 'customer_login' },
  }
}
