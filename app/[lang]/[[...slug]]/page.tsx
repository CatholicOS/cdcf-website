import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { setRequestLocale } from 'next-intl/server'
import { getPage, getPagePreview, getPostBySlug, getPosts, getProjects, getSponsors, getChildPages } from '@/lib/wordpress/api'
import { getPreviewTarget, previewMatchesSlug } from '@/lib/wordpress/preview'
import PageRenderer from '@/components/sections/PageRenderer'

interface PageProps {
  params: Promise<{ lang: string; slug?: string[] }>
}

const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

/** Locale-aware absolute URL (default locale has no prefix). */
function absoluteUrl(lang: string, path: string): string {
  return `${SITE_URL}/${lang === 'en' ? '' : `${lang}/`}${path}`
}

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
      // Self-referencing canonical so locale variants aren't treated as
      // duplicates without a user-selected canonical (GSC indexing fix).
      canonical: absoluteUrl(lang, slug?.join('/') ?? ''),
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
