import { wpQuery } from './client'
import {
  GET_ACADEMIC_COLLABORATION_BY_SLUG,
  GET_ALL_PAGES,
  GET_AUTHORS,
  GET_AUTHOR_BY_SLUG,
  GET_CHILD_PAGES,
  GET_PAGE_BY_ID,
  GET_PAGE_BY_SLUG,
  GET_POST_BY_ID,
  GET_POST_BY_SLUG,
  GET_POSTS,
  GET_POSTS_BY_AUTHOR,
  GET_TEAM_MEMBER_BY_ID,
  GET_POSTS_FOR_SITEMAP,
  GET_PROJECTS,
  GET_PROJECTS_FOR_SITEMAP,
  GET_ACADEMIC_COLLABORATIONS_FOR_SITEMAP,
  GET_PROJECT_BY_SLUG,
  GET_SPONSORS,
} from './queries'
import type {
  Nicename,
  WPAcademicCollaboration,
  WPAuthor,
  WPPage,
  WPPost,
  WPProject,
  WPSponsor,
  WPTeamMember,
} from './types'
import {
  deriveAuthorSlug,
  linkedTeamMemberId,
  resolveAuthorProfile,
  type AuthorProfile,
} from '../author-profile'

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

/**
 * Fetch a page by database id with draft auth, for preview rendering.
 * Returns the exact post being edited (no translation fallback) including
 * unpublished drafts. See lib/wordpress/preview.ts.
 */
export async function getPagePreview(id: number): Promise<WPPage | null> {
  try {
    const data = await wpQuery<{ page: WPPage | null }>(
      GET_PAGE_BY_ID,
      { id: String(id) },
      { draft: true }
    )
    return data.page ?? null
  } catch (error) {
    console.error('Failed to fetch page preview:', error)
    return null
  }
}

/**
 * Fetch a post by database id with draft auth, for preview rendering.
 * Returns the exact post being edited (no translation fallback) including
 * unpublished drafts. See lib/wordpress/preview.ts.
 */
