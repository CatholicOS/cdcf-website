import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('./client', () => ({
  wpQuery: vi.fn(),
}))

import { wpQuery } from './client'
import {
  getAcademicCollaboration,
  getAllPages,
  getChildPages,
  getPage,
  getPostBySlug,
  getPosts,
  getAcademicCollaborationsForSitemap,
  getPostsForSitemap,
  getProject,
  getProjects,
  getProjectsForSitemap,
  getSponsors,
} from './api'

const wpQueryMock = vi.mocked(wpQuery)

beforeEach(() => {
  wpQueryMock.mockReset()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('locale → language code mapping', () => {
  it.each([
    ['en', 'EN'],
    ['it', 'IT'],
    ['es', 'ES'],
    ['fr', 'FR'],
    ['pt', 'PT'],
    ['de', 'DE'],
  ])('passes %s as %s to GraphQL', async (locale, expectedCode) => {
    wpQueryMock.mockResolvedValueOnce({ page: { translation: null } })

    await getPage('about', locale)

    const variables = wpQueryMock.mock.calls[0][1] as { language: string }
    expect(variables.language).toBe(expectedCode)
  })

  it('falls back to EN for an unknown locale', async () => {
    wpQueryMock.mockResolvedValueOnce({ page: { translation: null } })

    await getPage('about', 'jp')

    const variables = wpQueryMock.mock.calls[0][1] as { language: string }
    expect(variables.language).toBe('EN')
  })

  it('passes the slug as a leading-slash URI, except for the root', async () => {
    wpQueryMock.mockResolvedValue({ page: { translation: null } })

    await getPage('about', 'en')
    expect((wpQueryMock.mock.calls[0][1] as { slug: string }).slug).toBe('/about')

    await getPage('/', 'en')
    expect((wpQueryMock.mock.calls[1][1] as { slug: string }).slug).toBe('/')
  })
})

describe('getPage', () => {
  it('returns the localised page when present', async () => {
    const page = { databaseId: 1, title: 'Chi siamo' }
    wpQueryMock.mockResolvedValueOnce({ page: { translation: page } })

    await expect(getPage('about', 'it')).resolves.toEqual(page)
    expect(wpQueryMock).toHaveBeenCalledOnce()
  })

  it('falls back to the EN page when the locale translation is missing', async () => {
    wpQueryMock
      .mockResolvedValueOnce({ page: { translation: null } })
      .mockResolvedValueOnce({ page: { translation: { databaseId: 2, title: 'About' } } })

    const result = await getPage('about', 'it')

    expect(result).toEqual({ databaseId: 2, title: 'About' })
    expect(wpQueryMock).toHaveBeenCalledTimes(2)
    expect((wpQueryMock.mock.calls[1][1] as { language: string }).language).toBe('EN')
  })

  it('does not double-fetch when locale is already EN and translation is null', async () => {
    wpQueryMock.mockResolvedValueOnce({ page: { translation: null } })

    await expect(getPage('about', 'en')).resolves.toBeNull()
    expect(wpQueryMock).toHaveBeenCalledOnce()
  })

  it('returns null and swallows the error if wpQuery throws', async () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('GraphQL down'))

    await expect(getPage('about', 'en')).resolves.toBeNull()
    expect(errorSpy).toHaveBeenCalled()
  })
})

describe('getPostBySlug EN-fallback', () => {
  it('returns the localised post when present and does not refetch', async () => {
    const post = { databaseId: 7, title: 'Post IT' }
    wpQueryMock.mockResolvedValueOnce({ post: { translation: post } })

    await expect(getPostBySlug('p', 'it')).resolves.toEqual(post)
    expect(wpQueryMock).toHaveBeenCalledOnce()
  })

  it('falls back to EN when the locale version is missing', async () => {
    wpQueryMock
      .mockResolvedValueOnce({ post: { translation: null } })
      .mockResolvedValueOnce({ post: { translation: { databaseId: 8, title: 'Post EN' } } })

    await expect(getPostBySlug('p', 'fr')).resolves.toEqual({
      databaseId: 8,
      title: 'Post EN',
    })
    expect(wpQueryMock).toHaveBeenCalledTimes(2)
  })
})

