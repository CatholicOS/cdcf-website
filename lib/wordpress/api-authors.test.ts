import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('./client', () => ({
  wpQuery: vi.fn(),
}))

import { wpQuery } from './client'
import { getAuthorByDerivedSlug, getPostsByAuthor } from './api'
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
