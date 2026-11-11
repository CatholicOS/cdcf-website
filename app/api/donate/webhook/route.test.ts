import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const constructEvent = vi.fn()
const createPortal = vi.fn()
const retrieveCustomer = vi.fn()
vi.mock('@/lib/stripe', () => ({
  getStripe: () => ({
    webhooks: { constructEvent },
    billingPortal: { sessions: { create: createPortal } },
    customers: { retrieve: retrieveCustomer },
  }),
}))
const postToWordPress = vi.fn()
vi.mock('@/lib/donate/wp-mailer', () => ({
  postToWordPress: (...a: unknown[]) => postToWordPress(...a),
}))

import { POST } from './route'

/** A POST to the webhook with the given body and Stripe-Signature header. */
function req(body = '{}', signature: string | null = 't=1,v1=x') {
  const headers = new Headers({ 'content-type': 'application/json' })
  if (signature) headers.set('stripe-signature', signature)
  return new Request('http://localhost/api/donate/webhook', {
    method: 'POST',
    headers,
    body,
  }) as unknown as Parameters<typeof POST>[0]
}

const subscriptionMeta = {
  frequency: 'monthly',
  locale: 'it',
  project_title: 'BibleGet',
  project_slug: 'bibleget',
}

describe('POST /api/donate/webhook', () => {
  beforeEach(() => {
    vi.stubEnv('STRIPE_WEBHOOK_SECRET', 'whsec_test')
    vi.stubEnv('NEXT_PUBLIC_SITE_URL', 'https://example.org')
    vi.spyOn(console, 'error').mockImplementation(() => {})
    postToWordPress.mockResolvedValue(true)
  })

  afterEach(() => {
    vi.unstubAllEnvs()
    vi.restoreAllMocks()
    constructEvent.mockReset()
    createPortal.mockReset()
    retrieveCustomer.mockReset()
    postToWordPress.mockReset()
  })

  it('rejects an event whose signature does not verify', async () => {
    constructEvent.mockImplementation(() => {
      throw new Error('No signatures found')
    })

    const res = await POST(req())

    expect(res.status).toBe(400)
    expect(postToWordPress).not.toHaveBeenCalled()
  })

  it('rejects a request with no signature header', async () => {
    const res = await POST(req('{}', null))

    expect(res.status).toBe(400)
    expect(constructEvent).not.toHaveBeenCalled()
  })

  it('acknowledges a one-time gift on checkout.session.completed', async () => {
    constructEvent.mockReturnValue({
      id: 'evt_1',
      type: 'checkout.session.completed',
      livemode: false,
      created: 100,
      data: {
        object: {
          mode: 'payment',
          payment_status: 'paid',
          amount_total: 5000,
          customer: null,
          customer_details: { email: 'd@example.org', name: 'Dana' },
          metadata: { frequency: 'once', locale: 'fr', project_title: '' },
        },
      },
    })

    const res = await POST(req())

    expect(res.status).toBe(200)
    expect(postToWordPress).toHaveBeenCalledWith(
      'cdcf/v1/donation-acknowledgment',
      expect.objectContaining({
        event_id: 'evt_1',
        email: 'd@example.org',
        amount_cents: 5000,
        frequency: 'once',
        locale: 'fr',
        portal_url: '',
      })
    )
  })

  it('thanks a delayed one-time gift only when its payment succeeds', async () => {
    const session = {
      mode: 'payment',
      amount_total: 1000,
      customer_details: { email: 'late@example.org' },
      metadata: { frequency: 'once' },
    }
    constructEvent.mockReturnValueOnce({
      id: 'evt_a',
      type: 'checkout.session.completed',
      data: { object: { ...session, payment_status: 'unpaid' } },
    })
    await POST(req())
    expect(postToWordPress).not.toHaveBeenCalled()

    constructEvent.mockReturnValueOnce({
      id: 'evt_b',
      type: 'checkout.session.async_payment_succeeded',
      livemode: true,
      created: 5,
      data: { object: { ...session, payment_status: 'paid' } },
    })
    await POST(req())
    expect(postToWordPress).toHaveBeenCalledWith(
      'cdcf/v1/donation-acknowledgment',
      expect.objectContaining({ event_id: 'evt_b', amount_cents: 1000 })
    )
  })

  it('ignores a paid checkout this form did not create', async () => {
    // A Payment Link or any other Checkout on the account has no
    // frequency=once metadata and is not a donation.
    constructEvent.mockReturnValue({
      id: 'evt_x',
      type: 'checkout.session.completed',
      data: {
        object: {
          mode: 'payment',
          payment_status: 'paid',
          amount_total: 9900,
          customer_details: { email: 'buyer@example.org' },
          metadata: {},
        },
      },
    })

    const res = await POST(req())

    expect(res.status).toBe(200)
    expect(postToWordPress).not.toHaveBeenCalled()
  })

  it('does not acknowledge a subscription checkout, which invoice.paid covers', async () => {
    constructEvent.mockReturnValue({
      id: 'evt_2',
      type: 'checkout.session.completed',
      data: { object: { mode: 'subscription' } },
    })

    const res = await POST(req())

    expect(res.status).toBe(200)
    expect(postToWordPress).not.toHaveBeenCalled()
  })

  it('reads the designation of a renewal from the subscription metadata on the invoice', async () => {
    createPortal.mockResolvedValue({
      url: 'https://billing.stripe.com/p/session/abc',
    })
    constructEvent.mockReturnValue({
      id: 'evt_3',
      type: 'invoice.paid',
      livemode: true,
      created: 200,
      data: {
        object: {
          customer: 'cus_1',
          customer_email: 'm@example.org',
          customer_name: 'Mo',
          amount_paid: 2500,
          parent: { subscription_details: { metadata: subscriptionMeta } },
        },
      },
    })

    const res = await POST(req())

    expect(res.status).toBe(200)
    expect(createPortal).toHaveBeenCalledWith(
      expect.objectContaining({ customer: 'cus_1' })
    )
    expect(postToWordPress).toHaveBeenCalledWith(
      'cdcf/v1/donation-acknowledgment',
      expect.objectContaining({
        frequency: 'monthly',
        locale: 'it',
        project_title: 'BibleGet',
        amount_cents: 2500,
        portal_url: 'https://billing.stripe.com/p/session/abc',
      })
    )
  })

  it('ignores an invoice that is not one of our donation subscriptions', async () => {
    constructEvent.mockReturnValue({
      id: 'evt_4',
      type: 'invoice.paid',
      data: {
        object: {
          customer: 'cus_2',
          customer_email: 'x@example.org',
          amount_paid: 9900,
          parent: null,
        },
      },
    })

    const res = await POST(req())

    expect(res.status).toBe(200)
    expect(postToWordPress).not.toHaveBeenCalled()
  })

  it('returns 500 when WordPress could not send, so Stripe retries', async () => {
    postToWordPress.mockResolvedValue(false)
    constructEvent.mockReturnValue({
      id: 'evt_5',
      type: 'checkout.session.completed',
      livemode: true,
      created: 1,
      data: {
        object: {
          mode: 'payment',
          payment_status: 'paid',
          amount_total: 500,
          customer_details: { email: 'd@example.org' },
          metadata: { frequency: 'once' },
        },
      },
    })

    const res = await POST(req())

    expect(res.status).toBe(500)
  })

  describe('cancellations', () => {
    /** A monthly donation subscription as Stripe sends it, with overrides. */
    function subscription(overrides: Record<string, unknown> = {}) {
      return {
        id: 'sub_1',
        customer: 'cus_1',
        start_date: 1_700_000_000,
        cancel_at: null,
        cancel_at_period_end: false,
        ended_at: null,
        cancellation_details: { reason: null, feedback: null, comment: null },
        items: { data: [{ quantity: 1, price: { unit_amount: 2500 } }] },
        metadata: { ...subscriptionMeta, project_title_en: 'BibleGet' },
        ...overrides,
      }
    }

    /** A subscription event of `type` carrying `object`. */
    function subscriptionEvent(
      type: string,
      object: Record<string, unknown>,
      previous?: Record<string, unknown>
    ) {
      return {
        id: 'evt_c',
        type,
        livemode: true,
        created: 1_750_000_000,
        data: {
          object,
          ...(previous ? { previous_attributes: previous } : {}),
        },
      }
    }

    beforeEach(() => {
      retrieveCustomer.mockResolvedValue({
        email: 'd@example.org',
        name: 'Dana',
      })
    })

    it('sends a scheduled cancellation, with the reason, when the donor cancels in the portal', async () => {
      constructEvent.mockReturnValue(
        subscriptionEvent(
          'customer.subscription.updated',
          subscription({
            cancel_at: 1_760_000_000,
            cancellation_details: {
              reason: 'cancellation_requested',
              feedback: 'too_expensive',
              comment: 'Tight month',
            },
          }),
          { cancel_at: null }
        )
      )

      const res = await POST(req())

      expect(res.status).toBe(200)
      expect(retrieveCustomer).toHaveBeenCalledWith('cus_1')
      expect(postToWordPress).toHaveBeenCalledWith(
        'cdcf/v1/donation-cancellation',
        {
          event_id: 'evt_c',
          livemode: true,
          kind: 'scheduled',
          subscription_id: 'sub_1',
          email: 'd@example.org',
          donor_name: 'Dana',
          locale: 'it',
          amount_cents: 2500,
          project_title: 'BibleGet',
          project_title_en: 'BibleGet',
          started_at: 1_700_000_000,
          ends_at: 1_760_000_000,
          occurred_at: 1_750_000_000,
          feedback: 'too_expensive',
          comment: 'Tight month',
        }
      )
    })

    it('tells the Foundation when the donor reactivates', async () => {
      constructEvent.mockReturnValue(
        subscriptionEvent('customer.subscription.updated', subscription(), {
          cancel_at: 1_760_000_000,
        })
      )

      const res = await POST(req())

      expect(res.status).toBe(200)
      expect(postToWordPress).toHaveBeenCalledWith(
        'cdcf/v1/donation-cancellation',
        expect.objectContaining({ kind: 'reactivated', ends_at: 1_750_000_000 })
      )
    })

    it.each([
      ['customer.subscription.created', 'started'],
      ['customer.subscription.paused', 'paused'],
      ['customer.subscription.resumed', 'resumed'],
    ])('hands %s to WordPress as %s', async (type, kind) => {
      constructEvent.mockReturnValue(subscriptionEvent(type, subscription()))

      const res = await POST(req())

      expect(res.status).toBe(200)
      expect(postToWordPress).toHaveBeenCalledWith(
        'cdcf/v1/donation-cancellation',
        expect.objectContaining({ kind, email: 'd@example.org' })
      )
    })

    it('sends nothing for a routine update such as a renewal', async () => {
      constructEvent.mockReturnValue(
        subscriptionEvent('customer.subscription.updated', subscription(), {
          current_period_end: 1_700_000_000,
        })
      )

      await POST(req())

      expect(postToWordPress).not.toHaveBeenCalled()
      expect(retrieveCustomer).not.toHaveBeenCalled()
    })

    it('reports a gift ended by failed payments, dated when it ended', async () => {
      constructEvent.mockReturnValue(
        subscriptionEvent(
          'customer.subscription.deleted',
          subscription({
            ended_at: 1_755_000_000,
            cancellation_details: {
              reason: 'payment_failed',
              feedback: null,
              comment: null,
            },
          })
        )
      )

      await POST(req())

      expect(postToWordPress).toHaveBeenCalledWith(
        'cdcf/v1/donation-cancellation',
        expect.objectContaining({
          kind: 'payment_failed',
          ends_at: 1_755_000_000,
          feedback: '',
        })
      )
    })

    it('still notifies the Foundation when the customer has no email', async () => {
      retrieveCustomer.mockResolvedValue({ id: 'cus_1', deleted: true })
      constructEvent.mockReturnValue(
        subscriptionEvent(
          'customer.subscription.deleted',
          subscription({
            ended_at: 1_755_000_000,
            cancellation_details: {
              reason: 'cancellation_requested',
              feedback: null,
              comment: null,
            },
          })
        )
      )

      await POST(req())

      expect(postToWordPress).toHaveBeenCalledWith(
        'cdcf/v1/donation-cancellation',
        expect.objectContaining({ kind: 'immediate', email: '' })
      )
    })

    it('ignores subscriptions this form did not create', async () => {
      constructEvent.mockReturnValue(
        subscriptionEvent(
          'customer.subscription.deleted',
          subscription({ metadata: {} })
        )
      )

      const res = await POST(req())

      expect(res.status).toBe(200)
      expect(postToWordPress).not.toHaveBeenCalled()
    })

    it('returns 500 when WordPress could not send, so Stripe retries', async () => {
      postToWordPress.mockResolvedValue(false)
      constructEvent.mockReturnValue(
        subscriptionEvent(
          'customer.subscription.updated',
          subscription({ cancel_at: 1_760_000_000 }),
          { cancel_at: null }
        )
      )

      const res = await POST(req())

      expect(res.status).toBe(500)
    })
  })
})
