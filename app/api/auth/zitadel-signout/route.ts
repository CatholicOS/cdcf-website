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

  // CSRF defence — state-changing POST must originate from our own
  // origin. SameSite=Lax on the Auth.js session cookies already keeps
  // them from being sent on a cross-site POST (so the cookie-deletion
  // path below would no-op), but a malicious cross-site POST would
  // still bounce the user through Zitadel's end_session confirmation
  // page on the response redirect — annoying, plus we shouldn't rely
  // on browser cookie behaviour to make a state-mutating endpoint
  // safe. Modern browsers send Origin on every POST (cross-origin and
  // same-origin); Referer is the fallback for the rare client that
  // strips it. If both are missing or neither matches our expected
  // origin, refuse to mutate state. Auth.js v5's built-in
  // /api/auth/signout uses a CSRF token cookie for the same purpose;
  // we get equivalent protection from same-origin enforcement with
  // strictly less state to wire through the client side.
  const expectedOrigin = (() => {
    if (siteUrl === '') return ''
    try {
      const u = new URL(siteUrl)
      return `${u.protocol}//${u.host}`
    } catch {
      return ''
    }
  })()
  if (expectedOrigin === '') {
    // Can't determine our own origin — refuse to mutate state. A
    // visible 403 here surfaces the misconfiguration; the alternative
    // (skipping the CSRF check on a broken deploy) silently accepts
    // cross-site sign-outs.
    return new NextResponse('Forbidden', { status: 403 })
  }
  const requestOriginMatches = (header: string | null): boolean => {
    if (header === null || header === '') return false
    try {
      const u = new URL(header)
      return `${u.protocol}//${u.host}` === expectedOrigin
    } catch {
      return false
    }
  }
  const origin = req.headers.get('origin')
  const referer = req.headers.get('referer')
  if (!requestOriginMatches(origin) && !requestOriginMatches(referer)) {
    return new NextResponse('Forbidden', { status: 403 })
  }

  // Pull the id_token from the JWT BEFORE we delete the cookie. getToken
  // reads from the request cookies (an immutable snapshot at request
  // start), so deleting from the response cookie store first wouldn't
  // affect it — but ordering this way keeps the intent clear.
  const token = await getToken({
    req,
    secret: process.env.AUTH_SECRET,
    secureCookie: isSecure,
  })

  // Clear EVERY Auth.js v5 cookie the browser sent. We enumerate
  // req.cookies rather than hard-coding base names because Auth.js
  // CHUNKS large session JWTs across `.0` / `.1` / `.2` suffixed
  // cookies (the JWE grows past Zitadel's id_token + the project:roles
  // claim + refresh_token, easily exceeding the ~4KB per-cookie limit)
  // — a hard-coded `__Secure-authjs.session-token` delete would
  // silently miss the chunks and leave the user effectively still
  // signed in. Observed on staging where the JWE was split into
  // `.0` (~4KB) + `.1` (~700B) cookies.
  //
  // We also explicitly set Max-Age=0 with the prefix-appropriate flags
  // (Secure for `__Secure-` / `__Host-`, no Domain for `__Host-`)
  // because browsers IGNORE deletion responses that don't match the
  // prefix semantics — Next's cookies().delete() default flags are
  // wrong for prefixed cookies and the cookies survive the response.
  const AUTHJS_PREFIXES = ['authjs.', '__Secure-authjs.', '__Host-authjs.']
  const store = await cookies()
  for (const c of req.cookies.getAll()) {
    if (!AUTHJS_PREFIXES.some((p) => c.name.startsWith(p))) continue
    const isHostPrefix = c.name.startsWith('__Host-')
    const isPrefixSecure = isHostPrefix || c.name.startsWith('__Secure-')
    store.set(c.name, '', {
      maxAge: 0,
      path: '/',
      httpOnly: true,
      // __Host- and __Secure- prefixes REQUIRE Secure on both set and
      // delete; for unprefixed names fall back to whatever isSecure
      // says (matches lib/auth.ts's HTTPS detection).
      secure: isPrefixSecure || isSecure,
      sameSite: 'lax',
      // __Host- cookies must NOT have a Domain attribute — leaving it
      // unset preserves that constraint. For non-Host names, also
      // leaving Domain unset is fine: cookies set without Domain are
      // scoped to the exact host the browser sees, which matches how
      // Auth.js writes them in the first place.
    })
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
