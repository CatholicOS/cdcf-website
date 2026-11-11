// lib/wordpress/api-donate.test.ts
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('./client', () => ({ wpQuery: vi.fn() }))

import { getDonatableProjects } from './api'
import { wpQuery } from './client'

const mockedQuery = vi.mocked(wpQuery)

afterEach(() => {
  vi.resetAllMocks()
})

describe('getDonatableProjects', () => {
  it('reports the English slug and title, so one project is one bucket in Stripe', async () => {
    mockedQuery.mockResolvedValue({
      projects: {
        nodes: [
          {
            slug: 'familygraph-2',
            title: 'FamilyGraph (IT)',
            projectFields: { projectAcceptsDonations: false },
            translation: {
              slug: 'familygraph',
              title: 'FamilyGraph',
              projectFields: { projectAcceptsDonations: true },
            },
          },
        ],
      },
    })

    await expect(getDonatableProjects('it')).resolves.toEqual([
      {
        slug: 'familygraph-2',
        title: 'FamilyGraph (IT)',
        canonicalSlug: 'familygraph',
        canonicalTitle: 'FamilyGraph',
      },
    ])
  })

  it('takes the donation flag from the English post, not the translation', async () => {
    mockedQuery.mockResolvedValue({
      projects: {
        nodes: [
          {
            slug: 'progetto',
            title: 'Progetto',
            projectFields: { projectAcceptsDonations: true },
            translation: {
              slug: 'project',
              title: 'Project',
              projectFields: { projectAcceptsDonations: false },
            },
          },
        ],
      },
    })

    await expect(getDonatableProjects('it')).resolves.toEqual([])
  })

  it('returns only projects flagged as accepting donations', async () => {
    mockedQuery.mockResolvedValue({
      projects: {
        nodes: [
          {
            slug: 'bibleget',
            title: 'BibleGet',
            projectFields: { projectAcceptsDonations: true },
          },
          {
            slug: 'dormant',
            title: 'Dormant Project',
            projectFields: { projectAcceptsDonations: false },
          },
          {
            slug: 'unset',
            title: 'Unset Project',
            projectFields: null,
          },
        ],
      },
    })

    await expect(getDonatableProjects('it')).resolves.toEqual([
      {
        slug: 'bibleget',
        title: 'BibleGet',
        canonicalSlug: 'bibleget',
        canonicalTitle: 'BibleGet',
      },
    ])
  })

  it('maps the next-intl locale onto the Polylang language code', async () => {
    mockedQuery.mockResolvedValue({ projects: { nodes: [] } })

    await getDonatableProjects('pt')

    expect(mockedQuery).toHaveBeenCalledWith(expect.any(String), {
      language: 'PT',
    })
  })

  it('returns an empty list rather than throwing when WordPress is down', async () => {
    mockedQuery.mockRejectedValue(new Error('ECONNREFUSED'))

    await expect(getDonatableProjects('en')).resolves.toEqual([])
  })

  it('drops a node with no slug, which cannot be designated', async () => {
    mockedQuery.mockResolvedValue({
      projects: {
        nodes: [
          {
            slug: '',
            title: 'Broken',
            projectFields: { projectAcceptsDonations: true },
          },
        ],
      },
    })

    await expect(getDonatableProjects('en')).resolves.toEqual([])
  })
})
