import createMiddleware from 'next-intl/middleware'
import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'
import { routing } from './src/i18n/routing'

// Hosts that ARE allowed to be indexed by search engines. Anything else
// (staging, preview deploys, raw IPs, one-off subdomains) gets a
// noindex/nofollow X-Robots-Tag so it can't leak into search results.
//
// Hardcoded — NOT derived from NEXT_PUBLIC_SITE_URL. The two are
// different concepts:
//   - NEXT_PUBLIC_SITE_URL = "what URL THIS deployment thinks it is"
//     (different per environment: prod vs staging)
//   - INDEXABLE_HOSTS      = "what URLs search engines should index"
//     (always the production hostname, regardless of where the
//     build is running)
// Mixing the two means the staging build adds itself to the
// indexable list — the opposite of intent.
//
// If the canonical hostname ever changes, update this list. There is
// no runtime override on purpose; environment-derived behavior here
// would re-introduce the bug above.
const INDEXABLE_HOSTS = new Set<string>([
  'catholicdigitalcommons.org',
  'www.catholicdigitalcommons.org',
])
const intlProxy = createMiddleware(routing)

// Paths excluded from next-intl locale routing. Mirrors the original
// proxy.ts matcher exclusions, hoisted so we can branch on it in code
// (we want noindex on a wider set of paths than locale routing).
//
// Each path-segment literal must be followed by `/` or end-of-string so
// `/apiary`, `/wp-jsonary`, etc. are NOT excluded. Filename literals
// must match exactly (anchor to end). The trailing `.*\..*` catches any
// remaining asset-like path that contains a dot.
const INTL_EXCLUDE = /^\/(?:(?:api|_next|wp-admin|wp-login|wp-json|graphql|wp-content)(?:\/|$)|favicon\.ico$|logo\.svg$|.*\..*)/

export function proxy(request: NextRequest) {
  // Run next-intl only for routes it owns; pass-through otherwise.
  const response = INTL_EXCLUDE.test(request.nextUrl.pathname)
    ? NextResponse.next()
    : intlProxy(request)

  // Apply noindex on any non-production host (staging, preview deploys,
  // raw IPs). Applied after intl middleware so robots.txt and sitemap on
  // staging also carry the header.
  //
  // We read the Host header rather than request.nextUrl.hostname because
  // Next.js runs with `trustHostHeader: false` behind Plesk's reverse
  // proxy; nextUrl.hostname falls back to the bind address (localhost)
  // and would noindex production. The Host header is what nginx actually
  // forwards via `proxy_set_header Host $host` and is the only reliable
  // source of the incoming hostname in this topology.
  const host = (request.headers.get('host') ?? '').toLowerCase().split(':')[0]
  if (host && !INDEXABLE_HOSTS.has(host)) {
    response.headers.set('X-Robots-Tag', 'noindex, nofollow')
  }

  return response
}

// Broad matcher so the noindex header reaches API routes, robots.txt and
// sitemap responses on staging too. Only Next.js internal static assets
// are excluded.
export const config = {
  matcher: ['/((?!_next/static|_next/image).*)'],
}