describe('getAllPages mapping', () => {
  it('builds enUri + availableLocales for a non-EN locale', async () => {
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            uri: '/it/chi-siamo/',
            modified: '2026-05-01T00:00:00',
            translations: [
              { language: { code: 'EN' }, uri: '/about/' },
              { language: { code: 'FR' }, uri: '/fr/a-propos/' },
            ],
          },
        ],
      },
    })

    const [page] = await getAllPages('it')

    expect(page.enUri).toBe('/about')
    expect(page.modified).toBe('2026-05-01T00:00:00')
    expect(page.availableLocales.sort()).toEqual(['en', 'fr', 'it'])
    expect(page.uriByLocale.get('it')).toBe('/chi-siamo')
    expect(page.uriByLocale.get('en')).toBe('/about')
    expect(page.uriByLocale.get('fr')).toBe('/a-propos')
  })

  it('keeps the node uri as enUri when locale is EN', async () => {
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            uri: '/about/',
            modified: '2026-05-01',
            translations: [{ language: { code: 'IT' }, uri: '/it/chi-siamo/' }],
          },
        ],
      },
    })

    const [page] = await getAllPages('en')

    expect(page.enUri).toBe('/about')
    expect(page.availableLocales.sort()).toEqual(['en', 'it'])
    expect(page.uriByLocale.get('en')).toBe('/about')
    expect(page.uriByLocale.get('it')).toBe('/chi-siamo')
  })

  it('strips the Polylang locale prefix when a page has no EN translation', async () => {
    // Regression for the /pt/pt/… doubled-prefix bug: a page with no English
    // translation must fall back to its own URI with the /pt prefix stripped,
    // so the sitemap route can add exactly one locale segment.
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            uri: '/pt/governanca/governanca-de-ia/',
            modified: '2026-05-01',
            translations: [{ language: { code: 'FR' }, uri: '/fr/gouvernance/gouvernance-de-lia/' }],
          },
        ],
      },
    })

    const [page] = await getAllPages('pt')

    expect(page.enUri).toBe('/governanca/governanca-de-ia')
    expect(page.availableLocales.sort()).toEqual(['fr', 'pt'])
    expect(page.uriByLocale.get('pt')).toBe('/governanca/governanca-de-ia')
    expect(page.uriByLocale.get('fr')).toBe('/gouvernance/gouvernance-de-lia')
  })

  it('preserves WP slug-collision suffixes per locale (real /about pattern)', async () => {
    // Polylang free can't share slugs across languages, so every non-EN
    // translation of /about lands as /<lang>/about-N. The sitemap must emit
    // those exact URLs in the hreflang alternates, not derive them from the
    // EN slug, or Google sees a sitemap claiming URLs that don't match WP's
    // canonical. Mirrors the live WP data for EN page id 5.
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            uri: '/about/',
            modified: '2026-02-28T14:11:37',
            translations: [
              { language: { code: 'IT' }, uri: '/it/about-2/' },
              { language: { code: 'ES' }, uri: '/es/about-3/' },
              { language: { code: 'FR' }, uri: '/fr/about-4/' },
              { language: { code: 'PT' }, uri: '/pt/about-5/' },
              { language: { code: 'DE' }, uri: '/de/about-6/' },
            ],
          },
        ],
      },
    })

    const [page] = await getAllPages('en')

    expect(page.uriByLocale.get('en')).toBe('/about')
    expect(page.uriByLocale.get('it')).toBe('/about-2')
    expect(page.uriByLocale.get('es')).toBe('/about-3')
    expect(page.uriByLocale.get('fr')).toBe('/about-4')
    expect(page.uriByLocale.get('pt')).toBe('/about-5')
    expect(page.uriByLocale.get('de')).toBe('/about-6')
  })

  it('passes through a URI that already has no trailing slash', async () => {
    // Belt-and-suspenders: WordPress in practice always emits trailing slashes
    // on page URIs, but the trailing-slash strip must be a no-op for already-
    // clean URIs (and for translations === undefined, a real case for an
    // English page with no other languages yet).
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            uri: '/about',
            modified: '2026-05-01',
          },
        ],
      },
    })

    const [page] = await getAllPages('en')

    expect(page.enUri).toBe('/about')
    expect(page.uriByLocale.get('en')).toBe('/about')
    expect(page.availableLocales).toEqual(['en'])
  })

  it('keeps the home URI as "/" (does not strip the lone slash)', async () => {
    // WordPress emits "/" for the front page; the trailing-slash strip must
    // not collapse it to "" or the sitemap will emit "https://…<empty>".
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            uri: '/',
            modified: '2026-05-01',
            translations: [{ language: { code: 'IT' }, uri: '/it/' }],
          },
        ],
      },
    })

    const [page] = await getAllPages('en')

    expect(page.enUri).toBe('/')
    expect(page.uriByLocale.get('en')).toBe('/')
    expect(page.uriByLocale.get('it')).toBe('/')
  })

  it('returns [] if wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getAllPages('en')).resolves.toEqual([])
  })
})

