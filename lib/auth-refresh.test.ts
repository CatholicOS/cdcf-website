import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { JWT } from 'next-auth/jwt'
import { refreshAccessToken } from './auth-refresh'

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