export async function getPostPreview(id: number): Promise<WPPost | null> {
  try {
    const data = await wpQuery<{ post: WPPost | null }>(
      GET_POST_BY_ID,
      { id: String(id) },
      { draft: true }
    )
    return data.post ?? null
  } catch (error) {
    console.error('Failed to fetch post preview:', error)
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

export async function getPostsByAuthor(
  authorSlug: Nicename,
  locale: string,
  count: number = 50
): Promise<WPPost[]> {
  try {
    const data = await wpQuery<{ posts: { nodes: WPPost[] } }>(
      GET_POSTS_BY_AUTHOR,
      { authorName: authorSlug, language: langCode(locale), first: count }
    )
    return data.posts.nodes.filter((p) => !p.postSettings?.hideFromBlog)
  } catch (error) {
    console.error('Failed to fetch posts by author:', error)
    return []
  }
}

export async function getAuthorBySlug(slug: Nicename): Promise<WPAuthor | null> {
  try {
    const data = await wpQuery<{ user: WPAuthor | null }>(GET_AUTHOR_BY_SLUG, {
      slug,
    })
    return data.user ?? null
  } catch (error) {
    console.error('Failed to fetch author:', error)
    return null
  }
}

export async function getAuthors(): Promise<WPAuthor[]> {
  try {
    const data = await wpQuery<{ users: { nodes: WPAuthor[] } }>(GET_AUTHORS)
    return data.users.nodes
  } catch (error) {
    console.error('Failed to fetch authors:', error)
    return []
  }
}

/** Translated team_member behind an author, with English fallback. */
export async function getTeamMemberProfile(
  id: number,
  locale: string
): Promise<WPTeamMember | null> {
  try {
    const data = await wpQuery<{
      teamMember: { translation: WPTeamMember | null } | null
    }>(GET_TEAM_MEMBER_BY_ID, { id: String(id), language: langCode(locale) })

    const translated = data.teamMember?.translation ?? null
    if (translated) return translated

    if (locale !== 'en') {
      const fallback = await wpQuery<{
        teamMember: { translation: WPTeamMember | null } | null
      }>(GET_TEAM_MEMBER_BY_ID, { id: String(id), language: 'EN' })
      return fallback.teamMember?.translation ?? null
    }

    return null
  } catch (error) {
    console.error('Failed to fetch team member profile:', error)
    return null
  }
}

/**
 * Resolved, locale-aware author profile: the WP user merged with their linked
 * team_member (translated) when present. Returns null when the author does not
 * exist. Used by both the article about-the-author card and the author page.
 */
export async function getAuthorProfile(
  slug: Nicename,
  locale: string
): Promise<AuthorProfile | null> {
  const author = await getAuthorBySlug(slug)
  if (!author) return null

  const teamMemberId = linkedTeamMemberId(author)
  const teamMember = teamMemberId
    ? await getTeamMemberProfile(teamMemberId, locale)
    : null

  return resolveAuthorProfile(author, teamMember)
}

/**
 * Find an author by their *derived* (display-name) slug, used by the author
 * page. WPGraphQL can't look users up by a derived slug, so we match it against
 * the author list; the WP nicename is accepted as a fallback so any pre-existing
 * links still resolve. Returns the WPAuthor (callers need its nicename to fetch
 * the author's posts) or null when no author matches.
 */
export async function getAuthorByDerivedSlug(
  slug: string
): Promise<WPAuthor | null> {
  const authors = await getAuthors()
  return (
    authors.find((author) => deriveAuthorSlug(author) === slug) ??
    authors.find((author) => author.slug === slug) ??
    null
  )
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
  // Each translation's actual prefix-less URI, keyed by lowercase locale code.
  // We can't derive these from enUri because Polylang (free) doesn't share
  // slugs across languages — every non-EN translation gets a "-N" collision
  // suffix (EN /about, IT /about-2, ES /about-3, FR /about-4, ...). The
  // sitemap loc and the hreflang alternates need these real slugs; deriving
  // from enUri would emit URLs that don't match WP's canonical.
  uriByLocale: Map<string, string>
  modified: string
  availableLocales: string[]
}

interface RawAllPagesNode {
  slug: string
  uri: string
  modifiedGmt: string
  translations?: { language: { code: string }; uri: string }[]
}

// Strip a leading Polylang /<locale> language directory from a page URI.
// Polylang prefixes non-default-language URIs (e.g. "/pt/governanca/…"); the
// sitemap stores prefix-less paths and re-adds the locale prefix itself, so
// leaving it in produces a doubled "/pt/pt/…" entry.
function stripLocalePrefix(uri: string, locale: string): string {
  const prefix = `/${locale}`
  if (uri === prefix || uri === `${prefix}/`) return '/'
  return uri.startsWith(`${prefix}/`) ? uri.slice(prefix.length) : uri
}

// WordPress emits URIs with a trailing slash ("/about/"), but Next.js's server
// canonical (with trailingSlash defaulting to false) is "/about" — so emitting
// the WP form into the sitemap produces a 308 redirect on every fetch. Strip
// the trailing slash here; keep "/" intact for the home page.
function stripTrailingSlash(uri: string): string {
  return uri.length > 1 && uri.endsWith('/') ? uri.replace(/\/+$/, '') : uri
}

// WPGraphQL returns *GmtDate fields as "YYYY-MM-DDTHH:MM:SS" with NO timezone
// designator. The sitemap spec (W3C Datetime) requires a TZ marker whenever a
// time-of-day is present; without it, Google Search Console rejects the
// sitemap with "invalid date". The *Gmt variants are by definition UTC, so we
// append "Z". Idempotent: a value that already ends with Z is left alone.
function toLastmodUtc(gmt: string): string {
  return gmt.endsWith('Z') ? gmt : `${gmt}Z`
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
      // Prefer the English translation's URI (Polylang serves the default
      // locale without a prefix). When a page has no English translation, fall
      // back to its own URI with the Polylang /<locale> prefix stripped, so the
      // sitemap route doesn't prepend a second locale segment (/pt/pt/…).
      const enTranslationUri = node.translations?.find(
        (t) => t.language.code === 'EN'
      )?.uri
      const rawEnUri =
        locale === 'en'
          ? node.uri
          : enTranslationUri ?? stripLocalePrefix(node.uri, locale)
      const enUri = stripTrailingSlash(rawEnUri)
      // Build the per-locale URI map (prefix-LESS paths, ready to be fed to
      // buildUrl, which re-adds the locale prefix). Includes both this node's
      // own locale and every Polylang translation. Each entry preserves WP's
      // actual slug ("/about-2" for IT, "/about-3" for ES, …) so the sitemap
      // emits the URL Google will actually find indexed.
      const uriByLocale = new Map<string, string>()
      uriByLocale.set(locale, stripTrailingSlash(stripLocalePrefix(node.uri, locale)))
      for (const t of node.translations ?? []) {
        const tLocale = t.language.code.toLowerCase()
        uriByLocale.set(tLocale, stripTrailingSlash(stripLocalePrefix(t.uri, tLocale)))
      }
      const otherLocales = node.translations?.map((t) => t.language.code.toLowerCase()) ?? []
      const availableLocales = Array.from(new Set([locale, ...otherLocales]))
      return { enUri, uriByLocale, modified: toLastmodUtc(node.modifiedGmt), availableLocales }
    })
  } catch (error) {
    console.error('Failed to fetch all pages:', error)
    return []
  }
}

