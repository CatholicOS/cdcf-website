import NextAuth, { type DefaultSession } from 'next-auth'
import type { JWT } from 'next-auth/jwt'
import Zitadel from 'next-auth/providers/zitadel'

// Plesk Passenger surfaces the bind address (0.0.0.0:3000) to Next.js
// standalone instead of the public hostname, so Auth.js's redirect_uri
// construction — even with trustHost: true — produces
// https://0.0.0.0:3000/api/auth/callback/zitadel, which Zitadel
// rejects with "invalid_request: requested redirect_uri is missing in
// the client configuration". The canonical fix per Auth.js v5 is to set
// AUTH_URL to the public origin. We already configure NEXT_PUBLIC_SITE_URL
// per environment (prod / staging / dev) at build time + runtime via Plesk's
// app env, so promote it to AUTH_URL when AUTH_URL itself isn't set.
// See `project_plesk_passenger_port_leak` memory + proxy.ts for the
// sibling fix that normalizes redirect-Location leaks the same way.
if (!process.env.AUTH_URL && process.env.NEXT_PUBLIC_SITE_URL) {
  process.env.AUTH_URL = process.env.NEXT_PUBLIC_SITE_URL
}

// Augment NextAuth's session/JWT types with the claims we surface.
// Kept inline (rather than in a separate .d.ts) so this file is the
// single source of truth for the shape we expose to consumers.
declare module 'next-auth' {
  interface Session {
    accessToken?: string
    error?: 'RefreshAccessTokenError'
    user?: {
      roles?: string[]
      locale?: string
    } & DefaultSession['user']
  }
}

declare module 'next-auth/jwt' {
  interface JWT {
    accessToken?: string
    refreshToken?: string
    expiresAt?: number
    roles?: string[]
    locale?: string
    error?: 'RefreshAccessTokenError'
  }
}

const ZITADEL_ROLES_CLAIM = 'urn:zitadel:iam:org:project:roles'

function extractRoles(claim: unknown): string[] {
  // Zitadel emits the project-roles claim as
  //   { "<roleKey>": { "<orgId>": "<orgName>" }, ... }
  // We only need the role keys for our authorization checks.
  if (!claim || typeof claim !== 'object') return []
  return Object.keys(claim as Record<string, unknown>)
}

// Hard cap on the refresh-token round-trip. Without this the JWT
// callback can stall an entire page render if Zitadel is unreachable.
const REFRESH_TIMEOUT_MS = 5000

async function refreshAccessToken(token: JWT): Promise<JWT> {
  const issuer = process.env.AUTH_ZITADEL_ISSUER
  if (!issuer || !token.refreshToken) {
    return { ...token, error: 'RefreshAccessTokenError' }
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

export const { handlers, auth, signIn, signOut } = NextAuth({
  providers: [
    Zitadel({
      clientId: process.env.AUTH_ZITADEL_ID,
      clientSecret: process.env.AUTH_ZITADEL_SECRET,
      issuer: process.env.AUTH_ZITADEL_ISSUER,
      authorization: {
        params: {
          // offline_access → refresh token; the project:roles scope
          // makes Zitadel include the role claim in the id_token.
          scope: `openid profile email offline_access ${ZITADEL_ROLES_CLAIM}`,
        },
      },
    }),
  ],
  callbacks: {
    async jwt({ token, account, profile }) {
      // Initial sign-in: capture access/refresh/expiry and one-time
      // claims (roles + locale). Subsequent calls only have `token`.
      if (account) {
        token.accessToken = account.access_token
        token.refreshToken = account.refresh_token
        token.expiresAt = account.expires_at
      }
      if (profile) {
        token.roles = extractRoles(profile[ZITADEL_ROLES_CLAIM])
        if (typeof profile.locale === 'string') {
          token.locale = profile.locale
        }
      }
      // Still valid → return as-is.
      const expiresMs = (token.expiresAt ?? 0) * 1000
      if (Date.now() < expiresMs) {
        return token
      }
      // Expired → attempt refresh. If refresh fails we still return the
      // (now stale) token plus an error flag; downstream code can decide
      // whether to force a re-login.
      return refreshAccessToken(token)
    },
    async session({ session, token }) {
      session.accessToken = token.accessToken
      session.error = token.error
      if (session.user) {
        session.user.roles = token.roles ?? []
        session.user.locale = token.locale
      }
      return session
    },
  },
  session: { strategy: 'jwt' },
  // Plesk's Passenger sets Host correctly but trustHost defaults vary
  // by deploy target; force trust so Auth.js doesn't reject prod URLs.
  trustHost: true,
})
