// lib/donate/acknowledgment.ts
import type { DonationFrequency } from './validation'

/** Locales the acknowledgment email has copy for. Mirrors
 *  src/i18n/routing.ts; anything else falls back to English. */
const SUPPORTED_LOCALES = ['en', 'it', 'es', 'fr', 'pt', 'de'] as const

/** What the webhook handler knows about a gift, in its own camelCase. */
export interface AcknowledgmentInput {
  eventId: string
  livemode: boolean
  email: string
  donorName: string
  amountCents: number
  frequency: DonationFrequency
  locale: string
  projectTitle: string
  occurredAt: number
  portalUrl: string
}

/** The body POSTed to WordPress `cdcf/v1/donation-acknowledgment`. */
export interface AcknowledgmentPayload {
  event_id: string
  livemode: boolean
  email: string
  donor_name: string
  amount_cents: number
  currency: string
  frequency: DonationFrequency
  locale: string
  project_title: string
  occurred_at: number
  portal_url: string
}

/**
 * Subscription mode fires BOTH checkout.session.completed and invoice.paid
 * for the first payment. Acknowledging on invoice.paid alone covers the
 * first charge and every renewal through one code path, with no duplicate.
 */
export function shouldAcknowledge(
  eventType: string,
  checkoutMode: 'payment' | 'subscription' | null
): boolean {
  if (eventType === 'checkout.session.completed') {
    return checkoutMode === 'payment'
  }
  return eventType === 'invoice.paid'
}

/**
 * Map a gift onto the WordPress request shape, falling back to English for an
 * unsupported locale and to empty strings for missing optional fields.
 */
export function buildAcknowledgmentPayload(
  input: AcknowledgmentInput
): AcknowledgmentPayload {
  const locale = (SUPPORTED_LOCALES as readonly string[]).includes(input.locale)
    ? input.locale
    : 'en'

  return {
    event_id: input.eventId,
    livemode: input.livemode,
    email: input.email,
    donor_name: input.donorName || '',
    amount_cents: input.amountCents,
    currency: 'usd',
    frequency: input.frequency,
    locale,
    project_title: input.projectTitle || '',
    occurred_at: input.occurredAt,
    portal_url: input.portalUrl || '',
  }
}
