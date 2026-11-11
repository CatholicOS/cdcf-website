// app/api/donate/webhook/route.ts
import { type NextRequest, NextResponse } from 'next/server'
import type Stripe from 'stripe'

import {
  buildAcknowledgmentPayload,
  shouldAcknowledge,
} from '@/lib/donate/acknowledgment'
import {
  buildCancellationPayload,
  classifySubscriptionEvent,
  type SubscriptionNoticeKind,
} from '@/lib/donate/cancellation'
import type { DonationFrequency } from '@/lib/donate/validation'
import { postToWordPress } from '@/lib/donate/wp-mailer'
import { createPortalSession } from '@/lib/donate/portal-session'
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

  if (event.type.startsWith('customer.subscription.')) {
    return handleSubscriptionEvent(event)
  }

  const isCheckoutEvent =
    event.type === 'checkout.session.completed' ||
    event.type === 'checkout.session.async_payment_succeeded'

  let mode: 'payment' | 'subscription' | null = null
  let paymentStatus: string | null = null
  if (isCheckoutEvent) {
    const session = event.data.object as Stripe.Checkout.Session
    mode = session.mode as 'payment' | 'subscription'
    paymentStatus = session.payment_status
  }

  if (!shouldAcknowledge(event.type, mode, paymentStatus)) {
    return NextResponse.json({ received: true })
  }

  let email = ''
  let donorName = ''
  let amountCents = 0
  let metadata: Record<string, string> = {}
  let customerId: string | null = null

  if (isCheckoutEvent) {
    const session = event.data.object as Stripe.Checkout.Session
    email = session.customer_details?.email ?? ''
    donorName = session.customer_details?.name ?? ''
    amountCents = session.amount_total ?? 0
    metadata = (session.metadata ?? {}) as Record<string, string>
    customerId = typeof session.customer === 'string' ? session.customer : null

    // Same guard as for invoices: only sessions this form created carry
    // frequency=once; a Payment Link or other Checkout is not a donation.
    if (metadata.frequency !== 'once') {
      return NextResponse.json({ received: true })
    }
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
      portalUrl = await createPortalSession(
        (params) => getStripe().billingPortal.sessions.create(params),
        customerId,
        metadata.locale ?? 'en'
      )
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

/**
 * A monthly gift started, was cancelled, was reactivated, paused, resumed,
 * or ended after failed payments: hand the notice (and, for a cancellation,
 * the donor's confirmation) to WordPress. Routine updates (a renewal, a new
 * card) are acknowledged and ignored.
 */
async function handleSubscriptionEvent(event: Stripe.Event) {
  const subscription = event.data.object as Stripe.Subscription
  const metadata = (subscription.metadata ?? {}) as Record<string, string>
  const previous = (
    event.data as { previous_attributes?: Record<string, unknown> }
  ).previous_attributes

  const kind = classifySubscriptionEvent(
    event.type,
    {
      cancelAt: subscription.cancel_at ?? null,
      cancelAtPeriodEnd: subscription.cancel_at_period_end ?? false,
      cancellationReason: subscription.cancellation_details?.reason ?? null,
      frequency: metadata.frequency,
    },
    previous
  )
  if (!kind) {
    return NextResponse.json({ received: true })
  }

  // The subscription carries only the customer id; the email and name are
  // on the customer. A deleted customer has neither, and the Foundation is
  // still told.
  let email = ''
  let donorName = ''
  const customerId =
    typeof subscription.customer === 'string'
      ? subscription.customer
      : subscription.customer?.id
  if (customerId) {
    try {
      const customer = await getStripe().customers.retrieve(customerId)
      if (!('deleted' in customer && customer.deleted)) {
        email = customer.email ?? ''
        donorName = customer.name ?? ''
      }
    } catch (error) {
      console.error('Failed to retrieve the cancelling customer:', error)
    }
  }

  const item = subscription.items?.data?.[0]
  const amountCents = (item?.price?.unit_amount ?? 0) * (item?.quantity ?? 1)

  const payload = buildCancellationPayload({
    eventId: event.id,
    livemode: event.livemode,
    kind,
    subscriptionId: subscription.id,
    email,
    donorName,
    locale: metadata.locale ?? 'en',
    amountCents,
    projectTitle: metadata.project_title ?? '',
    projectTitleEn: metadata.project_title_en ?? '',
    startedAt: subscription.start_date,
    endsAt: endsAt(kind, subscription, event.created),
    occurredAt: event.created,
    feedback: subscription.cancellation_details?.feedback ?? null,
    comment: subscription.cancellation_details?.comment ?? null,
  })

  const sent = await postToWordPress('cdcf/v1/donation-cancellation', payload)
  if (!sent) {
    return NextResponse.json(
      { error: 'Cancellation notice delivery failed.' },
      { status: 500 }
    )
  }
  return NextResponse.json({ received: true })
}

/**
 * The gift's last day for a cancellation: `cancel_at` when scheduled, when
 * it ended otherwise. For the other notices, when the event happened.
 */
function endsAt(
  kind: SubscriptionNoticeKind,
  subscription: Stripe.Subscription,
  occurredAt: number
): number {
  if (kind === 'scheduled') return subscription.cancel_at ?? occurredAt
  if (kind === 'immediate' || kind === 'payment_failed') {
    return subscription.ended_at ?? subscription.canceled_at ?? occurredAt
  }
  return occurredAt
}
