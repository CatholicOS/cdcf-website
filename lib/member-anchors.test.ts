import { describe, expect, it } from 'vitest'
import { allocateMemberAnchors } from './member-anchors'

/** Members with the given names. */
const m = (...titles: string[]) => titles.map((title) => ({ title }))

describe('allocateMemberAnchors', () => {
  it('uses the name slug, or a positional fallback', () => {
    expect(allocateMemberAnchors(m('Tom Ciszek', '—'))).toEqual([
      'tom-ciszek',
      'member-2',
    ])
  })

  it('suffixes a slug already taken within the list', () => {
    expect(allocateMemberAnchors(m('Ann Lee', 'Ann Lee', 'Ann Lee'))).toEqual([
      'ann-lee',
      'ann-lee-2',
      'ann-lee-3',
    ])
  })

  it('keeps ids unique across sections sharing one registry', () => {
    const used = new Set<string>()
    const board = allocateMemberAnchors(m('Ann Lee', '—'), used)
    const council = allocateMemberAnchors(m('Ann Lee', '—'), used)
    expect(board).toEqual(['ann-lee', 'member-2'])
    expect(council).toEqual(['ann-lee-2', 'member-2-2'])
  })

  it('checks each suffixed candidate against every allocated id', () => {
    const used = new Set(['ann-lee', 'ann-lee-2'])
    expect(allocateMemberAnchors(m('Ann Lee'), used)).toEqual(['ann-lee-3'])
  })
})
