import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// after() needs a live request scope; run the callback inline instead and
// keep its promise so a test can wait for the deferred work.
const deferred: Promise<unknown>[] = []
vi.mock('next/server', async (importOriginal) => ({
  ...(await importOriginal<typeof import('next/server')>()),
  after: (fn: () => Promise<unknown>) => {
    deferred.push(fn())
  },
}))
const listCustomers = vi.fn()
const createPortal = vi.fn()
vi.mock('@/lib/stripe', () => ({
  getStripe: () => ({
    customers: { list: listCustomers },
    billingPortal: { sessions: { create: createPortal } },
  }),
}))
const postToWordPress = vi.fn()
vi.mock('@/lib/donate/wp-mailer', () => ({
  postToWordPress: (...a: unknown[]) => postToWordPress(...a),
}))

import { POST } from './route'

let n = 0
function req(body: unknown) {
  n += 1
  return new Request('http://localhost/api/donate/portal', {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-real-ip': `10.0.0.${n}` },
    body: JSON.stringify(body),
  }) as unknown as Parameters<typeof POST>[0]
}

describe('POST /api/donate/portal', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    postToWordPress.mockResolvedValue(true)
  })

  afterEach(() => {
    deferred.length = 0
    vi.restoreAllMocks()
    listCustomers.mockReset()
    createPortal.mockReset()
    postToWordPress.mockReset()
  })

  it('answers a stranger exactly as it answers a donor', async () => {
    listCustomers.mockResolvedValue({ data: [] })
    const stranger = await POST(req({ email: 'no@example.org', locale: 'en' }))

    listCustomers.mockResolvedValue({ data: [{ id: 'cus_1' }] })
    createPortal.mockResolvedValue({ url: 'https://billing.stripe.com/p/s/1' })
    const donor = await POST(req({ email: 'yes@example.org', locale: 'en' }))

    expect(stranger.status).toBe(200)
    expect(donor.status).toBe(200)
    expect(await stranger.json()).toEqual(await donor.json())
  })

  it('answers before looking the address up, so timing reveals nothing', async () => {
    let resolveList: (v: unknown) => void = () => {}
    listCustomers.mockReturnValue(new Promise((r) => (resolveList = r)))

    const res = await POST(req({ email: 'slow@example.org', locale: 'en' }))

    expect(res.status).toBe(200)
    expect(postToWordPress).not.toHaveBeenCalled()
    resolveList({ data: [] })
    await Promise.all(deferred)
  })

  it('mails a donor their portal link in their language', async () => {
    listCustomers.mockResolvedValue({ data: [{ id: 'cus_9' }] })
    createPortal.mockResolvedValue({ url: 'https://billing.stripe.com/p/s/9' })

    await POST(req({ email: 'donor@example.org', locale: 'de' }))
    await Promise.all(deferred)

    expect(postToWordPress).toHaveBeenCalledWith(
      'cdcf/v1/donation-portal-link',
      {
        email: 'donor@example.org',
        locale: 'de',
        portal_url: 'https://billing.stripe.com/p/s/9',
      }
    )
  })

  it('sends no email at all for an address that never gave', async () => {
    listCustomers.mockResolvedValue({ data: [] })

    await POST(req({ email: 'no@example.org', locale: 'en' }))
    await Promise.all(deferred)

    expect(postToWordPress).not.toHaveBeenCalled()
  })

  it('refuses a body with no email', async () => {
    const res = await POST(req({ locale: 'en' }))

    expect(res.status).toBe(400)
  })
})
