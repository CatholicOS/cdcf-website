import type { JWT } from 'next-auth/jwt'

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
 */
export async function refreshAccessToken(token: JWT): Promise<JWT | null> {
  const issuer = process.env.AUTH_ZITADEL_ISSUER
  if (!issuer || !token.refreshToken) {
    return null
  }
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), REFRESH_TIMEOUT_MS)
  try {
    const response = await fetch(`${issuer}/oauth/v2/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        client_id: process.env.AUTH_ZITADEL_ID ?? '',
        client_secret: process.env.AUTH_ZITADEL_SECRET ?? '',
        grant_type: 'refresh_token',
        refresh_token: token.refreshToken,
      }),
      signal: controller.signal,
    })
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
      return null
    }
    if (!response.ok || !tokens.access_token) {
      throw new Error(tokens.error_description ?? 'Token refresh failed')
    }
    const expiresIn = Number(tokens.expires_in)
    return {
      ...token,
      accessToken: tokens.access_token,
      expiresAt: Number.isFinite(expiresIn)
        ? Math.floor(Date.now() / 1000 + expiresIn)
        : token.expiresAt,
      refreshToken: tokens.refresh_token ?? token.refreshToken,
      error: undefined,
    }
  } catch (err) {
    // AbortError surfaces here exactly like other fetch errors so the
    // RefreshAccessTokenError fall-through covers both timeout and
    // network failures uniformly.
    console.error('[auth] refresh failed:', err)
    return { ...token, error: 'RefreshAccessTokenError' }
  } finally {
    clearTimeout(timer)
  }
}
