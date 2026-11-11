// app/api/donate/create-session/route.ts
import { type NextRequest, NextResponse } from 'next/server'
import type Stripe from 'stripe'

import { clientIp, createIpRateLimiter } from '@/lib/ip-rate-limit'
import { getStripe } from '@/lib/stripe'
import {
  isDonationFrequency,
  validateAmountCents,
  validateProjectSlug,
} from '@/lib/donate/validation'
import { getDonatableProjects } from '@/lib/wordpress/api'
import { defaultLocale, locales } from '@/src/i18n/routing'

export const runtime = 'nodejs'

// Card testing is the main abuse vector on a public donation form, and
// it shows up as a burst of session creations. Same limiter as the other
// public form routes.
/**
 * A same-site path: a leading slash, then lowercase slug segments only, and
 * at most 200 characters. Checked per segment, so no regex has to match the
 * whole path with a nested quantifier.
 */
function isSafeReturnPath(value: unknown): value is string {
  if (typeof value !== 'string' || value.length > 200) return false
  if (!value.startsWith('/')) return false
  return value
    .slice(1)
    .split('/')
    .every((segment) => /^[a-z0-9-]+$/.test(segment))
}

const isRateLimited = createIpRateLimiter(10, 60 * 60 * 1000)

/**
 * POST /api/donate/create-session: validate the donor's choices against the
 * cap, the floor and the live designation list, then create a Stripe
 * Checkout Session and return its URL for the browser to redirect to.
 */
export async function POST(request: NextRequest) {
  const ip = clientIp(request)

  if (isRateLimited(ip)) {
    return NextResponse.json(
      { error: 'Too many attempts. Please try again later.' },
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

  const locale = String(body.locale ?? defaultLocale)
  if (!(locales as readonly string[]).includes(locale)) {
    return NextResponse.json({ error: 'Unsupported locale.' }, { status: 400 })
  }

  const amount = validateAmountCents(body.amountCents)
  if (!amount.ok) {
    // above_maximum is a routing decision in the UI, not a user error —
    // but the server still refuses it, since the form can be bypassed.
    return NextResponse.json({ error: amount.reason }, { status: 400 })
  }

  if (!isDonationFrequency(body.frequency)) {
    return NextResponse.json(
      { error: 'Unsupported frequency.' },
      { status: 400 }
    )
  }
  const frequency = body.frequency

  const donatable = await getDonatableProjects(locale)
  const designation = validateProjectSlug(
    body.projectSlug,
    donatable.map((p) => p.slug)
  )
  if (!designation.ok) {
    return NextResponse.json({ error: designation.reason }, { status: 400 })
  }

  const project = designation.slug
    ? donatable.find((p) => p.slug === designation.slug)
    : undefined

  const origin = process.env.NEXT_PUBLIC_SITE_URL
  if (!origin) {
    console.error('NEXT_PUBLIC_SITE_URL is not set')
    return NextResponse.json(
      { error: 'Server configuration error.' },
      { status: 500 }
    )
  }

  // The page the donor gave from. Each language's Donate page has its own
  // slug (/it/dona, /fr/donate-2…), and the redirect from a guessed URL to
  // the real one drops the query string, so Stripe must return the donor to
  // the exact path. Only a plain same-site path is accepted.
  const fallbackPath =
    locale === defaultLocale ? '/donate' : `/${locale}/donate`
  const donatePath = isSafeReturnPath(body.returnPath)
    ? body.returnPath
    : fallbackPath

  // Metadata must be set on the subscription too: session metadata does
  // NOT propagate, so renewals would otherwise arrive undesignated.
  const metadata: Record<string, string> = {
    // The English slug and title are what Dashboard reports group by and
    // what still identifies the project after a rename; the local title is
    // only for the donor's acknowledgment email.
    project_slug: project?.canonicalSlug ?? '',
    project_title_en: project?.canonicalTitle ?? '',
    project_title: project?.title ?? '',
    locale,
    frequency,
  }

  const isRecurring = frequency === 'monthly'

  const params: Stripe.Checkout.SessionCreateParams = {
    mode: isRecurring ? 'subscription' : 'payment',
    locale: locale as Stripe.Checkout.SessionCreateParams.Locale,
    line_items: [
      {
        quantity: 1,
        price_data: {
          currency: 'usd',
          unit_amount: amount.amountCents,
          product_data: {
            name: project
              ? `Donation — ${project.title}`
              : 'Donation — Catholic Digital Commons Foundation',
          },
          ...(isRecurring ? { recurring: { interval: 'month' as const } } : {}),
        },
      },
    ],
    metadata,
    // billing_mode is spelled out even though flexible is the default from
    // API version 2025-09-30.clover on: it records the intent and survives
    // an SDK pinned to an older version.
    ...(isRecurring
      ? {
          subscription_data: {
            metadata,
            billing_mode: { type: 'flexible' as const },
          },
        }
      : { submit_type: 'donate' as const }),
    success_url: `${origin}${donatePath}?status=success`,
    cancel_url: `${origin}${donatePath}?status=cancelled`,
  }

  try {
    const session = await getStripe().checkout.sessions.create(params)
    if (!session.url) {
      return NextResponse.json(
        { error: 'Checkout unavailable.' },
        { status: 502 }
      )
    }
    return NextResponse.json({ url: session.url })
  } catch (error) {
    console.error('Failed to create Checkout Session:', error)
    return NextResponse.json(
      { error: 'Checkout unavailable.' },
      { status: 502 }
    )
  }
}
