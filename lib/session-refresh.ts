/**
 * Renew the Auth.js session in the proxy, before the page renders.
 *
 * `auth()` in a Server Component or route handler can refresh an expired
 * access token but cannot write the result back to the cookie. Zitadel
 * rotates refresh tokens, so that unsaved refresh used up the stored token
 * and the next refresh was rejected (`Errors.OIDCSession.RefreshTokenInvalid`),
 * ending the session after a single access-token lifetime.
 *
 * The proxy can do both: when the session is about to expire it asks
 * Auth.js's own session endpoint to refresh it (Auth.js re-signs and
 * chunks the cookie), sends the new cookie to the browser, and rewrites
 * the request's Cookie header so everything rendered for this request
 * already sees the fresh token and never refreshes it again.
 */

import { needsRefresh } from './auth-refresh'

const SESSION_COOKIES = [
  { name: '__Secure-authjs.session-token', secure: true },
  { name: 'authjs.session-token', secure: false },
] as const

export type SessionCookie = (typeof SESSION_COOKIES)[number]

function isSessionCookie(name: string, cookie: SessionCookie): boolean {
  return name === cookie.name || name.startsWith(`${cookie.name}.`)
}

/** The Auth.js session cookie present on the request, whole or chunked. */
export function findSessionCookie(names: string[]): SessionCookie | null {
  return (
    SESSION_COOKIES.find((c) => names.some((n) => isSessionCookie(n, c))) ??
    null
  )
}

/**
 * Apply Set-Cookie headers to a Cookie request header: set or replace each
 * cookie, and drop the ones being cleared (empty value, `Max-Age=0`, or an
 * `Expires` in the past).
 */
export function applySetCookies(
  cookieHeader: string,
  setCookies: string[]
): string {
  const jar = new Map<string, string>()
  for (const pair of cookieHeader.split(';')) {
    const eq = pair.indexOf('=')
    if (eq > 0) {
      jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim())
    }
  }
  for (const header of setCookies) {
    const [pair, ...attributes] = header.split(';')
    const eq = pair.indexOf('=')
    if (eq <= 0) {
      continue
    }
    const name = pair.slice(0, eq).trim()
    const value = pair.slice(eq + 1).trim()
    if (value === '' || isRemoval(attributes)) {
      jar.delete(name)
    } else {
      jar.set(name, value)
    }
  }
  return [...jar].map(([name, value]) => `${name}=${value}`).join('; ')
}

function isRemoval(attributes: string[]): boolean {
  for (const attribute of attributes) {
    const [key, ...rest] = attribute.split('=')
    const name = key.trim().toLowerCase()
    const value = rest.join('=').trim()
    if (name === 'max-age' && Number(value) <= 0) {
      return true
    }
    if (name === 'expires' && Date.parse(value) <= Date.now()) {
      return true
    }
  }
  return false
}

export interface RefreshDeps {
  /** Decode the session cookie; null when it is missing or unreadable. */
  readToken: (
    headers: Headers,
    cookie: SessionCookie
  ) => Promise<{ expiresAt?: number } | null>
  /** Call Auth.js's GET /api/auth/session with these request headers. */
  fetchSession: (headers: Headers) => Promise<Response>
}

/**
 * When the request's session is about to expire, renew it through Auth.js.
 * Returns the request Cookie header to forward and the Set-Cookie headers
 * to send back, or null when nothing needs to change.
 */
export async function refreshSessionCookies(
  request: { headers: Headers },
  deps: RefreshDeps,
  nowMs: number = Date.now()
): Promise<{ cookieHeader: string; setCookies: string[] } | null> {
  const cookieHeader = request.headers.get('cookie') ?? ''
  const names = cookieHeader
    .split(';')
    .map((pair) => pair.split('=')[0].trim())
    .filter(Boolean)
  const cookie = findSessionCookie(names)
  if (!cookie) {
    return null
  }
  const token = await deps.readToken(request.headers, cookie)
  if (!token?.expiresAt || !needsRefresh(token, nowMs)) {
    return null
  }
  const response = await deps.fetchSession(request.headers)
  // Only the session cookie: the endpoint also issues fresh CSRF and
  // callback-url cookies, and replacing the CSRF cookie would break a
  // sign-in form already open in another tab.
  const setCookies = response.headers
    .getSetCookie()
    .filter((header) =>
      isSessionCookie(header.slice(0, header.indexOf('=')).trim(), cookie)
    )
  if (setCookies.length === 0) {
    return null
  }
  return { cookieHeader: applySetCookies(cookieHeader, setCookies), setCookies }
}
