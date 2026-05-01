import { wpQuery } from './client'
import {
  GET_ACADEMIC_COLLABORATION_BY_SLUG,
  GET_ALL_PAGES,
  GET_CHILD_PAGES,
  GET_PAGE_BY_SLUG,
  GET_POST_BY_SLUG,
  GET_POSTS,
  GET_PROJECTS,
  GET_PROJECT_BY_SLUG,
  GET_SPONSORS,
} from './queries'
import type { WPAcademicCollaboration, WPPage, WPPost, WPProject, WPSponsor } from './types'

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
): Promise<WPPost | null> {
  try {
    const data = await wpQuery<{
      post: { translation: WPPost | null } | null
    }>(GET_POST_BY_SLUG, { slug, language: langCode(locale) })

    const translated = data.post?.translation ?? null
    if (translated) return translated

    if (locale !== 'en') {
      const fallback = await wpQuery<{
        post: { translation: WPPost | null } | null
      }>(GET_POST_BY_SLUG, { slug, language: 'EN' })
      return fallback.post?.translation ?? null
    }

    return null
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

    return data.posts.nodes.filter(p => !p.postSettings?.hideFromBlog)
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


export async function getAcademicCollaboration(
  slug: string,
  locale: string
): Promise<WPAcademicCollaboration | null> {
  try {
    const data = await wpQuery<{
      academicCollaboration: { translation: WPAcademicCollaboration | null } | null
    }>(GET_ACADEMIC_COLLABORATION_BY_SLUG, { slug, language: langCode(locale) })

    return data.academicCollaboration?.translation ?? null
  } catch (error) {
    console.error('Failed to fetch academic collaboration:', error)
    return null
  }
}

export interface WPSitemapPage {
  enUri: string
  modified: string
  availableLocales: string[]
}

interface RawAllPagesNode {
  slug: string
  uri: string
  modified: string
  translations?: { language: { code: string }; uri: string }[]
}

export async function getAllPages(
  locale: string,
  options?: FetchOptions
): Promise<WPSitemapPage[]> {
  try {
    const data = await wpQuery<{
      pages: { nodes: RawAllPagesNode[] }
    }>(GET_ALL_PAGES, { language: langCode(locale) }, options)

    return data.pages.nodes.map((node) => {
      const enUri =
        locale === 'en'
          ? node.uri
          : node.translations?.find((t) => t.language.code === 'EN')?.uri ?? node.uri
      const otherLocales = node.translations?.map((t) => t.language.code.toLowerCase()) ?? []
      const availableLocales = Array.from(new Set([locale, ...otherLocales]))
      return { enUri, modified: node.modified, availableLocales }
    })
  } catch (error) {
    console.error('Failed to fetch all pages:', error)
    return []
  }
}

export interface WPChildPage {
  title: string
  slug: string
  uri: string
  modified: string
}

export async function getChildPages(
  parentDatabaseId: number,
  locale: string,
  options?: FetchOptions
): Promise<WPChildPage[]> {
  try {
    const data = await wpQuery<{
      pages: { nodes: WPChildPage[] }
    }>(GET_CHILD_PAGES, { parentId: parentDatabaseId, language: langCode(locale) }, options)

    return data.pages.nodes
  } catch (error) {
    console.error('Failed to fetch child pages:', error)
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
