import { wpQuery } from './client'
import {
  GET_ALL_PAGES,
  GET_PAGE_BY_SLUG,
  GET_POST_BY_SLUG,
  GET_POSTS,
  GET_PROJECTS,
  GET_PROJECT_BY_SLUG,
  GET_SPONSORS,
} from './queries'
import type { WPPage, WPPost, WPProject, WPSponsor } from './types'

interface FetchOptions {
  tags?: string[]
}

// Map next-intl locale codes to Polylang language codes
const LOCALE_MAP: Record<string, string> = {
  en: 'EN',
  it: 'IT',
  es: 'ES',
  fr: 'FR',
  pt: 'PT',
  de: 'DE',
}

function langCode(locale: string): string {
  return LOCALE_MAP[locale] || 'EN'
}

export async function getPage(
  slug: string,
  locale: string
): Promise<WPPage | null> {
  try {
    const uri = slug === '/' ? '/' : `/${slug}`

    const data = await wpQuery<{
      page: { translation: WPPage | null } | null
    }>(GET_PAGE_BY_SLUG, { slug: uri, language: langCode(locale) })

    // Fall back to the English version if no translation exists
    const translated = data.page?.translation ?? null
    if (translated) return translated

    if (locale !== 'en') {
      const fallback = await wpQuery<{
        page: { translation: WPPage | null } | null
      }>(GET_PAGE_BY_SLUG, { slug: uri, language: 'EN' })
      return fallback.page?.translation ?? null
    }

    return null
  } catch (error) {
    console.error('Failed to fetch page:', error)
    return null
  }
}

export async function getPostBySlug(
  slug: string,
  locale: string
): Promise<{ title: string; content: string } | null> {
  try {
    const data = await wpQuery<{
      post: { translation: { title: string; slug: string; content: string } | null } | null
    }>(GET_POST_BY_SLUG, { slug, language: langCode(locale) })

    return data.post?.translation ?? null
  } catch (error) {
    console.error('Failed to fetch post:', error)
    return null
  }
}

export async function getPosts(
  locale: string,
  count: number = 10,
  options?: FetchOptions
): Promise<WPPost[]> {
  try {
    const data = await wpQuery<{
      posts: { nodes: WPPost[] }
    }>(GET_POSTS, { language: langCode(locale), first: count }, options)

    return data.posts.nodes
  } catch (error) {
    console.error('Failed to fetch posts:', error)
    return []
  }
}

export async function getProjects(
  locale: string,
  options?: FetchOptions
): Promise<WPProject[]> {
  try {
    const data = await wpQuery<{
      projects: { nodes: WPProject[] }
    }>(GET_PROJECTS, { language: langCode(locale) }, options)

    return data.projects.nodes
  } catch (error) {
    console.error('Failed to fetch projects:', error)
    return []
  }
}

export async function getProject(
  slug: string,
  locale: string
): Promise<WPProject | null> {
  try {
    const data = await wpQuery<{
      project: { translation: WPProject | null } | null
    }>(GET_PROJECT_BY_SLUG, { slug, language: langCode(locale) })

    return data.project?.translation ?? null
  } catch (error) {
    console.error('Failed to fetch project:', error)
    return null
  }
}

export async function getAllPages(
  locale: string,
  options?: FetchOptions
): Promise<{ slug: string; uri: string; modified: string }[]> {
  try {
    const data = await wpQuery<{
      pages: { nodes: { slug: string; uri: string; modified: string }[] }
    }>(GET_ALL_PAGES, { language: langCode(locale) }, options)

    return data.pages.nodes
  } catch (error) {
    console.error('Failed to fetch all pages:', error)
    return []
  }
}

export async function getSponsors(locale: string): Promise<WPSponsor[]> {
  try {
    const data = await wpQuery<{
      sponsors: { nodes: WPSponsor[] }
    }>(GET_SPONSORS, { language: langCode(locale) })

    return data.sponsors.nodes
  } catch (error) {
    console.error('Failed to fetch sponsors:', error)
    return []
  }
}
