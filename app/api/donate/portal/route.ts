import { after, NextRequest, NextResponse } from 'next/server'

import { postToWordPress } from '@/lib/donate/wp-mailer'
import { clientIp, createIpRateLimiter } from '@/lib/ip-rate-limit'
import { createPortalSession } from '@/lib/donate/portal-session'
import { getStripe } from '@/lib/stripe'
import { defaultLocale, locales } from '@/src/i18n/routing'

export const runtime = 'nodejs'

const isRateLimited = createIpRateLimiter(5, 60 * 60 * 1000)

/**
 * Look the address up in Stripe and, if it belongs to a customer, have
 * WordPress mail them a Customer Portal link. Errors are logged and
 * swallowed: nothing here may change what the caller sees.
 */
async function sendPortalLink(email: string, locale: string): Promise<void> {
  try {
    const stripe = getStripe()
    const customers = await stripe.customers.list({ email, limit: 1 })
    const customer = customers.data[0]
    if (!customer) return

    const portalUrl = await createPortalSession(
      (params) => stripe.billingPortal.sessions.create(params),
      customer.id,
      locale
    )
    await postToWordPress('cdcf/v1/donation-portal-link', {
      email,
      locale,
      portal_url: portalUrl,
    })
  } catch (error) {
    console.error('Failed to send a billing portal link:', error)
  }
}

/**
 * POST /api/donate/portal: email a recurring donor a link to manage their
 * gift. Always responds { success: true }, and responds before the Stripe
 * lookup runs (via after()), so neither the body nor the response time
 * reveals whether the address has ever donated.
 */
export async function POST(request: NextRequest) {
  if (isRateLimited(clientIp(request))) {
    return NextResponse.json(
      { error: 'Too many requests. Please try again later.' },
      { status: 429 }
    )
  }

  let body: Record<string, unknown>
  try {
    body = await request.json()
  } catch {
    return NextResponse.json(
      { error: 'Invalid request body.' },
      { status: 400 }
    )
  }

  const email = typeof body.email === 'string' ? body.email.trim() : ''
  if (!email) {
    return NextResponse.json({ error: 'Email is required.' }, { status: 400 })
  }

  const locale = String(body.locale ?? defaultLocale)
  const safeLocale = (locales as readonly string[]).includes(locale)
    ? locale
    : defaultLocale

  after(() => sendPortalLink(email, safeLocale))

  return NextResponse.json({ success: true })
}
