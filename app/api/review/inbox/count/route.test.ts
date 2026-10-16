import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
const auth = vi.hoisted(() => vi.fn())
vi.mock('@/lib/auth', () => ({ auth }))
const fetchInboxCount = vi.hoisted(() => vi.fn())
vi.mock('@/lib/review-api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/review-api')>()),
  fetchInboxCount,
}))

import { GET } from './route'
import { ReviewApiError } from '@/lib/review-api'

const session = { user: { email: 'm@example.org' } }

beforeEach(() => {
  auth.mockReset()
  auth.mockResolvedValue(session)
  fetchInboxCount.mockReset()
})

describe('GET /api/review/inbox/count', () => {
  it('returns the unread count', async () => {
    fetchInboxCount.mockResolvedValue({ unread: 2 })
    const res = await GET()
    expect(res.status).toBe(200)
    expect(await res.json()).toEqual({ unread: 2 })
    expect(fetchInboxCount).toHaveBeenCalledWith(session)
  })

  it('needs a session', async () => {
    auth.mockResolvedValue(null)
    expect((await GET()).status).toBe(401)
  })

  it('maps WordPress errors', async () => {
    fetchInboxCount.mockRejectedValue(
      new ReviewApiError('no', 403, 'rest_forbidden')
    )
    const res = await GET()
    expect(res.status).toBe(403)
    expect(await res.json()).toEqual({
      error: 'rest_forbidden',
      message: 'no',
    })
  })
})
