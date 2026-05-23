import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('./client', () => ({
  wpQuery: vi.fn(),
}))

import { wpQuery } from './client'
import {
  getAuthorByDerivedSlug,
  getAuthorBySlug,
  getAuthorProfile,
  getAuthors,
  getPagePreview,
  getPostPreview,
  getPostsByAuthor,
  getTeamMemberProfile,
} from './api'
import type { Nicename, WPAuthor } from './types'

const wpQueryMock = vi.mocked(wpQuery)

function author(name: string, slug: string): WPAuthor {
  return {
    name,
    nickname: null,
    firstName: null,
    lastName: null,
    slug: slug as Nicename,
    description: null,
    url: null,
    avatar: null,
    authorProfile: null,
  }
}

beforeEach(() => {
  wpQueryMock.mockReset()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('getAuthorByDerivedSlug', () => {
  const authors = [
    author('John Doe', 'jdoe_1'),
    author('Jane Roe', 'jroe_2'),
  ]

  function mockAuthors(list = authors) {
    wpQueryMock.mockResolvedValueOnce({ users: { nodes: list } })
  }

  it('matches on the derived slug', async () => {
    mockAuthors()
    const found = await getAuthorByDerivedSlug('john-doe')
    expect(found?.slug).toBe('jdoe_1')
  })

  it('falls back to the WP nicename so pre-existing links still resolve', async () => {
    mockAuthors()
    const found = await getAuthorByDerivedSlug('jroe_2')
    expect(found?.name).toBe('Jane Roe')
  })

  it('returns null when nothing matches', async () => {
    mockAuthors()
    expect(await getAuthorByDerivedSlug('nobody')).toBeNull()
  })

  it('returns the first author on a derived-slug collision', async () => {
    mockAuthors([
      author('John Doe', 'jdoe_1'),
      author('John Doe', 'jdoe_dup'),
    ])
    const found = await getAuthorByDerivedSlug('john-doe')
    expect(found?.slug).toBe('jdoe_1')
  })

  it('returns null when the author list is empty (e.g. fetch error)', async () => {
    wpQueryMock.mockResolvedValueOnce({ users: { nodes: [] } })
    expect(await getAuthorByDerivedSlug('john-doe')).toBeNull()
  })
})

describe('getPostsByAuthor', () => {
  it('filters out posts flagged hideFromBlog', async () => {
    wpQueryMock.mockResolvedValueOnce({
      posts: {
        nodes: [
          { slug: 'visible', postSettings: { hideFromBlog: false } },
          { slug: 'hidden', postSettings: { hideFromBlog: true } },
          { slug: 'no-settings', postSettings: null },
        ],
      },
    })
    const posts = await getPostsByAuthor('jdoe_1' as Nicename, 'en')
    expect(posts.map((p) => p.slug)).toEqual(['visible', 'no-settings'])
  })

  it('returns [] on error', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    wpQueryMock.mockRejectedValueOnce(new Error('boom'))
    expect(await getPostsByAuthor('jdoe_1' as Nicename, 'en')).toEqual([])
  })
})

describe('getAuthorBySlug', () => {
  it('returns the user node', async () => {
    wpQueryMock.mockResolvedValueOnce({ user: author('Jane', 'jane_1') })
    expect((await getAuthorBySlug('jane_1' as Nicename))?.name).toBe('Jane')
  })

  it('returns null on error', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    wpQueryMock.mockRejectedValueOnce(new Error('x'))
    expect(await getAuthorBySlug('jane_1' as Nicename)).toBeNull()
  })
})

describe('getAuthors', () => {
  it('returns the users list', async () => {
    wpQueryMock.mockResolvedValueOnce({ users: { nodes: [author('A', 'a_1')] } })
    expect(await getAuthors()).toHaveLength(1)
  })

  it('returns [] on error', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    wpQueryMock.mockRejectedValueOnce(new Error('x'))
    expect(await getAuthors()).toEqual([])
  })
})

describe('getTeamMemberProfile', () => {
  const tm = { title: 'TM', content: '<p>bio</p>' }

  it('returns the localized translation without an EN fallback query', async () => {
    wpQueryMock.mockResolvedValueOnce({ teamMember: { translation: tm } })
    const result = await getTeamMemberProfile(7, 'it')
    expect(result).toEqual(tm)
    expect(wpQueryMock).toHaveBeenCalledTimes(1)
  })

  it('falls back to EN when the locale translation is null', async () => {
    wpQueryMock
      .mockResolvedValueOnce({ teamMember: { translation: null } })
      .mockResolvedValueOnce({ teamMember: { translation: tm } })
    const result = await getTeamMemberProfile(7, 'it')
    expect(result).toEqual(tm)
    expect(wpQueryMock).toHaveBeenCalledTimes(2)
    expect((wpQueryMock.mock.calls[1][1] as { language: string }).language).toBe('EN')
  })

  it('does not issue an EN fallback for the en locale', async () => {
    wpQueryMock.mockResolvedValueOnce({ teamMember: { translation: null } })
    expect(await getTeamMemberProfile(7, 'en')).toBeNull()
    expect(wpQueryMock).toHaveBeenCalledTimes(1)
  })
})

describe('getAuthorProfile', () => {
  it('returns null (no team_member fetch) when the author is missing', async () => {
    wpQueryMock.mockResolvedValueOnce({ user: null })
    expect(await getAuthorProfile('ghost' as Nicename, 'en')).toBeNull()
    expect(wpQueryMock).toHaveBeenCalledTimes(1)
  })

  it('resolves a profile and fetches the linked team_member', async () => {
    const linked: WPAuthor = {
      ...author('Jane Doe', 'jane_1'),
      authorProfile: { authorTeamMember: { nodes: [{ databaseId: 9 }] } },
    }
    wpQueryMock
      .mockResolvedValueOnce({ user: linked })
      .mockResolvedValueOnce({
        teamMember: {
          translation: {
            title: 'TM',
            content: '<p>Bio</p>',
            featuredImage: null,
            teamMemberFields: { memberTitle: 'AI Specialist' },
          },
        },
      })
    const profile = await getAuthorProfile('jane_1' as Nicename, 'en')
    expect(profile?.slug).toBe('jane-doe')
    expect(profile?.title).toBe('AI Specialist')
    expect(wpQueryMock).toHaveBeenCalledTimes(2)
  })
})

describe('preview fetchers', () => {
  it('getPostPreview fetches by id with draft auth', async () => {
    wpQueryMock.mockResolvedValueOnce({ post: { databaseId: 5, title: 'Draft' } })
    const post = await getPostPreview(5)
    expect(post).toEqual({ databaseId: 5, title: 'Draft' })
    expect(wpQueryMock.mock.calls[0][1]).toEqual({ id: '5' })
    expect(wpQueryMock.mock.calls[0][2]).toEqual({ draft: true })
  })

  it('getPagePreview returns null on error', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    wpQueryMock.mockRejectedValueOnce(new Error('x'))
    expect(await getPagePreview(5)).toBeNull()
  })
})
