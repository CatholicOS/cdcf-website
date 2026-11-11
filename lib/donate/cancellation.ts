// lib/donate/cancellation.ts

/** Locales the donor email has copy for; anything else falls back to English. */
const SUPPORTED_LOCALES = ['en', 'it', 'es', 'fr', 'pt', 'de'] as const

/**
 * What a cancellation was:
 * - `scheduled`: the donor cancelled in the portal; the gift ends at the end
 *   of the period already paid for. Donor confirmation + Foundation notice.
 * - `immediate`: cancelled at once (from the Dashboard). Both emails.
 * - `payment_failed`: Smart Retries ran out. Foundation notice only; Stripe
 *   already emails the donor about the failed payments.
 */
export type CancellationKind = 'scheduled' | 'immediate' | 'payment_failed'

/** The subscription fields the decision depends on. */
export interface SubscriptionFacts {
  cancelAt: number | null
  cancelAtPeriodEnd: boolean
  cancellationReason: string | null
  /** `metadata.frequency`; only `monthly` subscriptions are this form's. */
  frequency: string | undefined
}

/**
 * Which cancellation, if any, a subscription event reports.
 *
 * Under flexible billing mode a portal cancellation sets `cancel_at` and
 * leaves `cancel_at_period_end` false; under classic mode both are set. A
 * cancellation was scheduled when the event's previous attributes show
 * either one was unset. Clearing `cancel_at` is a reactivation and sends
 * nothing. When a scheduled cancellation later takes effect, the deletion
 * still carries `cancel_at`, and both emails have already gone out.
 */
export function classifyCancellation(
  eventType: string,
  sub: SubscriptionFacts,
  previousAttributes: Record<string, unknown> | undefined
): CancellationKind | null {
  if (sub.frequency !== 'monthly') return null

  if (eventType === 'customer.subscription.updated') {
    if (sub.cancelAt === null) return null
    const previous = previousAttributes ?? {}
    const wasUnscheduled =
      ('cancel_at' in previous && previous.cancel_at === null) ||
      ('cancel_at_period_end' in previous &&
        previous.cancel_at_period_end === false)
    return wasUnscheduled ? 'scheduled' : null
  }

  if (eventType === 'customer.subscription.deleted') {
    if (sub.cancellationReason === 'payment_failed') return 'payment_failed'
    if (sub.cancelAt !== null) return null
    return 'immediate'
  }

  return null
}

/** What the webhook knows about a cancellation, in its own camelCase. */
export interface CancellationInput {
  eventId: string
  livemode: boolean
  kind: CancellationKind
  subscriptionId: string
  email: string
  donorName: string
  locale: string
  amountCents: number
  projectTitle: string
  projectTitleEn: string
  startedAt: number
  /** When the gift stops: `cancel_at` for a scheduled cancellation. */
  endsAt: number
  occurredAt: number
  feedback: string | null
  comment: string | null
}

/** The body POSTed to WordPress `cdcf/v1/donation-cancellation`. */
export interface CancellationPayload {
  event_id: string
  livemode: boolean
  kind: CancellationKind
  subscription_id: string
  email: string
  donor_name: string
  locale: string
  amount_cents: number
  project_title: string
  project_title_en: string
  started_at: number
  ends_at: number
  occurred_at: number
  feedback: string
  comment: string
}

/**
 * Map a cancellation onto the WordPress request shape, falling back to
 * English for an unsupported locale and to empty strings for the donor's
 * optional name, reason and comment.
 */
export function buildCancellationPayload(
  input: CancellationInput
): CancellationPayload {
  const locale = (SUPPORTED_LOCALES as readonly string[]).includes(input.locale)
    ? input.locale
    : 'en'

  return {
    event_id: input.eventId,
    livemode: input.livemode,
    kind: input.kind,
    subscription_id: input.subscriptionId,
    email: input.email,
    donor_name: input.donorName || '',
    locale,
    amount_cents: input.amountCents,
    project_title: input.projectTitle,
    project_title_en: input.projectTitleEn,
    started_at: input.startedAt,
    ends_at: input.endsAt,
    occurred_at: input.occurredAt,
    feedback: input.feedback ?? '',
    comment: input.comment ?? '',
  }
}
