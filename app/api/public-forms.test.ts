import { createHmac } from 'node:crypto'
import { NextRequest } from 'next/server'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The public form routes proxy to WordPress. Both layers rate-limit per
// visitor, so each route must (1) limit on the address our proxy saw, not
// one the client wrote into X-Forwarded-For, and (2) tell WordPress who
// the visitor is, signed with the shared secret.

const routes = [
  {
    path: 'submit-project',
    limit: 3,
    load: () => import('./submit-project/route'),
  },
  {
    path: 'submit-project/send-code',
    limit: 5,
    load: () => import('./submit-project/send-code/route'),
  },
  {
    path: 'refer-community-project',
    limit: 3,
    load: () => import('./refer-community-project/route'),
  },
  {
    path: 'refer-community-project/send-code',
    limit: 5,
    load: () => import('./refer-community-project/send-code/route'),
  },
  {
    path: 'refer-local-group',
    limit: 3,
    load: () => import('./refer-local-group/route'),
  },
  {
    path: 'refer-local-group/send-code',
    limit: 5,
    load: () => import('./refer-local-group/send-code/route'),
  },
]

const body = JSON.stringify({
  project_name: 'Liturgy Helper',
  group_name: 'Rome group',
  url: 'https://example.org',
  description: 'A project.',
  submitter_name: 'Sam',
  submitter_email: 'sam@example.org',
  verification_code: '123456',
})

const post = (path: string, forwardedFor: string, payload = body) =>
  new NextRequest(`http://localhost/api/${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-forwarded-for': forwardedFor,
    },
    body: payload,
  })

let fetchMock: ReturnType<typeof vi.fn>
beforeEach(() => {
  vi.resetModules() // a fresh in-memory limiter per test
  vi.stubEnv('WP_GRAPHQL_URL', 'https://wp.example.org/graphql')
  vi.stubEnv('CDCF_CLIENT_IP_SECRET', 'shared')
  fetchMock = vi.fn(async () => Response.json({ success: true }))
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => {
  vi.unstubAllEnvs()
  vi.unstubAllGlobals()
})

describe.each(routes)('POST /api/$path', (route) => {
  it('limits on the address the proxy appended, not a spoofed first entry', async () => {
    const { POST } = await route.load()
    for (let i = 0; i < route.limit; i++) {
      // Invalid JSON: counted by the limiter, never sent to WordPress.
      await POST(post(route.path, `10.9.9.${i}, 203.0.113.9`, '{'))
    }
    const res = await POST(post(route.path, '10.9.9.99, 203.0.113.9', '{'))
    expect(res.status).toBe(429)
  })

  it('forwards the signed visitor address to WordPress', async () => {
    const { POST } = await route.load()
    const res = await POST(post(route.path, '6.6.6.6, 203.0.113.9'))
    expect(res.status).toBe(200)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(`https://wp.example.org/wp-json/cdcf/v1/${route.path}`)
    const headers = new Headers(init.headers)
    expect(headers.get('X-CDCF-Client-IP')).toBe('203.0.113.9')
    expect(headers.get('X-CDCF-Client-IP-Signature')).toBe(
      createHmac('sha256', 'shared').update('203.0.113.9').digest('hex')
    )
  })
})
