import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { Session } from 'next-auth'

// Mock `next/navigation`'s redirect — Next throws a special "NEXT_REDIRECT"
// error when it's called inside a Server Component. We capture the call
// arg instead so tests can assert on the target without an exception.
const redirectMock = vi.fn()
vi.mock('next/navigation', () => ({
  redirect: (url: string) => {
    redirectMock(url)
    // Mimic Next's behaviour of throwing (callers expect requireSession
    // to short-circuit rather than fall through).
    throw new Error(`NEXT_REDIRECT:${url}`)
  },
}))

const authMock = vi.fn()
vi.mock('@/lib/auth', () => ({
  auth: () => authMock(),
}))

import { requireSession, requireRole, getAccessToken } from './auth-utils'

beforeEach(() => {
  redirectMock.mockReset()
  authMock.mockReset()
})

describe('requireSession', () => {
  it('returns the session when a user is present', async () => {
    const session = { user: { email: 'a@b.org' }, expires: 'soon' }
    authMock.mockResolvedValue(session)

    await expect(requireSession()).resolves.toEqual(session)
    expect(redirectMock).not.toHaveBeenCalled()
  })

  it('redirects to /api/auth/signin when auth() returns null', async () => {
    authMock.mockResolvedValue(null)

    await expect(requireSession()).rejects.toThrow(/NEXT_REDIRECT/)
    expect(redirectMock).toHaveBeenCalledWith('/api/auth/signin')
  })

  it('redirects when auth() returns a session without user', async () => {
    authMock.mockResolvedValue({ user: undefined, expires: 'soon' })

    await expect(requireSession()).rejects.toThrow(/NEXT_REDIRECT/)
    expect(redirectMock).toHaveBeenCalledWith('/api/auth/signin')
  })
})

describe('requireRole', () => {
  it('returns the session when the role is granted', async () => {
    const session = {
      user: { email: 'a@b.org', roles: ['team_member', 'editor'] },
      expires: 'soon',
    }
    authMock.mockResolvedValue(session)

    await expect(requireRole('team_member')).resolves.toEqual(session)
  })

  it('throws a 403-shaped error when the role is missing', async () => {
    const session = {
      user: { email: 'a@b.org', roles: ['editor'] },
      expires: 'soon',
    }
    authMock.mockResolvedValue(session)

    let thrown: unknown
    try {
      await requireRole('team_member')
    } catch (err) {
      thrown = err
    }
    expect(thrown).toBeInstanceOf(Error)
    expect((thrown as Error).message).toMatch(/team_member/)
    expect((thrown as Error & { status?: number }).status).toBe(403)
  })

  it('throws 403 when the user has no roles at all', async () => {
    authMock.mockResolvedValue({
      user: { email: 'a@b.org' /* roles omitted */ },
      expires: 'soon',
    })

    await expect(requireRole('admin')).rejects.toThrow(/admin/)
  })

  it('delegates to requireSession (redirect) when unauthenticated', async () => {
    authMock.mockResolvedValue(null)

    await expect(requireRole('team_member')).rejects.toThrow(/NEXT_REDIRECT/)
    expect(redirectMock).toHaveBeenCalledWith('/api/auth/signin')
  })
})

describe('getAccessToken', () => {
  it('returns undefined for a null/undefined session', () => {
    expect(getAccessToken(null)).toBeUndefined()
    expect(getAccessToken(undefined)).toBeUndefined()
  })

  it('returns undefined when the session carries a refresh error', () => {
    const session: Session = {
      accessToken: 'still-here-but-stale',
      error: 'RefreshAccessTokenError',
      expires: '2099-01-01T00:00:00.000Z',
    }
    expect(getAccessToken(session)).toBeUndefined()
  })

  it('returns the access token for a healthy session', () => {
    const session: Session = {
      accessToken: 'jwt-abc',
      expires: '2099-01-01T00:00:00.000Z',
    }
    expect(getAccessToken(session)).toBe('jwt-abc')
  })
})
