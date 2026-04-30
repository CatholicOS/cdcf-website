import createMiddleware from 'next-intl/middleware'
import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'
import { routing } from './src/i18n/routing'

// Hosts that ARE allowed to be indexed by search engines. Anything else
// (staging, preview deploys, raw IPs, one-off subdomains) gets a
// noindex/nofollow X-Robots-Tag so it can't leak into search results.
//
// Primary source is NEXT_PUBLIC_SITE_URL (kept in lockstep with the value
// used by metadataBase in app/[lang]/layout.tsx). The hardcoded fallback
// guarantees production stays indexable if NEXT_PUBLIC_SITE_URL is unset
// or malformed at build time.
const FALLBACK_PRODUCTION_HOSTS = ['catholicdigitalcommons.org']

function buildProductionHosts(): Set<string> {
  const hosts = new Set<string>()

  const siteUrl = process.env.NEXT_PUBLIC_SITE_URL
  if (siteUrl) {
    try {
      hosts.add(new URL(siteUrl).hostname.toLowerCase())
    } catch {
      // Malformed URL — fall through to the fallback list.
    }
  }

  if (hosts.size === 0) {
    for (const host of FALLBACK_PRODUCTION_HOSTS) hosts.add(host)
  }

  // Always treat the bare and www. variants as production-equivalent so a
  // visitor reaching either URL gets the same indexing policy regardless of
  // which form NEXT_PUBLIC_SITE_URL was configured with.
  for (const host of [...hosts]) {
    if (host.startsWith('www.')) {
      hosts.add(host.slice(4))
    } else {
      hosts.add(`www.${host}`)
    }
  }

  return hosts
}

const PRODUCTION_HOSTS = buildProductionHosts()
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
  const host = request.nextUrl.hostname.toLowerCase()
  if (!PRODUCTION_HOSTS.has(host)) {
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
