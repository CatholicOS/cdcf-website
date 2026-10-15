import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
const requestStatusLink = vi.hoisted(() => vi.fn())
vi.mock('@/lib/submission-status-api', () => ({ requestStatusLink }))

import { POST } from './route'

let ip = 0
const post = (body: string, headers: Record<string, string> = {}) =>
  POST(
    new NextRequest('http://localhost/api/submission-status/resend-link', {
      method: 'POST',
      // A fresh client address per call unless the test pins one.
      headers: {
        'Content-Type': 'application/json',
        'x-real-ip': `198.51.100.${++ip}`,
        ...headers,
      },
      body,
    })
  )

const valid = JSON.stringify({ project_id: 40, email: 'sam@example.org' })

const expectAccepted = async (res: Response) => {
  expect(res.status).toBe(202)
  expect(await res.json()).toEqual({ accepted: true })
}

beforeEach(() => {
  requestStatusLink.mockReset()
  requestStatusLink.mockResolvedValue(undefined)
})
afterEach(() => vi.restoreAllMocks())

describe('POST /api/submission-status/resend-link', () => {
  it('forwards a well-formed request', async () => {
    await expectAccepted(await post(valid))
    expect(requestStatusLink).toHaveBeenCalledWith({
      project_id: 40,
      email: 'sam@example.org',
    })
  })

  it('answers the same for malformed or oversized requests, without forwarding', async () => {
    for (const body of [
      '{oops',
      JSON.stringify({ project_id: 0, email: 'a@b.c' }),
      JSON.stringify({ project_id: 40, email: 'a'.repeat(255) }),
    ]) {
      await expectAccepted(await post(body))
    }
    await expectAccepted(
      await post(valid, { 'content-length': String(5 * 1024) })
    )
    expect(requestStatusLink).not.toHaveBeenCalled()
  })

  it('answers the same when WordPress fails', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    requestStatusLink.mockRejectedValue(new Error('down'))
    await expectAccepted(await post(valid))
  })

  it('stops forwarding after five requests an hour from one address', async () => {
    const same = { 'x-real-ip': '203.0.113.77' }
    for (let i = 0; i < 6; i++) await expectAccepted(await post(valid, same))
    expect(requestStatusLink).toHaveBeenCalledTimes(5)
  })
})
