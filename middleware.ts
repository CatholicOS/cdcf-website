import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'

// Hosts that ARE allowed to be indexed by search engines. Anything else
// (staging, preview deploys, raw IPs, one-off subdomains) gets a
// noindex/nofollow X-Robots-Tag so it can't leak into search results.
const PRODUCTION_HOSTS = new Set([
  'catholicdigitalcommons.org',
  'www.catholicdigitalcommons.org',
])

export function middleware(request: NextRequest) {
  const response = NextResponse.next()

  const host = (request.headers.get('host') ?? '').toLowerCase().split(':')[0]
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
