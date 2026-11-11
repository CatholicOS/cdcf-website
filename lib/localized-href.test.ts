import { describe, expect, it } from 'vitest'

import { localizeHref } from './localized-href'

const paths = {
  '/community': '/community-2',
  '/governance/project-governance': '/governance-2/governanza-del-progetto',
}

describe('localizeHref', () => {
  it('swaps an English page path for its translation', () => {
    expect(localizeHref('/community', paths)).toBe('/community-2')
  })

  it('keeps the fragment', () => {
    expect(localizeHref('/community#local-groups', paths)).toBe(
      '/community-2#local-groups'
    )
  })

  it('maps nested pages by their full path', () => {
    expect(localizeHref('/governance/project-governance', paths)).toBe(
      '/governance-2/governanza-del-progetto'
    )
  })

  it('leaves unmapped paths, the home page, anchors and external links alone', () => {
    expect(localizeHref('/blog/authors', paths)).toBe('/blog/authors')
    expect(localizeHref('/', paths)).toBe('/')
    expect(localizeHref('#', paths)).toBe('#')
    expect(localizeHref('https://github.com/CatholicOS', paths)).toBe(
      'https://github.com/CatholicOS'
    )
  })
})
