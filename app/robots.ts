import type { MetadataRoute } from 'next'

const PRODUCTION_HOST = 'catholicdigitalcommons.org'

// Use `||` (not `??`) so an env var defined-but-empty in CI — e.g. a
// PR-build workflow that doesn't set NEXT_PUBLIC_SITE_URL — falls
// through to the production default instead of feeding '' into
// `new URL()`, which throws ERR_INVALID_URL.
const baseUrl =
  process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

export default function robots(): MetadataRoute.Robots {
  // Only the production apex domain should be crawlable. Any other host
  // (e.g. staging.catholicdigitalcommons.org) disallows all so it isn't
  // indexed or allowed to compete with production in search results.
  const isProduction = new URL(baseUrl).host === PRODUCTION_HOST

  if (!isProduction) {
    return {
      rules: {
        userAgent: '*',
        disallow: '/',
      },
    }
  }

  return {
    rules: {
      userAgent: '*',
      allow: '/',
    },
    sitemap: `${baseUrl}/sitemap.xml`,
  }
}
