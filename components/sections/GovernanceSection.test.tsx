// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest'

import GovernanceSection from './GovernanceSection'
import { renderDom } from '@/components/testing/renderDom'
import type { WPTeamMember } from '@/lib/wordpress/types'

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

  it('uses the anchors it is given', () => {
    const r = renderDom(
      <GovernanceSection
        members={[member('Tom Ciszek')]}
        anchors={['tom-ciszek-2']}
      />
    )
    unmount = r.unmount
    expect(r.container.querySelector('#tom-ciszek-2')).not.toBeNull()
    expect(r.container.querySelector('h3 a')?.getAttribute('href')).toBe(
      '#tom-ciszek-2'
    )
  })

  it('ignores an anchors array that does not match the members', () => {
    const r = renderDom(
      <GovernanceSection
        members={[member('Tom Ciszek'), member('Ann Lee')]}
        anchors={['tom-ciszek-2']}
      />
    )
    unmount = r.unmount
    const hrefs = [...r.container.querySelectorAll('h3 a')].map((a) =>
      a.getAttribute('href')
    )
    expect(hrefs).toEqual(['#tom-ciszek', '#ann-lee'])
  })

  it('falls back to a positional id when the name has no usable characters', () => {
    const r = renderDom(<GovernanceSection members={[member('—')]} />)
    unmount = r.unmount
    expect(r.container.querySelector('#member-1')).not.toBeNull()
  })
})
