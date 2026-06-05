import { redirect } from 'next/navigation'
import { auth } from '@/lib/auth'
import type { Session } from 'next-auth'

/**
 * Require an authenticated session. Returns the session if present,
 * redirects to the Auth.js sign-in route otherwise. Server-only.
 *
 * Use in Server Components or route handlers that should be inaccessible
 * to anonymous users — e.g. the upcoming /[lang]/my-bio page.
 */
export async function requireSession(): Promise<Session> {
  const session = await auth()
  if (!session?.user) {
    redirect('/api/auth/signin')
  }
  return session
}

/**
 * Require an authenticated session AND a Zitadel project role. Redirects
 * unauthenticated users to sign-in; returns the session if the role is
 * present, throws a 403-shaped error otherwise (caller catches or lets
 * the framework render the error boundary).
 */
export async function requireRole(role: string): Promise<Session> {
  const session = await requireSession()
  if (!session.user?.roles?.includes(role)) {
    // Surface as a thrown error so Next's error boundary handles it.
    // We deliberately don't redirect — an authenticated user without
    // a role shouldn't bounce back to sign-in.
    const err = new Error(`Forbidden: required role "${role}" not granted`)
    ;(err as Error & { status?: number }).status = 403
    throw err
  }
  return session
}

/**
 * Type-narrowed helper to read the access token off a session. Returns
 * undefined when there's no session or the token expired and the refresh
 * grant failed.
 */
export function getAccessToken(session: Session | null | undefined): string | undefined {
  if (!session) return undefined
  if (session.error === 'RefreshAccessTokenError') return undefined
  return session.accessToken
}
