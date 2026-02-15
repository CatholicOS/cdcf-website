import { wpQuery } from './client'
import {
  GET_PAGE_BY_SLUG,
  GET_POSTS,
  GET_PROJECTS,
  GET_PROJECT_BY_SLUG,
  GET_SPONSORS,
} from './queries'
import type { WPPage, WPPost, WPProject, WPSponsor } from './types'

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

    return data.page?.translation ?? null
  } catch (error) {
    console.error('Failed to fetch page:', error)
    return null
  }
}

export async function getPosts(
  locale: string,
  count: number = 10
): Promise<WPPost[]> {
  try {
    const data = await wpQuery<{
      posts: { nodes: WPPost[] }
    }>(GET_POSTS, { language: langCode(locale), first: count })

    return data.posts.nodes
  } catch (error) {
    console.error('Failed to fetch posts:', error)
    return []
  }
}

export async function getProjects(locale: string): Promise<WPProject[]> {
  try {
    const data = await wpQuery<{
      projects: { nodes: WPProject[] }
    }>(GET_PROJECTS, { language: langCode(locale) })

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
