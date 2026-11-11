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

type CreatePortal = (
  params: Stripe.BillingPortal.SessionCreateParams
) => Promise<{ url: string }>

/**
 * Create a portal session and return its URL. The expired-link fallback
 * needs "customer login" switched on in the Dashboard's portal settings;
 * when Stripe refuses it, retry without, so the donor still gets a link
 * (one that simply expires) rather than none.
 */
export async function createPortalSession(
  create: CreatePortal,
  customer: string,
  locale: string
): Promise<string> {
  const params = portalSessionParams(customer, locale)
  try {
    return (await create(params)).url
  } catch (error) {
    console.error(
      'Portal session with login fallback refused; retrying without:',
      error
    )
    const plain = { ...params }
    delete plain.after_expiration
    return (await create(plain)).url
  }
}
