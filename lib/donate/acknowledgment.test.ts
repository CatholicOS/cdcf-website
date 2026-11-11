// lib/donate/acknowledgment.test.ts
import { describe, expect, it } from 'vitest'

import { buildAcknowledgmentPayload, shouldAcknowledge } from './acknowledgment'

describe('shouldAcknowledge', () => {
  it('waits when a one-time gift completes checkout but has not cleared', () => {
    // A delayed method (e.g. bank debit) completes checkout as 'unpaid'; the
    // money may never arrive, so no substantiating thank-you yet.
    expect(
      shouldAcknowledge('checkout.session.completed', 'payment', 'unpaid')
    ).toBe(false)
  })

  it('acknowledges a delayed one-time gift once its payment succeeds', () => {
    expect(
      shouldAcknowledge(
        'checkout.session.async_payment_succeeded',
        'payment',
        'paid'
      )
    ).toBe(true)
  })

  it('never acknowledges a subscription on its async checkout event', () => {
    expect(
      shouldAcknowledge(
        'checkout.session.async_payment_succeeded',
        'subscription',
        'paid'
      )
    ).toBe(false)
  })

  it('acknowledges a one-time gift on checkout.session.completed', () => {
    expect(
      shouldAcknowledge('checkout.session.completed', 'payment', 'paid')
    ).toBe(true)
  })

  it('does NOT acknowledge a subscription on checkout.session.completed', () => {
    // invoice.paid fires for the same first charge; acknowledging both
    // double-sends the first thank-you.
    expect(
      shouldAcknowledge('checkout.session.completed', 'subscription')
    ).toBe(false)
  })

  it('acknowledges every invoice.paid, first charge and renewal alike', () => {
    expect(shouldAcknowledge('invoice.paid', null)).toBe(true)
  })

  it('ignores every other event type', () => {
    expect(shouldAcknowledge('payment_intent.succeeded', null)).toBe(false)
    expect(shouldAcknowledge('invoice.payment_failed', null)).toBe(false)
    expect(shouldAcknowledge('customer.subscription.deleted', null)).toBe(false)
  })
})

describe('buildAcknowledgmentPayload', () => {
  const base = {
    eventId: 'evt_123',
    livemode: true,
    email: 'donor@example.org',
    donorName: 'Jane Donor',
    amountCents: 5000,
    frequency: 'once' as const,
    locale: 'it',
    projectTitle: 'BibleGet',
    occurredAt: 1755777600,
    portalUrl: '',
  }

  it('maps every field onto the WordPress request shape', () => {
    expect(buildAcknowledgmentPayload(base)).toEqual({
      event_id: 'evt_123',
      livemode: true,
      email: 'donor@example.org',
      donor_name: 'Jane Donor',
      amount_cents: 5000,
      currency: 'usd',
      frequency: 'once',
      locale: 'it',
      project_title: 'BibleGet',
      occurred_at: 1755777600,
      portal_url: '',
    })
  })

  it('falls back to English when the locale is absent or unsupported', () => {
    expect(buildAcknowledgmentPayload({ ...base, locale: '' }).locale).toBe(
      'en'
    )
    expect(buildAcknowledgmentPayload({ ...base, locale: 'ja' }).locale).toBe(
      'en'
    )
  })

  it('sends an empty project title for an undesignated gift', () => {
    const payload = buildAcknowledgmentPayload({ ...base, projectTitle: '' })
    expect(payload.project_title).toBe('')
  })

  it('falls back to an empty donor name rather than emitting undefined', () => {
    const payload = buildAcknowledgmentPayload({ ...base, donorName: '' })
    expect(payload.donor_name).toBe('')
  })
})
