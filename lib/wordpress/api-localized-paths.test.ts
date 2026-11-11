// lib/wordpress/api-localized-paths.test.ts
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('./client', () => ({ wpQuery: vi.fn() }))

import { getLocalizedPagePaths } from './api'
import { wpQuery } from './client'

const mockedQuery = vi.mocked(wpQuery)

afterEach(() => {
  vi.resetAllMocks()
})

describe('getLocalizedPagePaths', () => {
  it("maps each English page path to its translation's path, without the locale prefix", async () => {
    mockedQuery.mockResolvedValue({
      pages: {
        nodes: [
          { uri: '/community/', translation: { uri: '/it/community-2/' } },
          { uri: '/donate/', translation: { uri: '/it/dona/' } },
          {
            uri: '/governance/project-governance/',
            translation: { uri: '/it/governance-2/project-governance-2/' },
          },
        ],
      },
    })

    await expect(getLocalizedPagePaths('it')).resolves.toEqual({
      '/community': '/community-2',
      '/donate': '/dona',
      '/governance/project-governance': '/governance-2/project-governance-2',
    })
    expect(mockedQuery).toHaveBeenCalledWith(expect.any(String), {
      language: 'IT',
    })
  })

  it('leaves out pages with no translation, the same path, or the home page', async () => {
    mockedQuery.mockResolvedValue({
      pages: {
        nodes: [
          { uri: '/bylaws/', translation: null },
          { uri: '/about/', translation: { uri: '/it/about/' } },
          // The front page is served at /it, whatever its slug.
          { uri: '/', translation: { uri: '/it/home-italiano/' } },
        ],
      },
    })

    await expect(getLocalizedPagePaths('it')).resolves.toEqual({})
  })

  it('needs no lookup for English, whose links are already canonical', async () => {
    await expect(getLocalizedPagePaths('en')).resolves.toEqual({})
    expect(mockedQuery).not.toHaveBeenCalled()
  })

  it('falls back to no mapping when WordPress is unreachable', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    mockedQuery.mockRejectedValue(new Error('down'))

    await expect(getLocalizedPagePaths('it')).resolves.toEqual({})
  })
})
