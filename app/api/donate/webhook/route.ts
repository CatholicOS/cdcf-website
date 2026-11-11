// app/api/donate/webhook/route.ts
import { NextRequest, NextResponse } from 'next/server'
import type Stripe from 'stripe'

import {
  buildAcknowledgmentPayload,
  shouldAcknowledge,
} from '@/lib/donate/acknowledgment'
import type { DonationFrequency } from '@/lib/donate/validation'
import { postToWordPress } from '@/lib/donate/wp-mailer'
import { getStripe } from '@/lib/stripe'

export const runtime = 'nodejs'

/**
 * POST /api/donate/webhook: verify Stripe's signature, decide whether the
 * event is one that thanks a donor, and hand the acknowledgment to
 * WordPress. Any failure to send is a non-2xx, so Stripe retries.
 */
export async function POST(request: NextRequest) {
  const signature = request.headers.get('stripe-signature')
  const secret = process.env.STRIPE_WEBHOOK_SECRET

  if (!signature || !secret) {
    return NextResponse.json({ error: 'Not configured.' }, { status: 400 })
  }

  // Signature verification needs the exact bytes Stripe signed, so read
  // the raw text rather than request.json().
  const raw = await request.text()

  let event: Stripe.Event
  try {
    event = getStripe().webhooks.constructEvent(raw, signature, secret)
  } catch (error) {
    console.error('Stripe webhook signature verification failed:', error)
    return NextResponse.json({ error: 'Invalid signature.' }, { status: 400 })
  }

  let mode: 'payment' | 'subscription' | null = null
  if (event.type === 'checkout.session.completed') {
    mode = (event.data.object as Stripe.Checkout.Session).mode as
      'payment' | 'subscription'
  }

  if (!shouldAcknowledge(event.type, mode)) {
    return NextResponse.json({ received: true })
  }

  let email = ''
  let donorName = ''
  let amountCents = 0
  let metadata: Record<string, string> = {}
  let customerId: string | null = null

  if (event.type === 'checkout.session.completed') {
    const session = event.data.object as Stripe.Checkout.Session
    email = session.customer_details?.email ?? ''
    donorName = session.customer_details?.name ?? ''
    amountCents = session.amount_total ?? 0
    metadata = (session.metadata ?? {}) as Record<string, string>
    customerId = typeof session.customer === 'string' ? session.customer : null
  } else {
    const invoice = event.data.object as Stripe.Invoice
    email = invoice.customer_email ?? ''
    donorName = invoice.customer_name ?? ''
    amountCents = invoice.amount_paid ?? 0
    // Set from subscription_data.metadata at session creation, so it
    // survives onto every renewal invoice. Since API 2025-03-31.basil it
    // lives under invoice.parent, not invoice.subscription_details.
    metadata = (invoice.parent?.subscription_details?.metadata ?? {}) as Record<
      string,
      string
    >
    customerId = typeof invoice.customer === 'string' ? invoice.customer : null

    // invoice.paid fires for every invoice on the account. Only the
    // subscriptions this form created carry frequency=monthly; anything
    // else (a manual invoice, another product) is not a donation.
    if (metadata.frequency !== 'monthly') {
      return NextResponse.json({ received: true })
    }
  }

  if (!email) {
    console.error(`Stripe event ${event.id} carried no donor email`)
    return NextResponse.json({ received: true })
  }

  const frequency: DonationFrequency =
    metadata.frequency === 'monthly' ? 'monthly' : 'once'

  let portalUrl = ''
  if (frequency === 'monthly' && customerId) {
    try {
      const portal = await getStripe().billingPortal.sessions.create({
        customer: customerId,
        return_url: process.env.NEXT_PUBLIC_SITE_URL ?? undefined,
      })
      portalUrl = portal.url
    } catch (error) {
      // Non-fatal: the acknowledgment is still worth sending without it.
      console.error('Failed to create a billing portal session:', error)
    }
  }

  const payload = buildAcknowledgmentPayload({
    eventId: event.id,
    livemode: event.livemode,
    email,
    donorName,
    amountCents,
    frequency,
    locale: metadata.locale ?? 'en',
    projectTitle: metadata.project_title ?? '',
    occurredAt: event.created,
    portalUrl,
  })

  const sent = await postToWordPress('cdcf/v1/donation-acknowledgment', payload)

  if (!sent) {
    // Deliberately non-2xx: returning 200 on a failed email would lose
    // the acknowledgment permanently. Stripe retries for three days.
    return NextResponse.json(
      { error: 'Acknowledgment delivery failed.' },
      { status: 500 }
    )
  }

  return NextResponse.json({ received: true })
}
