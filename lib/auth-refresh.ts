import type { JWT } from 'next-auth/jwt'

// Renew this long before the access token expires. The proxy and the jwt
// callback must agree: the proxy renews (and saves) the session inside
// this window, so pages rendered for the request never refresh it again.
export const RENEW_MARGIN_S = 60

/** Whether the access token is expired or close enough to renew now. */
export function needsRefresh(
  token: { expiresAt?: number },
  nowMs: number = Date.now()
): boolean {
  return (token.expiresAt ?? 0) - nowMs / 1000 <= RENEW_MARGIN_S
}

// Hard cap on the refresh-token round-trip. Without this the JWT
// callback can stall an entire page render if Zitadel is unreachable.
const REFRESH_TIMEOUT_MS = 5000

/**
 * Exchange the refresh token for a new access token.
 *
 * Returns `null` when the session can no longer be refreshed — no refresh
 * token, or Zitadel rejected it (an OAuth 4xx such as `invalid_grant`:
 * expired, revoked, or already rotated). Returning `null` from the jwt
 * callback ends the Auth.js session: `auth()` reports no user and the
 * session cookie is cleared, so the header shows "Sign in" again instead
 * of a name whose token no longer works.
 *
 * A transient failure (network error, timeout, 5xx) keeps the session
 * with the `RefreshAccessTokenError` flag, so the next request retries.
 *
 * Zitadel rotates refresh tokens, so a token can be exchanged only once.
 * Requests that arrive together carry the same expired cookie; they share
 * one exchange (see exchangeOnce) instead of racing Zitadel, where every
 * loser would be rejected and signed out.
 */
export async function refreshAccessToken(token: JWT): Promise<JWT | null> {
  if (!process.env.AUTH_ZITADEL_ISSUER || !token.refreshToken) {
    return null
  }
  const result = await exchangeOnce(token.refreshToken)
  if (result === 'rejected') {
    return null
  }
  if (result === 'unavailable') {
    return { ...token, error: 'RefreshAccessTokenError' }
  }
  return {
    ...token,
    accessToken: result.accessToken,
    expiresAt: result.expiresAt ?? token.expiresAt,
    refreshToken: result.refreshToken ?? token.refreshToken,
    error: undefined,
  }
}

type Exchange =
  | { accessToken: string; refreshToken?: string; expiresAt?: number }
  | 'rejected'
  | 'unavailable'

// How long a finished exchange is reused for requests that still carry
// the old cookie (they were sent before the browser stored the new one).
const REUSE_MS = 60_000

// Shared through globalThis: the proxy and the app are bundled separately
// but run in the same Node process.
const exchanges: Map<string, Promise<Exchange>> = ((
  globalThis as { __cdcfTokenExchanges?: Map<string, Promise<Exchange>> }
).__cdcfTokenExchanges ??= new Map())

/** Test seam: forget every shared exchange. */
export function clearTokenExchanges(): void {
  exchanges.clear()
}

function exchangeOnce(refreshToken: string): Promise<Exchange> {
  const pending = exchanges.get(refreshToken)
  if (pending) {
    return pending
  }
  const exchange = requestTokens(refreshToken)
  exchanges.set(refreshToken, exchange)
  void exchange.then((result) => {
    if (result === 'unavailable') {
      // A transient failure must not stick: the next request retries.
      exchanges.delete(refreshToken)
      return
    }
    setTimeout(() => exchanges.delete(refreshToken), REUSE_MS).unref?.()
  })
  return exchange
}

async function requestTokens(refreshToken: string): Promise<Exchange> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), REFRESH_TIMEOUT_MS)
  try {
    const response = await fetch(
      `${process.env.AUTH_ZITADEL_ISSUER}/oauth/v2/token`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          client_id: process.env.AUTH_ZITADEL_ID ?? '',
          client_secret: process.env.AUTH_ZITADEL_SECRET ?? '',
          grant_type: 'refresh_token',
          refresh_token: refreshToken,
        }),
        signal: controller.signal,
      }
    )
    const tokens = (await response.json()) as {
      access_token?: string
      refresh_token?: string
      expires_in?: number
      error_description?: string
    }
    if (response.status >= 400 && response.status < 500) {
      console.error(
        '[auth] refresh rejected, ending session:',
        tokens.error_description ?? response.status
      )
      return 'rejected'
    }
    if (!response.ok || !tokens.access_token) {
      throw new Error(tokens.error_description ?? 'Token refresh failed')
    }
    const expiresIn = Number(tokens.expires_in)
    return {
      accessToken: tokens.access_token,
      refreshToken: tokens.refresh_token,
      expiresAt: Number.isFinite(expiresIn)
        ? Math.floor(Date.now() / 1000 + expiresIn)
        : undefined,
    }
  } catch (err) {
    // AbortError surfaces here exactly like other fetch errors so the
    // RefreshAccessTokenError fall-through covers both timeout and
    // network failures uniformly.
    console.error('[auth] refresh failed:', err)
    return 'unavailable'
  } finally {
    clearTimeout(timer)
  }
}
