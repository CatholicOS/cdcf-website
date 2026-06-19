import type { Metadata } from 'next'
import { notFound, permanentRedirect } from 'next/navigation'
import { setRequestLocale } from 'next-intl/server'
import { getPage, getPagePreview, getPostBySlug, getPosts, getProjects, getSponsors, getChildPages } from '@/lib/wordpress/api'
import { canonicalAbsoluteUrl, canonicalRedirectPath } from '@/lib/canonical'
import { getPreviewTarget, previewMatchesSlug } from '@/lib/wordpress/preview'
import PageRenderer from '@/components/sections/PageRenderer'

interface PageProps {
  params: Promise<{ lang: string; slug?: string[] }>
}

const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { lang, slug } = await params
  const pageSlug = slug?.join('/') || '/'

  const page = await getPage(pageSlug, lang)
  if (!page?.title) {
    return {}
  }

  return {
    title: page.title,
    alternates: {
      // Canonical = the page's real Polylang uri, NOT the requested URL.
      // WPGraphQL resolves a page by leaf slug regardless of language, so a
      // request like /it/about (or /it/governance-2/research) renders the IT
      // page whose true URL is /it/about-2 (or /it/governance-2/ricerca).
      // Pointing canonical at page.uri tells Google the one true URL; the
      // catch-all also 308-redirects non-canonical requests there.
      canonical: canonicalAbsoluteUrl(SITE_URL, page.uri),
    },
  }
}

export default async function CatchAllPage({ params }: PageProps) {
  const { lang, slug } = await params
  setRequestLocale(lang)

  const pageSlug = slug?.join('/') || '/'

  // In a preview session for a page/CPT (anything but a blog post), render the
  // draft by id; otherwise fall through to the normal published lookup.
  const preview = await getPreviewTarget()
  const usePreview =
    !!preview && preview.type !== 'post' && previewMatchesSlug(preview, pageSlug)

  const page = usePreview
    ? await getPagePreview(preview.id)
    : await getPage(pageSlug, lang)

  if (!page) {
    notFound()
  }

  // WPGraphQL resolves a page by leaf slug regardless of language, so a
  // cross-language URL (e.g. /it/about, /it/governance-2/research, or the
  // English-fallback /it/<en-only-slug>) renders content whose true URL is
  // page.uri. Those duplicates are what GSC flags as "duplicate, no
  // user-selected canonical". 308-redirect to the one canonical URL.
  // Skipped in preview (drafts have no public uri and are looked up by id).
  if (!usePreview) {
    const redirectTo = canonicalRedirectPath(lang, slug, page.uri)
    if (redirectTo) {
      permanentRedirect(redirectTo)
    }
  }

  const template = page.template?.templateName || 'Default'
  const isLogoSymbolism = slug?.at(-1) === 'logo-symbolism'

  // Fetch additional data based on template
  const [posts, projects, sponsors, fishExplanation, childPages] = await Promise.all([
    template === 'Blog' || template === 'Home'
      ? getPosts(lang, page.blogFields?.maxPosts || 6)
      : Promise.resolve([]),
    template === 'Projects'
      ? getProjects(lang)
      : Promise.resolve([]),
    template === 'Home'
      ? getSponsors(lang)
      : Promise.resolve([]),
    isLogoSymbolism
      ? getPostBySlug('symbolism-of-24', lang)
      : Promise.resolve(null),
    template === 'Governance TOC'
      ? getChildPages(page.databaseId, lang)
      : Promise.resolve([]),
  ])

  return (
    <PageRenderer
      page={page}
      posts={posts}
      projects={projects}
      sponsors={sponsors}
      isLogoSymbolism={isLogoSymbolism}
      fishExplanationHtml={fishExplanation?.content ?? undefined}
      childPages={childPages}
      parentPath={pageSlug}
    />
  )
}
