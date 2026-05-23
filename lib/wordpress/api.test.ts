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

    expect(page.enUri).toBe('/about/')
    expect(page.modified).toBe('2026-05-01T00:00:00')
    expect(page.availableLocales.sort()).toEqual(['en', 'fr', 'it'])
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

    expect(page.enUri).toBe('/about/')
    expect(page.availableLocales.sort()).toEqual(['en', 'it'])
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
