import { describe, expect, it, vi } from 'vitest'

// review-api → auth-utils → next-auth, which Node can't load outside Next.
vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))

import { ReviewApiError } from './review-api'
import {
  attachmentResponse,
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from './review-route'

describe('review-route helpers', () => {
  it('parses positive integer ids only', () => {
    expect(parseId('42')).toBe(42)
    for (const bad of [
      '0',
      '-1',
      '1.5',
      'abc',
      '',
      '01',
      '99999999999999999999',
    ]) {
      expect(parseId(bad)).toBeNull()
    }
  })

  it('reads JSON objects and rejects everything else', async () => {
    const req = (body: string) =>
      new Request('http://x', { method: 'POST', body })
    expect(await readJsonObject(req('{"a":1}'))).toEqual({ a: 1 })
    expect(await readJsonObject(req('[1]'))).toBeNull()
    expect(await readJsonObject(req('null'))).toBeNull()
    expect(await readJsonObject(req('{oops'))).toBeNull()
  })

  it('builds 400 and 401 responses', async () => {
    const r = badRequest('invalid_field', 'body_md')
    expect(r.status).toBe(400)
    expect(await r.json()).toEqual({
      error: 'invalid_field',
      field: 'body_md',
    })
    expect(unauthorized().status).toBe(401)
  })

  it('maps ReviewApiError to its status and code', async () => {
    const r = reviewErrorResponse(
      new ReviewApiError('Locked.', 403, 'review_locked')
    )
    expect(r.status).toBe(403)
    expect(await r.json()).toEqual({
      error: 'review_locked',
      message: 'Locked.',
    })
    const generic = reviewErrorResponse(new ReviewApiError('Nope', 502))
    expect(await generic.json()).toEqual({
      error: 'wp_error',
      message: 'Nope',
    })
  })

  it('rethrows unexpected errors', () => {
    expect(() => reviewErrorResponse(new Error('boom'))).toThrow('boom')
  })

  it('passes attachment bytes and only the safe headers through', async () => {
    const wp = new Response('bytes', {
      status: 200,
      headers: {
        'Content-Type': 'image/png',
        'Content-Disposition': 'inline',
        'X-Content-Type-Options': 'nosniff',
        'Content-Security-Policy': "default-src 'none'; sandbox",
        'Cache-Control': 'private, no-store',
        'Content-Length': '5',
        'Set-Cookie': 'wp=1',
      },
    })

    const out = attachmentResponse(wp)

    expect(await out.text()).toBe('bytes')
    expect(out.headers.get('content-type')).toBe('image/png')
    expect(out.headers.get('x-content-type-options')).toBe('nosniff')
    expect(out.headers.get('content-security-policy')).toBe(
      "default-src 'none'; sandbox"
    )
    expect(out.headers.get('set-cookie')).toBeNull()
    // undici may decompress the upstream body; a forwarded length would lie.
    expect(out.headers.get('content-length')).toBeNull()
  })
})