describe('getPostsForSitemap mapping', () => {
  it('filters posts with hideFromBlog=true', async () => {
    wpQueryMock.mockResolvedValueOnce({
      posts: {
        nodes: [
          {
            slug: 'visible',
            date: '2026-01-01',
            modified: '2026-04-01',
            postSettings: { hideFromBlog: false },
            translations: [],
          },
          {
            slug: 'hidden',
            date: '2026-01-02',
            modified: '2026-04-02',
            postSettings: { hideFromBlog: true },
            translations: [],
          },
        ],
      },
    })

    const result = await getPostsForSitemap('en')

    expect(result.map((p) => p.slug)).toEqual(['visible'])
  })

  it('falls back to date when modified is missing', async () => {
    wpQueryMock.mockResolvedValueOnce({
      posts: {
        nodes: [
          {
            slug: 'no-modified',
            date: '2026-01-15',
            modified: null,
            postSettings: null,
            translations: [],
          },
        ],
      },
    })

    const [post] = await getPostsForSitemap('en')
    expect(post.modified).toBe('2026-01-15')
  })

  it('lowercases translation language codes', async () => {
    wpQueryMock.mockResolvedValueOnce({
      posts: {
        nodes: [
          {
            slug: 'p',
            date: '2026-01-01',
            modified: '2026-01-01',
            postSettings: { hideFromBlog: false },
            translations: [
              { language: { code: 'IT' }, slug: 'p-it' },
              { language: { code: 'FR' }, slug: 'p-fr' },
            ],
          },
        ],
      },
    })

    const [post] = await getPostsForSitemap('en')
    expect(post.translations).toEqual([
      { code: 'it', slug: 'p-it' },
      { code: 'fr', slug: 'p-fr' },
    ])
  })

  it('returns [] if wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getPostsForSitemap('en')).resolves.toEqual([])
  })
})

describe('getProjectsForSitemap mapping', () => {
  it('maps modified, slug, and lowercased translation codes', async () => {
    wpQueryMock.mockResolvedValueOnce({
      projects: {
        nodes: [
          {
            slug: 'foo',
            date: '2026-02-01',
            modified: '2026-03-01',
            translations: [{ language: { code: 'ES' }, slug: 'foo-es' }],
          },
        ],
      },
    })

    const [project] = await getProjectsForSitemap('en')
    expect(project).toEqual({
      slug: 'foo',
      modified: '2026-03-01',
      translations: [{ code: 'es', slug: 'foo-es' }],
    })
  })

  it('returns [] if wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getProjectsForSitemap('en')).resolves.toEqual([])
  })
})

describe('getAcademicCollaborationsForSitemap mapping', () => {
  it('maps modified, slug, and lowercased translation codes', async () => {
    wpQueryMock.mockResolvedValueOnce({
      academicCollaborations: {
        nodes: [
          {
            slug: 'notre-dame',
            date: '2026-02-01',
            modified: '2026-03-01',
            translations: [{ language: { code: 'IT' }, slug: 'notre-dame-it' }],
          },
        ],
      },
    })

    const [collab] = await getAcademicCollaborationsForSitemap('en')
    expect(collab).toEqual({
      slug: 'notre-dame',
      modified: '2026-03-01',
      translations: [{ code: 'it', slug: 'notre-dame-it' }],
    })
  })

  it('falls back to date when modified is absent', async () => {
    wpQueryMock.mockResolvedValueOnce({
      academicCollaborations: {
        nodes: [{ slug: 'oxford', date: '2026-01-15', modified: null, translations: [] }],
      },
    })

    const [collab] = await getAcademicCollaborationsForSitemap('en')
    expect(collab.modified).toBe('2026-01-15')
  })

  it('returns [] if wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getAcademicCollaborationsForSitemap('en')).resolves.toEqual([])
  })
})

describe('getPostBySlug error + EN-locale paths', () => {
  it('returns null and swallows the error when wpQuery throws', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('GraphQL down'))

    await expect(getPostBySlug('p', 'en')).resolves.toBeNull()
  })

  it('does not refetch when locale is EN and translation is null', async () => {
    wpQueryMock.mockResolvedValueOnce({ post: { translation: null } })

    await expect(getPostBySlug('p', 'en')).resolves.toBeNull()
    expect(wpQueryMock).toHaveBeenCalledOnce()
  })
})

