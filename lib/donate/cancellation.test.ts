// lib/donate/cancellation.test.ts
import { describe, expect, it } from 'vitest'

import {
  buildCancellationPayload,
  classifyCancellation,
  type SubscriptionFacts,
} from './cancellation'

/** A monthly donation subscription, with any overrides applied. */
function sub(overrides: Partial<SubscriptionFacts> = {}): SubscriptionFacts {
  return {
    cancelAt: null,
    cancelAtPeriodEnd: false,
    cancellationReason: null,
    frequency: 'monthly',
    ...overrides,
  }
}

describe('classifyCancellation', () => {
  it('is a scheduled cancellation when the portal sets cancel_at', () => {
    expect(
      classifyCancellation(
        'customer.subscription.updated',
        sub({ cancelAt: 1_800_000_000 }),
        { cancel_at: null }
      )
    ).toBe('scheduled')
  })

  it('also recognises a classic cancel_at_period_end flip', () => {
    expect(
      classifyCancellation(
        'customer.subscription.updated',
        sub({ cancelAt: 1_800_000_000, cancelAtPeriodEnd: true }),
        { cancel_at_period_end: false }
      )
    ).toBe('scheduled')
  })

  it('ignores a reactivation, which clears cancel_at', () => {
    expect(
      classifyCancellation(
        'customer.subscription.updated',
        sub({ cancelAt: null }),
        { cancel_at: 1_800_000_000 }
      )
    ).toBeNull()
  })

  it('ignores any other update (a new card, a renewal)', () => {
    expect(
      classifyCancellation('customer.subscription.updated', sub(), {
        default_payment_method: 'pm_old',
      })
    ).toBeNull()
    expect(
      classifyCancellation(
        'customer.subscription.updated',
        sub({ cancelAt: 1_800_000_000 }),
        { current_period_end: 1 }
      )
    ).toBeNull()
  })

  it('says nothing when a scheduled cancellation takes effect: both emails went out when it was scheduled', () => {
    expect(
      classifyCancellation(
        'customer.subscription.deleted',
        sub({
          cancelAt: 1_800_000_000,
          cancellationReason: 'cancellation_requested',
        }),
        undefined
      )
    ).toBeNull()
  })

  it('is an immediate cancellation when it is deleted with nothing scheduled', () => {
    expect(
      classifyCancellation(
        'customer.subscription.deleted',
        sub({ cancellationReason: 'cancellation_requested' }),
        undefined
      )
    ).toBe('immediate')
  })

  it('is a payment failure when retries ran out', () => {
    expect(
      classifyCancellation(
        'customer.subscription.deleted',
        sub({ cancellationReason: 'payment_failed' }),
        undefined
      )
    ).toBe('payment_failed')
  })

  it('ignores subscriptions this form did not create', () => {
    expect(
      classifyCancellation(
        'customer.subscription.deleted',
        sub({ frequency: undefined }),
        undefined
      )
    ).toBeNull()
  })

  it('ignores other event types', () => {
    expect(classifyCancellation('invoice.paid', sub(), undefined)).toBeNull()
  })
})

describe('buildCancellationPayload', () => {
  it('maps the facts onto the WordPress request, defaulting the optional ones', () => {
    expect(
      buildCancellationPayload({
        eventId: 'evt_1',
        livemode: true,
        kind: 'scheduled',
        subscriptionId: 'sub_1',
        email: 'd@example.org',
        donorName: '',
        locale: 'xx',
        amountCents: 2500,
        projectTitle: 'BibleGet',
        projectTitleEn: 'BibleGet',
        startedAt: 100,
        endsAt: 200,
        occurredAt: 150,
        feedback: null,
        comment: null,
      })
    ).toEqual({
      event_id: 'evt_1',
      livemode: true,
      kind: 'scheduled',
      subscription_id: 'sub_1',
      email: 'd@example.org',
      donor_name: '',
      locale: 'en',
      amount_cents: 2500,
      project_title: 'BibleGet',
      project_title_en: 'BibleGet',
      started_at: 100,
      ends_at: 200,
      occurred_at: 150,
      feedback: '',
      comment: '',
    })
  })
})
