import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { setRequestLocale } from 'next-intl/server'
import { getPage, getPostBySlug, getPosts, getProjects, getSponsors } from '@/lib/wordpress/api'
import PageRenderer from '@/components/sections/PageRenderer'

interface PageProps {
  params: Promise<{ lang: string; slug?: string[] }>
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { lang, slug } = await params
  const pageSlug = slug?.join('/') || '/'

  const page = await getPage(pageSlug, lang)
  if (page?.title) {
    return { title: page.title }
  }

  return {}
}

export default async function CatchAllPage({ params }: PageProps) {
  const { lang, slug } = await params
  setRequestLocale(lang)

  const pageSlug = slug?.join('/') || '/'

  const page = await getPage(pageSlug, lang)

  if (!page) {
    notFound()
  }

  const template = page.template?.templateName || 'Default'
  const isLogoSymbolism = slug?.at(-1) === 'logo-symbolism'

  // Fetch additional data based on template
  const [posts, projects, sponsors, fishExplanation] = await Promise.all([
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
  ])

  return (
    <PageRenderer
      page={page}
      posts={posts}
      projects={projects}
      sponsors={sponsors}
      isLogoSymbolism={isLogoSymbolism}
      fishExplanationHtml={fishExplanation?.content ?? undefined}
    />
  )
}
