import { locales } from '@/src/i18n/routing'

const baseUrl =
  process.env.NEXT_PUBLIC_SITE_URL ?? 'https://staging.catholicdigitalcommons.org'

export async function GET() {
  const sitemaps = locales
    .map(
      (lang) =>
        `  <sitemap>\n    <loc>${baseUrl}/sitemap-${lang}.xml</loc>\n  </sitemap>`
    )
    .join('\n')

  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${sitemaps}
</sitemapindex>`

  return new Response(xml, {
    headers: {
      'Content-Type': 'application/xml',
      'Cache-Control': 'public, s-maxage=3600, stale-while-revalidate=600',
    },
  })
}
