import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

const set = vi.fn()
vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
vi.mock('next/headers', () => ({
  cookies: vi.fn(async () => ({ set })),
}))
const redirectMock = vi.hoisted(() => vi.fn())
vi.mock('next/navigation', () => ({ redirect: redirectMock }))

import { GET } from './route'

const TOKEN = 'a1b2c3d4'.repeat(8)

const call = (id: string, query: string) =>
  GET(
    new NextRequest(
      `http://localhost/api/submission-status/${id}/open?${query}`
    ),
    { params: Promise.resolve({ id }) }
  )

beforeEach(() => {
  set.mockClear()
  redirectMock.mockReset()
  // Like the real redirect(): throws, so the handler stops there.
  redirectMock.mockImplementation((path: string) => {
    throw new Error(`REDIRECT:${path}`)
  })
})

// Exact redirect target, and the token never part of it.
const expectRedirect = (path: string) => {
  expect(redirectMock).toHaveBeenCalledTimes(1)
  expect(redirectMock).toHaveBeenCalledWith(path)
  const target = redirectMock.mock.calls[0][0] as string
  expect(target).not.toContain('t=')
  expect(target).not.toContain(TOKEN)
}

describe('GET /api/submission-status/[id]/open', () => {
  it('stores a valid token in a cookie and redirects without it', async () => {
    await expect(call('42', `lang=it&t=${TOKEN}`)).rejects.toThrow('REDIRECT:')
    expectRedirect('/it/submission-status/42')
    expect(set).toHaveBeenCalledTimes(1)
    expect(set).toHaveBeenCalledWith('cdcf_status_42', TOKEN, {
      httpOnly: true,
      secure: false,
      sameSite: 'lax',
      path: '/',
      maxAge: 15552000,
    })
  })

  it.each(['abc', 'A'.repeat(64)])(
    'ignores the malformed token %s but still redirects',
    async (bad) => {
      await expect(call('42', `lang=it&t=${bad}`)).rejects.toThrow('REDIRECT:')
      expectRedirect('/it/submission-status/42')
      expect(set).not.toHaveBeenCalled()
    }
  )

  it('falls back to the default locale for an unknown lang', async () => {
    await expect(call('42', `lang=xx&t=${TOKEN}`)).rejects.toThrow('REDIRECT:')
    expectRedirect('/submission-status/42')
  })

  it.each(['0', 'abc'])('returns 404 for the id %s', async (id) => {
    const response = await call(id, `lang=it&t=${TOKEN}`)
    expect(response.status).toBe(404)
    expect(set).not.toHaveBeenCalled()
  })
})
