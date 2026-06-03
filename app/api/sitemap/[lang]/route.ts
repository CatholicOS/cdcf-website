import { NextRequest } from 'next/server'
import { locales, defaultLocale } from '@/src/i18n/routing'
import {
  getAllPages,
  getPostsForSitemap,
  getProjectsForSitemap,
  getAcademicCollaborationsForSitemap,
  getAuthors,
} from '@/lib/wordpress/api'
import { deriveAuthorSlug } from '@/lib/author-profile'

export const revalidate = 3600

const baseUrl =
  process.env.NEXT_PUBLIC_SITE_URL ?? 'https://catholicdigitalcommons.org'

function localePrefix(locale: string): string {
  return locale === defaultLocale ? '' : `/${locale}`
}

function buildUrl(locale: string, path: string): string {
  const prefix = localePrefix(locale)
  if (path === '/' || path === '') return `${baseUrl}${prefix || '/'}`
  const cleanPath = path.startsWith('/') ? path : `/${path}`
  return `${baseUrl}${prefix}${cleanPath}`
}

function buildAlternateLinks(
  path: string,
  alternateLocales: readonly string[] = locales
): string {
  return alternateLocales
    .map(
      (locale) =>
        `      <xhtml:link rel="alternate" hreflang="${locale}" href="${buildUrl(locale, path)}" />`
    )
    .join('\n')
}

function buildAlternateLinksByLocale(pathByLocale: Map<string, string>): string {
  const lines: string[] = []
  for (const locale of locales) {
    const path = pathByLocale.get(locale)
    if (path === undefined) continue
    lines.push(
      `      <xhtml:link rel="alternate" hreflang="${locale}" href="${buildUrl(locale, path)}" />`
    )
  }
  return lines.join('\n')
}

function urlEntry(
  loc: string,
  lastmod: string,
  changefreq: string,
  priority: number,
  path: string,
  alternateLocales?: readonly string[]
): string {
  return `  <url>
    <loc>${loc}</loc>
    <lastmod>${lastmod}</lastmod>
    <changefreq>${changefreq}</changefreq>
    <priority>${priority.toFixed(1)}</priority>
${buildAlternateLinks(path, alternateLocales)}
  </url>`
}

function urlEntryByLocale(
  loc: string,
  lastmod: string,
  changefreq: string,
  priority: number,
  pathByLocale: Map<string, string>
): string {
  return `  <url>
    <loc>${loc}</loc>
    <lastmod>${lastmod}</lastmod>
    <changefreq>${changefreq}</changefreq>
    <priority>${priority.toFixed(1)}</priority>
${buildAlternateLinksByLocale(pathByLocale)}
  </url>`
}

export async function GET(
  _request: NextRequest,
  { params }: { params: Promise<{ lang: string }> }
) {
  const { lang } = await params

  if (!locales.includes(lang as (typeof locales)[number])) {
    return new Response('Not Found', { status: 404 })
  }

  const [pages, posts, projects, collaborations, authors] = await Promise.all([
    getAllPages(lang, { tags: ['sitemap'] }),
    getPostsForSitemap(lang, { tags: ['sitemap'] }),
    getProjectsForSitemap(lang, { tags: ['sitemap'] }),
    getAcademicCollaborationsForSitemap(lang, { tags: ['sitemap'] }),
    // Authors are language-independent (the public slug is derived from the
    // display name, identical across locales), so this isn't fetched per-lang.
    getAuthors(),
  ])

  const entries: string[] = []

  for (const page of pages) {
    // Polylang free can't share slugs across languages: every non-EN page has
    // an auto-collision suffix ("/about-2" IT, "/about-3" ES, ...). Use the
    // map of real per-locale URIs so loc and hreflang alternates both point at
    // WP's canonical URL for each language, not a derived /<lang>+<en-slug>.
    const ownPath = page.uriByLocale.get(lang) ?? page.enUri
    const isHome = page.enUri === '/'
    entries.push(
      urlEntryByLocale(
        buildUrl(lang, ownPath),
        page.modified,
        'weekly',
        isHome ? 1.0 : 0.8,
        page.uriByLocale
      )
    )
  }

  const knownLocales = new Set<string>(locales)

  for (const post of posts) {
    const ownPath = `/blog/${post.slug}`
    const pathByLocale = new Map<string, string>([[lang, ownPath]])
    for (const t of post.translations) {
      if (knownLocales.has(t.code)) {
        pathByLocale.set(t.code, `/blog/${t.slug}`)
      }
    }
    entries.push(
      urlEntryByLocale(
        buildUrl(lang, ownPath),
        post.modified,
        'daily',
        0.6,
        pathByLocale
      )
    )
  }

  for (const project of projects) {
    const ownPath = `/projects/${project.slug}`
    const pathByLocale = new Map<string, string>([[lang, ownPath]])
    for (const t of project.translations) {
      if (knownLocales.has(t.code)) {
        pathByLocale.set(t.code, `/projects/${t.slug}`)
      }
    }
    entries.push(
      urlEntryByLocale(
        buildUrl(lang, ownPath),
        project.modified,
        'weekly',
        0.6,
        pathByLocale
      )
    )
  }

  for (const collaboration of collaborations) {
    const ownPath = `/academic-collaborations/${collaboration.slug}`
    const pathByLocale = new Map<string, string>([[lang, ownPath]])
    for (const t of collaboration.translations) {
      if (knownLocales.has(t.code)) {
        pathByLocale.set(t.code, `/academic-collaborations/${t.slug}`)
      }
    }
    entries.push(
      urlEntryByLocale(
        buildUrl(lang, ownPath),
        collaboration.modified,
        'weekly',
        0.6,
        pathByLocale
      )
    )
  }

  // Blog authors: the index page plus one page per author with published
  // posts. The derived author slug is identical across locales, so each page
  // exists at the same path in every locale (default hreflang alternates).
  // Authors carry no per-entry modified date, so use the request time.
  const authorsLastmod = new Date().toISOString()
  entries.push(
    urlEntry(buildUrl(lang, '/blog/authors'), authorsLastmod, 'weekly', 0.4, '/blog/authors')
  )
  for (const author of authors) {
    const path = `/blog/authors/${deriveAuthorSlug(author)}`
    entries.push(
      urlEntry(buildUrl(lang, path), authorsLastmod, 'monthly', 0.3, path)
    )
  }

  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
${entries.join('\n')}
</urlset>`

  return new Response(xml, {
    headers: {
      'Content-Type': 'application/xml',
      'Cache-Control': 'public, s-maxage=3600, stale-while-revalidate=600',
    },
  })
}
