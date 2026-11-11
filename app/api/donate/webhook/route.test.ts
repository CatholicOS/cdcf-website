import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const constructEvent = vi.fn()
const createPortal = vi.fn()
vi.mock('@/lib/stripe', () => ({
  getStripe: () => ({
    webhooks: { constructEvent },
    billingPortal: { sessions: { create: createPortal } },
  }),
}))
const postToWordPress = vi.fn()
vi.mock('@/lib/donate/wp-mailer', () => ({
  postToWordPress: (...a: unknown[]) => postToWordPress(...a),
}))

import { POST } from './route'

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
})
