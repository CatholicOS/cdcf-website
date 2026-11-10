// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('next/image', () => ({
  default: ({ src, alt }: { src: string; alt: string }) => (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={src} alt={alt} />
  ),
}))

import GovernanceSection from './GovernanceSection'
import { renderDom } from '@/components/testing/renderDom'
import type { WPTeamMember } from '@/lib/wordpress/types'

let unmount = () => {}
afterEach(() => unmount())

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

describe('GovernanceSection bio anchors', () => {
  it('gives each bio card an id derived from the name', () => {
    const r = renderDom(
      <GovernanceSection
        members={[member('Tom Ciszek'), member('Fr. John R. D’Orazio')]}
      />
    )
    unmount = r.unmount
    expect(r.container.querySelector('#tom-ciszek')).not.toBeNull()
    expect(r.container.querySelector('#fr-john-r-dorazio')).not.toBeNull()
  })

  it('links the name to its own card', () => {
    const r = renderDom(<GovernanceSection members={[member('Tom Ciszek')]} />)
    unmount = r.unmount
    const link = r.container.querySelector('h3 a')
    expect(link?.getAttribute('href')).toBe('#tom-ciszek')
    expect(link?.textContent).toContain('Tom Ciszek')
  })

  it('falls back to a positional id when the name has no usable characters', () => {
    const r = renderDom(<GovernanceSection members={[member('—')]} />)
    unmount = r.unmount
    expect(r.container.querySelector('#member-1')).not.toBeNull()
  })
})
