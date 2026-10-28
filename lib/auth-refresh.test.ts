import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { JWT } from 'next-auth/jwt'
import {
  clearTokenExchanges,
  needsRefresh,
  refreshAccessToken,
  RENEW_MARGIN_S,
} from './auth-refresh'

const expired: JWT = {
  name: 'Ann',
  email: 'ann@example.org',
  accessToken: 'old-access',
  refreshToken: 'r1',
  expiresAt: 1_000,
}

function respond(status: number, body: Record<string, unknown>) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(JSON.stringify(body), { status }))
  )
}

beforeEach(() => {
  clearTokenExchanges()
  vi.stubEnv('AUTH_ZITADEL_ISSUER', 'https://auth.example.org')
  vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

describe('refreshAccessToken', () => {
  it('stores the new tokens when Zitadel refreshes them', async () => {
    respond(200, {
      access_token: 'new-access',
      refresh_token: 'r2',
      expires_in: 3600,
    })

    const token = await refreshAccessToken(expired)

    expect(token?.accessToken).toBe('new-access')
    expect(token?.refreshToken).toBe('r2')
    expect(token?.error).toBeUndefined()
  })

  it('ends the session when Zitadel rejects the refresh token', async () => {
    respond(400, {
      error: 'invalid_grant',
      error_description: 'refresh token invalid',
    })

    expect(await refreshAccessToken(expired)).toBeNull()
  })

  it('ends the session when there is no refresh token', async () => {
    expect(
      await refreshAccessToken({ ...expired, refreshToken: undefined })
    ).toBeNull()
  })

  it('keeps the session, flagged, when Zitadel cannot be reached', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('fetch failed')
      })
    )

    const token = await refreshAccessToken(expired)

    expect(token?.error).toBe('RefreshAccessTokenError')
    expect(token?.refreshToken).toBe('r1')
  })

  it('shares one Zitadel call between refreshes of the same token', async () => {
    // Zitadel rotates refresh tokens: a second call with the same token
    // would be rejected and end the session. Concurrent requests that
    // carry the same expired cookie must reuse the first call's result.
    const fetchMock = vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            access_token: 'new-access',
            refresh_token: 'r2',
            expires_in: 3600,
          }),
          { status: 200 }
        )
    )
    vi.stubGlobal('fetch', fetchMock)
    const shared: JWT = { ...expired, refreshToken: 'shared-r1' }

    const [a, b] = await Promise.all([
      refreshAccessToken({ ...shared, name: 'Ann' }),
      refreshAccessToken({ ...shared, name: 'Ann (tab 2)' }),
    ])
    const late = await refreshAccessToken(shared)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect([a?.refreshToken, b?.refreshToken, late?.refreshToken]).toEqual([
      'r2',
      'r2',
      'r2',
    ])
    // Each caller keeps its own claims; only the tokens are shared.
    expect(b?.name).toBe('Ann (tab 2)')
  })

  it('retries after a transient failure instead of reusing it', async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new TypeError('fetch failed'))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({ access_token: 'new-access', expires_in: 3600 }),
          { status: 200 }
        )
      )
    vi.stubGlobal('fetch', fetchMock)
    const token: JWT = { ...expired, refreshToken: 'flaky-r1' }

    expect((await refreshAccessToken(token))?.error).toBe(
      'RefreshAccessTokenError'
    )
    expect((await refreshAccessToken(token))?.accessToken).toBe('new-access')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('keeps the session, flagged, when our client credentials are rejected', async () => {
    // invalid_client is a configuration problem on our side, not a dead
    // refresh token: signing every visitor out would not fix it.
    respond(401, { error: 'invalid_client' })

    expect((await refreshAccessToken(expired))?.error).toBe(
      'RefreshAccessTokenError'
    )
  })

  it('keeps the session, flagged, when Zitadel rate-limits the refresh', async () => {
    respond(429, { error: 'too_many_requests' })

    expect((await refreshAccessToken(expired))?.error).toBe(
      'RefreshAccessTokenError'
    )
  })

  it('keeps the session, flagged, when Zitadel answers with a server error', async () => {
    respond(503, { error: 'unavailable' })

    expect((await refreshAccessToken(expired))?.error).toBe(
      'RefreshAccessTokenError'
    )
  })
})

describe('needsRefresh', () => {
  const now = 1_000_000_000

  it('refreshes an expired token and one inside the renewal margin', () => {
    expect(needsRefresh({ expiresAt: now / 1000 - 1 }, now)).toBe(true)
    expect(
      needsRefresh({ expiresAt: now / 1000 + RENEW_MARGIN_S - 1 }, now)
    ).toBe(true)
  })

  it('keeps a token with more than the margin left', () => {
    expect(
      needsRefresh({ expiresAt: now / 1000 + RENEW_MARGIN_S + 1 }, now)
    ).toBe(false)
  })

  it('refreshes a token without an expiry', () => {
    expect(needsRefresh({}, now)).toBe(true)
  })
})
