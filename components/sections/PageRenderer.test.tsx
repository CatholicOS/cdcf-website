// @vitest-environment happy-dom
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('next-intl/server', () => ({
  getTranslations: vi.fn(async () => (key: string) => key),
}))
// Only the Home template reads stats; stub it so the module loads offline.
vi.mock('@/lib/stats', () => ({ getStats: vi.fn(async () => []) }))
// Other templates' sections import the locale-aware Link, which needs Next.
vi.mock('@/src/i18n/navigation', () => ({
  Link: ({ href, children }: { href: string; children: ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}))

import PageRenderer from './PageRenderer'
import { renderDom } from '@/components/testing/renderDom'
import type { WPPage, WPTeamMember } from '@/lib/wordpress/types'

let unmount = () => {}
afterEach(() => unmount())

/** A team member with only a name, for anchor tests. */
function member(title: string): WPTeamMember {
  return {
    title,
    content: null,
    featuredImage: null,
    teamMemberFields: {
      memberRole: null,
      memberTitle: null,
      memberLinkedinUrl: null,
      memberGithubUrl: null,
    },
  }
}

/** An About page whose three bio sections list the given members. */
function aboutPage(
  board: WPTeamMember[],
  ecclesial: WPTeamMember[],
  technical: WPTeamMember[]
): WPPage {
  return {
    title: 'About',
    content: null,
    template: { templateName: 'About' },
    aboutFields: {
      teamMembers: { nodes: board },
      ecclesialCouncil: { nodes: ecclesial },
      technicalCouncil: { nodes: technical },
      governanceColumns: null,
    },
  } as unknown as WPPage
}

describe('PageRenderer About page bio anchors', () => {
  it('gives the same name a distinct anchor in every section, each linked to its own card', async () => {
    const page = aboutPage(
      [member('Ann Lee')],
      [member('Ann Lee')],
      [member('Ann Lee'), member('Tom Ciszek')]
    )
    const r = renderDom(await PageRenderer({ page }))
    unmount = r.unmount

    const sectionIds = [
      'board-of-directors',
      'ecclesial-advisory-council',
      'technical-advisory-council',
    ]
    const cards = sectionIds.map((id) =>
      [...r.container.querySelectorAll(`section#${id} .grid > [id]`)].map(
        (card) => ({
          id: card.id,
          href: card.querySelector('h3 a')?.getAttribute('href'),
        })
      )
    )

    expect(cards).toEqual([
      [{ id: 'ann-lee', href: '#ann-lee' }],
      [{ id: 'ann-lee-2', href: '#ann-lee-2' }],
      [
        { id: 'ann-lee-3', href: '#ann-lee-3' },
        { id: 'tom-ciszek', href: '#tom-ciszek' },
      ],
    ])

    const allIds = [...r.container.querySelectorAll('[id]')].map((e) => e.id)
    expect(new Set(allIds).size).toBe(allIds.length)
  })
})
