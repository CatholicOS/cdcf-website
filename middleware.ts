import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'

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

export function middleware(request: NextRequest) {
  const response = NextResponse.next()

  const host = request.nextUrl.hostname.toLowerCase()
  if (!PRODUCTION_HOSTS.has(host)) {
    response.headers.set('X-Robots-Tag', 'noindex, nofollow')
  }

  return response
}

// Apply to all routes except Next.js internals; we want robots.txt and
// sitemap responses to also carry the noindex header on staging.
export const config = {
  matcher: ['/((?!_next/static|_next/image).*)'],
}
