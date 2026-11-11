import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const createSession = vi.fn()
vi.mock('@/lib/stripe', () => ({
  getStripe: () => ({ checkout: { sessions: { create: createSession } } }),
}))
vi.mock('@/lib/wordpress/api', () => ({
  getDonatableProjects: vi.fn(async () => [
    {
      slug: 'familygraph-2',
      title: 'FamilyGraph IT',
      canonicalSlug: 'familygraph',
      canonicalTitle: 'FamilyGraph',
    },
  ]),
}))

import { POST } from './route'

let n = 0
function req(body: unknown) {
  n += 1
  return new Request('http://localhost/api/donate/create-session', {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-real-ip': `10.1.0.${n}` },
    body: JSON.stringify(body),
  }) as unknown as Parameters<typeof POST>[0]
}

describe('POST /api/donate/create-session', () => {
  beforeEach(() => {
    vi.stubEnv('NEXT_PUBLIC_SITE_URL', 'https://example.org')
    createSession.mockResolvedValue({ url: 'https://checkout.stripe.com/c/x' })
  })

  afterEach(() => {
    vi.unstubAllEnvs()
    createSession.mockReset()
  })

  it('records the English slug and title for reporting, the local title for the email', async () => {
    const res = await POST(
      req({
        amountCents: 2500,
        frequency: 'monthly',
        projectSlug: 'familygraph-2',
        locale: 'it',
      })
    )

    expect(res.status).toBe(200)
    const params = createSession.mock.calls[0][0]
    const expected = {
      project_slug: 'familygraph',
      project_title_en: 'FamilyGraph',
      project_title: 'FamilyGraph IT',
      locale: 'it',
      frequency: 'monthly',
    }
    expect(params.metadata).toEqual(expected)
    // Renewals read the subscription's copy: session metadata does not
    // propagate to it.
    expect(params.subscription_data.metadata).toEqual(expected)
    expect(params.success_url).toBe(
      'https://example.org/it/donate?status=success'
    )
  })

  it('leaves every designation field empty for the general fund', async () => {
    await POST(req({ amountCents: 500, frequency: 'once', locale: 'en' }))

    const params = createSession.mock.calls[0][0]
    expect(params.metadata).toMatchObject({
      project_slug: '',
      project_title_en: '',
      project_title: '',
    })
    expect(params.submit_type).toBe('donate')
  })

  it('refuses a project that does not accept donations', async () => {
    const res = await POST(
      req({
        amountCents: 500,
        frequency: 'once',
        projectSlug: 'x',
        locale: 'en',
      })
    )

    expect(res.status).toBe(400)
    expect(createSession).not.toHaveBeenCalled()
  })
})