export interface WPSitemapPost {
  slug: string
  modified: string
  translations: { code: string; slug: string }[]
}

interface RawSitemapPost {
  slug: string
  dateGmt: string
  modifiedGmt?: string | null
  postSettings?: { hideFromBlog?: boolean | null } | null
  translations?: { language: { code: string }; slug: string }[]
}

export async function getPostsForSitemap(
  locale: string,
  options?: FetchOptions
): Promise<WPSitemapPost[]> {
  try {
    const data = await wpQuery<{
      posts: { nodes: RawSitemapPost[] }
    }>(GET_POSTS_FOR_SITEMAP, { language: langCode(locale) }, options)

    return data.posts.nodes
      .filter((p) => !p.postSettings?.hideFromBlog)
      .map((p) => ({
        slug: p.slug,
        modified: toLastmodUtc(p.modifiedGmt ?? p.dateGmt),
        translations: (p.translations ?? []).map((t) => ({
          code: t.language.code.toLowerCase(),
          slug: t.slug,
        })),
      }))
  } catch (error) {
    console.error('Failed to fetch posts for sitemap:', error)
    return []
  }
}

export interface WPSitemapProject {
  slug: string
  modified: string
  translations: { code: string; slug: string }[]
}

interface RawSitemapProject {
  slug: string
  dateGmt: string
  modifiedGmt?: string | null
  translations?: { language: { code: string }; slug: string }[]
}

export async function getProjectsForSitemap(
  locale: string,
  options?: FetchOptions
): Promise<WPSitemapProject[]> {
  try {
    const data = await wpQuery<{
      projects: { nodes: RawSitemapProject[] }
    }>(GET_PROJECTS_FOR_SITEMAP, { language: langCode(locale) }, options)

    return data.projects.nodes.map((p) => ({
      slug: p.slug,
      modified: toLastmodUtc(p.modifiedGmt ?? p.dateGmt),
      translations: (p.translations ?? []).map((t) => ({
        code: t.language.code.toLowerCase(),
        slug: t.slug,
      })),
    }))
  } catch (error) {
    console.error('Failed to fetch projects for sitemap:', error)
    return []
  }
}

// Academic collaborations share the slug/modified/translations sitemap shape
// with projects (WPSitemapProject); the sitemap route renders them under the
// /academic-collaborations/ path prefix instead of /projects/.
export async function getAcademicCollaborationsForSitemap(
  locale: string,
  options?: FetchOptions
): Promise<WPSitemapProject[]> {
  try {
    const data = await wpQuery<{
      academicCollaborations: { nodes: RawSitemapProject[] }
    }>(GET_ACADEMIC_COLLABORATIONS_FOR_SITEMAP, { language: langCode(locale) }, options)

    return data.academicCollaborations.nodes.map((c) => ({
      slug: c.slug,
      modified: toLastmodUtc(c.modifiedGmt ?? c.dateGmt),
      translations: (c.translations ?? []).map((t) => ({
        code: t.language.code.toLowerCase(),
        slug: t.slug,
      })),
    }))
  } catch (error) {
    console.error('Failed to fetch academic collaborations for sitemap:', error)
    return []
  }
}

export interface WPChildPage {
  title: string
  enSlug: string
  modified: string
}

interface RawChildPage {
  title: string
  slug: string
  modified: string
  translations?: { language: { code: string }; slug: string }[]
}

export async function getChildPages(
  parentDatabaseId: number,
  locale: string,
  options?: FetchOptions
): Promise<WPChildPage[]> {
  try {
    const data = await wpQuery<{
      pages: { nodes: RawChildPage[] }
    }>(GET_CHILD_PAGES, { parentId: parentDatabaseId, language: langCode(locale) }, options)

    return data.pages.nodes.map((node) => {
      const enSlug =
        locale === 'en'
          ? node.slug
          : node.translations?.find((t) => t.language.code === 'EN')?.slug ?? node.slug
      return { title: node.title, enSlug, modified: node.modified }
    })
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
