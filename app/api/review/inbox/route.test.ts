import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
const auth = vi.hoisted(() => vi.fn())
vi.mock('@/lib/auth', () => ({ auth }))
const fetchInbox = vi.hoisted(() => vi.fn())
vi.mock('@/lib/review-api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/review-api')>()),
  fetchInbox,
}))

import { GET } from './route'
import { ReviewApiError } from '@/lib/review-api'

const session = { user: { email: 'm@example.org' } }
const get = (qs = '') =>
  GET(new NextRequest(`http://localhost/api/review/inbox${qs}`))

beforeEach(() => {
  auth.mockReset()
  auth.mockResolvedValue(session)
  fetchInbox.mockReset()
})

describe('GET /api/review/inbox', () => {
  it('passes the filter and page through', async () => {
    const page = { items: [], page: 3, has_more: false, unread: 1 }
    fetchInbox.mockResolvedValue(page)
    const res = await get('?unread=1&page=3')
    expect(res.status).toBe(200)
    expect(await res.json()).toEqual(page)
    expect(fetchInbox).toHaveBeenCalledWith(session, { unread: true, page: 3 })
  })

  it('treats a junk page as page 1', async () => {
    fetchInbox.mockResolvedValue({
      items: [],
      page: 1,
      has_more: false,
      unread: 0,
    })
    await get('?page=abc')
    expect(fetchInbox).toHaveBeenCalledWith(session, {
      unread: false,
      page: 1,
    })
  })

  it('needs a session', async () => {
    auth.mockResolvedValue(null)
    expect((await get()).status).toBe(401)
  })

  it('maps WordPress errors', async () => {
    fetchInbox.mockRejectedValue(
      new ReviewApiError('no', 403, 'rest_forbidden')
    )
    const res = await get()
    expect(res.status).toBe(403)
    expect(await res.json()).toEqual({
      error: 'rest_forbidden',
      message: 'no',
    })
  })
})
