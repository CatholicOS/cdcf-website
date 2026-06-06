import { cookies } from 'next/headers'
import { type NextRequest, NextResponse } from 'next/server'
import { getToken } from 'next-auth/jwt'

/**
 * RP-initiated logout for the Zitadel session.
 *
 * Auth.js's signOut() only clears the local session cookie. The upstream
 * Zitadel SSO session survives, so the next "Sign in" silently reuses it
 * and the user can't switch accounts. This route does both: clear the
 * Auth.js cookies AND redirect the browser to Zitadel's RP-initiated
 * logout endpoint, which kills the upstream session and bounces back to
 * post_logout_redirect_uri (registered per env in setup-zitadel.sh).
 *
 * See cdcf-infra/auth/handoffs/cdcf-website.md for the registered
 * post-logout URIs per Zitadel client.
 */
export async function GET(req: NextRequest): Promise<NextResponse> {
  // Whether Auth.js v5 used Secure-prefixed cookie names. The cookie
  // naming convention is tied to whether the deployment is HTTPS — same
  // signal Auth.js uses internally — so derive from AUTH_URL rather than
  // a separate flag.
  const isSecure = (process.env.AUTH_URL ?? '').startsWith('https://')

  // Pull the id_token from the JWT BEFORE we delete the cookie. getToken
  // reads from the request cookies (an immutable snapshot at request
  // start), so deleting from the response cookie store first wouldn't
  // affect it — but ordering this way keeps the intent clear.
  const token = await getToken({
    req,
    secret: process.env.AUTH_SECRET,
    secureCookie: isSecure,
  })

  // Clear the Auth.js v5 session cookies. We touch both the prefixed and
  // unprefixed names so a misconfigured env (e.g. HTTPS deploy that
  // forgot to set AUTH_URL) still gets cleaned up, never stranding the
  // user logged-in-locally-but-logged-out-of-Zitadel.
  const store = await cookies()
  for (const name of [
    'authjs.session-token',
    '__Secure-authjs.session-token',
    'authjs.csrf-token',
    '__Host-authjs.csrf-token',
    'authjs.callback-url',
    '__Secure-authjs.callback-url',
  ]) {
    store.delete(name)
  }

  const issuer = process.env.AUTH_ZITADEL_ISSUER
  // Without an issuer there's nothing to redirect to for the upstream
  // session termination — fall back to the local sign-in page so the
  // user at least lands somewhere sensible. The local session is gone
  // either way.
  if (!issuer) {
    return NextResponse.redirect(new URL('/', req.url))
  }

  const endSession = new URL('/oidc/v1/end_session', issuer)

  // id_token_hint is REQUIRED by Zitadel to skip the logout-confirmation
  // prompt and to reliably terminate the right session. If we don't have
  // one (cookie expired before the user clicked sign-out, etc.) the
  // request still works but Zitadel may render a confirmation page.
  const idToken = token?.idToken
  if (typeof idToken === 'string' && idToken !== '') {
    endSession.searchParams.set('id_token_hint', idToken)
  }
  const clientId = process.env.AUTH_ZITADEL_ID
  if (typeof clientId === 'string' && clientId !== '') {
    endSession.searchParams.set('client_id', clientId)
  }
  // post_logout_redirect_uri must be one of the URIs registered on the
  // Zitadel OIDC app for this client_id (setup-zitadel.sh registers the
  // public origin per env). AUTH_URL matches that by construction.
  const postLogout = process.env.AUTH_URL
  if (typeof postLogout === 'string' && postLogout !== '') {
    endSession.searchParams.set('post_logout_redirect_uri', postLogout)
  }

  return NextResponse.redirect(endSession.toString())
}
