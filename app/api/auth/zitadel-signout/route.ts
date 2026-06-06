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
 * Exposed only on POST: a sign-out endpoint mutates state, so it must
 * not be triggerable via `<img>` or other cross-origin GET vectors.
 * Auth.js v5's built-in /api/auth/signout enforces POST for the same
 * reason. The success path returns 303 See Other so the browser follows
 * the upstream Zitadel URL with GET (the verb /oidc/v1/end_session
 * expects), without replaying the POST.
 *
 * See cdcf-infra/auth/handoffs/cdcf-website.md for the registered
 * post-logout URIs per Zitadel client.
 */
export async function POST(req: NextRequest): Promise<NextResponse> {
  // Mirror lib/auth.ts's runtime AUTH_URL determination: AUTH_URL takes
  // precedence, else NEXT_PUBLIC_SITE_URL (inlined at build time into
  // server code by Next's DefinePlugin). Doing this independently rather
  // than reading process.env.AUTH_URL alone covers a cold-start where
  // this route handler runs BEFORE lib/auth.ts's top-level `process.env.
  // AUTH_URL = ...` fallback has executed — otherwise isSecure would be
  // false on HTTPS and we'd look up the cookie by the wrong (unprefixed)
  // name, failing to read token.idToken and skipping id_token_hint.
  const siteUrl =
    process.env.AUTH_URL ?? process.env.NEXT_PUBLIC_SITE_URL ?? ''
  const isSecure = siteUrl.startsWith('https://')

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
  // either way. Use 303 here too so the browser follows with GET.
  if (!issuer) {
    return NextResponse.redirect(new URL('/', req.url), 303)
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
  // public origin per env). Use the same fallback chain as siteUrl above
  // so misconfigured deploys still produce a registered URI rather than
  // silently dropping the param and bouncing to Zitadel's default.
  if (siteUrl !== '') {
    endSession.searchParams.set('post_logout_redirect_uri', siteUrl)
  }

  // 303 — the incoming method was POST; the browser must follow with GET
  // because /oidc/v1/end_session is GET-only and we don't want to replay
  // the cookie-clearing POST against Zitadel.
  return NextResponse.redirect(endSession.toString(), 303)
}
