import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
const auth = vi.hoisted(() => vi.fn())
vi.mock('@/lib/auth', () => ({ auth }))
const markInboxRead = vi.hoisted(() => vi.fn())
vi.mock('@/lib/review-api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/review-api')>()),
  markInboxRead,
}))

import { POST } from './route'

const session = { user: { email: 'm@example.org' } }
const post = (body: unknown) =>
  POST(
    new NextRequest('http://localhost/api/review/inbox/read', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
  )

beforeEach(() => {
  auth.mockReset()
  auth.mockResolvedValue(session)
  markInboxRead.mockReset()
  markInboxRead.mockResolvedValue({ unread: 0 })
})

describe('POST /api/review/inbox/read', () => {
  it('marks all', async () => {
    const res = await post({ all: true })
    expect(res.status).toBe(200)
    expect(await res.json()).toEqual({ unread: 0 })
    expect(markInboxRead).toHaveBeenCalledWith(session, { all: true })
  })

  it('marks ids', async () => {
    await post({ event_ids: [4, 5] })
    expect(markInboxRead).toHaveBeenCalledWith(session, { event_ids: [4, 5] })
  })

  it.each([
    [{}],
    [{ event_ids: [] }],
    [{ event_ids: [1, 'x'] }],
    [{ event_ids: [0] }],
    [{ event_ids: [1.5] }],
    [{ event_ids: Array.from({ length: 201 }, (_, i) => i + 1) }],
    [{ all: 'yes' }],
  ])('rejects %j', async (body) => {
    const res = await post(body)
    expect(res.status).toBe(400)
    expect(markInboxRead).not.toHaveBeenCalled()
  })

  it('needs a session', async () => {
    auth.mockResolvedValue(null)
    expect((await post({ all: true })).status).toBe(401)
  })
})
