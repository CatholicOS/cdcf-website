import { describe, expect, it, vi } from 'vitest'
import {
  applySetCookies,
  findSessionCookie,
  refreshSessionCookies,
} from './session-refresh'

const NOW = 1_000_000

describe('findSessionCookie', () => {
  it('finds the secure cookie, whole or chunked', () => {
    expect(findSessionCookie(['__Secure-authjs.session-token'])).toEqual({
      name: '__Secure-authjs.session-token',
      secure: true,
    })
    expect(
      findSessionCookie([
        'NEXT_LOCALE',
        '__Secure-authjs.session-token.0',
        '__Secure-authjs.session-token.1',
      ])
    ).toEqual({ name: '__Secure-authjs.session-token', secure: true })
  })

  it('finds the plain cookie used over http in development', () => {
    expect(findSessionCookie(['authjs.session-token'])).toEqual({
      name: 'authjs.session-token',
      secure: false,
    })
  })

  it('returns null when nobody is signed in', () => {
    expect(findSessionCookie(['NEXT_LOCALE', 'authjs.csrf-token'])).toBeNull()
  })
})

describe('applySetCookies', () => {
  it('replaces, adds and removes cookies in a Cookie header', () => {
    expect(
      applySetCookies('a=1; s.0=old0; s.1=old1; keep=yes', [
        's.0=new0; Path=/; HttpOnly; SameSite=Lax',
        's.1=; Path=/; Max-Age=0',
        'b=2; Path=/',
      ])
    ).toBe('a=1; s.0=new0; keep=yes; b=2')
  })

  it('treats an expiry in the past as a removal', () => {
    expect(
      applySetCookies('s=old; x=1', [
        's=gone; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
      ])
    ).toBe('x=1')
  })
})

function request(cookie: string) {
  return { headers: new Headers({ cookie }) }
}

describe('refreshSessionCookies', () => {
  const sessionResponse = () =>
    new Response('{}', {
      headers: [
        [
          'set-cookie',
          '__Secure-authjs.session-token=fresh; Path=/; HttpOnly; Secure',
        ],
      ],
    })

  it('renews a session about to expire and hands the new cookie on', async () => {
    const fetchSession = vi.fn(async () => sessionResponse())

    const result = await refreshSessionCookies(
      request('NEXT_LOCALE=it; __Secure-authjs.session-token=stale'),
      {
        readToken: async () => ({ expiresAt: NOW / 1000 + 30 }),
        fetchSession,
      },
      NOW
    )

    expect(fetchSession).toHaveBeenCalledOnce()
    expect(result).toEqual({
      cookieHeader: 'NEXT_LOCALE=it; __Secure-authjs.session-token=fresh',
      setCookies: [
        '__Secure-authjs.session-token=fresh; Path=/; HttpOnly; Secure',
      ],
    })
  })

  it('passes on only the session cookie, never the CSRF or callback cookies', async () => {
    // The session endpoint also issues authjs.csrf-token and
    // authjs.callback-url; overwriting the CSRF cookie would break a
    // sign-in form already open in another tab.
    const fetchSession = vi.fn(
      async () =>
        new Response('{}', {
          headers: [
            ['set-cookie', '__Secure-authjs.csrf-token=new; Path=/'],
            ['set-cookie', '__Secure-authjs.callback-url=x; Path=/'],
            ['set-cookie', '__Secure-authjs.session-token.0=fresh0; Path=/'],
            ['set-cookie', '__Secure-authjs.session-token.1=fresh1; Path=/'],
            ['set-cookie', '__Secure-authjs.session-token=; Path=/; Max-Age=0'],
          ],
        })
    )

    const result = await refreshSessionCookies(
      request(
        '__Secure-authjs.csrf-token=mine; __Secure-authjs.session-token=stale'
      ),
      {
        readToken: async () => ({ expiresAt: NOW / 1000 + 30 }),
        fetchSession,
      },
      NOW
    )

    expect(result?.setCookies).toEqual([
      '__Secure-authjs.session-token.0=fresh0; Path=/',
      '__Secure-authjs.session-token.1=fresh1; Path=/',
      '__Secure-authjs.session-token=; Path=/; Max-Age=0',
    ])
    expect(result?.cookieHeader).toBe(
      '__Secure-authjs.csrf-token=mine; ' +
        '__Secure-authjs.session-token.0=fresh0; __Secure-authjs.session-token.1=fresh1'
    )
  })

  it('leaves a session with time left alone', async () => {
    const fetchSession = vi.fn(async () => sessionResponse())

    const result = await refreshSessionCookies(
      request('__Secure-authjs.session-token=ok'),
      {
        readToken: async () => ({ expiresAt: NOW / 1000 + 3600 }),
        fetchSession,
      },
      NOW
    )

    expect(result).toBeNull()
    expect(fetchSession).not.toHaveBeenCalled()
  })

  it('does nothing without a session cookie', async () => {
    const readToken = vi.fn()

    expect(
      await refreshSessionCookies(
        request('NEXT_LOCALE=it'),
        { readToken, fetchSession: vi.fn() },
        NOW
      )
    ).toBeNull()
    expect(readToken).not.toHaveBeenCalled()
  })

  it('does nothing when the cookie cannot be read', async () => {
    const fetchSession = vi.fn()

    expect(
      await refreshSessionCookies(
        request('__Secure-authjs.session-token=garbage'),
        { readToken: async () => null, fetchSession },
        NOW
      )
    ).toBeNull()
    expect(fetchSession).not.toHaveBeenCalled()
  })
})