describe('getPosts', () => {
  it('passes the requested count and locale to GraphQL', async () => {
    wpQueryMock.mockResolvedValueOnce({ posts: { nodes: [] } })

    await getPosts('it', 7)

    const variables = wpQueryMock.mock.calls[0][1] as { language: string; first: number }
    expect(variables.language).toBe('IT')
    expect(variables.first).toBe(7)
  })

  it('filters posts with hideFromBlog=true', async () => {
    wpQueryMock.mockResolvedValueOnce({
      posts: {
        nodes: [
          { databaseId: 1, title: 'Visible',  postSettings: { hideFromBlog: false } },
          { databaseId: 2, title: 'Hidden',   postSettings: { hideFromBlog: true } },
          { databaseId: 3, title: 'No-flag',  postSettings: null },
        ],
      },
    })

    const posts = await getPosts('en')
    expect(posts.map((p) => p.title)).toEqual(['Visible', 'No-flag'])
  })

  it('returns [] when wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getPosts('en')).resolves.toEqual([])
  })
})

describe('getProjects', () => {
  it('returns the projects list from GraphQL', async () => {
    const projects = [{ databaseId: 10 }, { databaseId: 11 }]
    wpQueryMock.mockResolvedValueOnce({ projects: { nodes: projects } })

    await expect(getProjects('en')).resolves.toEqual(projects)
  })

  it('returns [] when wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getProjects('en')).resolves.toEqual([])
  })
})

describe('getProject', () => {
  it('returns the translated project when present', async () => {
    const project = { databaseId: 42, title: 'Progetto IT' }
    wpQueryMock.mockResolvedValueOnce({ project: { translation: project } })

    await expect(getProject('slug', 'it')).resolves.toEqual(project)
  })

  it('returns null when no translation exists (no EN fallback)', async () => {
    // Unlike getPage / getPostBySlug, getProject does NOT fall back to
    // the EN version — projects are expected to exist in every locale.
    wpQueryMock.mockResolvedValueOnce({ project: { translation: null } })

    await expect(getProject('slug', 'fr')).resolves.toBeNull()
    expect(wpQueryMock).toHaveBeenCalledOnce()
  })

  it('returns null when wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getProject('slug', 'en')).resolves.toBeNull()
  })
})

describe('getAcademicCollaboration', () => {
  it('returns the translated collaboration when present', async () => {
    const collab = { databaseId: 100, title: 'Catholic U' }
    wpQueryMock.mockResolvedValueOnce({ academicCollaboration: { translation: collab } })

    await expect(getAcademicCollaboration('slug', 'it')).resolves.toEqual(collab)
  })

  it('returns null when wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getAcademicCollaboration('slug', 'en')).resolves.toBeNull()
  })
})

describe('getSponsors', () => {
  it('returns the sponsors list from GraphQL', async () => {
    const sponsors = [{ databaseId: 50 }, { databaseId: 51 }]
    wpQueryMock.mockResolvedValueOnce({ sponsors: { nodes: sponsors } })

    await expect(getSponsors('en')).resolves.toEqual(sponsors)
  })

  it('returns [] when wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getSponsors('en')).resolves.toEqual([])
  })
})

describe('getChildPages error path', () => {
  it('returns [] when wpQuery rejects', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))

    await expect(getChildPages(123, 'en')).resolves.toEqual([])
  })
})

describe('getChildPages mapping', () => {
  it('uses node slug as enSlug when locale is EN', async () => {
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            title: 'Standards',
            slug: 'standards',
            modified: '2026-04-01',
            translations: [{ language: { code: 'IT' }, slug: 'standard' }],
          },
        ],
      },
    })

    const [page] = await getChildPages(123, 'en')
    expect(page.enSlug).toBe('standards')
    expect(page.title).toBe('Standards')
  })

  it('uses the EN translation slug when locale is not EN', async () => {
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            title: 'Standard',
            slug: 'standard',
            modified: '2026-04-01',
            translations: [{ language: { code: 'EN' }, slug: 'standards' }],
          },
        ],
      },
    })

    const [page] = await getChildPages(123, 'it')
    expect(page.enSlug).toBe('standards')
  })

  it('falls back to node slug when no EN translation exists', async () => {
    wpQueryMock.mockResolvedValueOnce({
      pages: {
        nodes: [
          {
            title: 'Standard',
            slug: 'standard',
            modified: '2026-04-01',
            translations: [],
          },
        ],
      },
    })

    const [page] = await getChildPages(123, 'it')
    expect(page.enSlug).toBe('standard')
  })
})
