import { describe, expect, it } from 'vitest'

import { projectDonateHref } from './project-link'

const donatable = [{ slug: 'bibleget-2' }, { slug: 'ontokit-2' }]

describe('projectDonateHref', () => {
  it('links to the Donate page with the project preselected', () => {
    expect(projectDonateHref('bibleget-2', donatable, '/dona')).toBe(
      '/dona?project=bibleget-2'
    )
  })

  it('is null for a project that does not accept donations', () => {
    expect(projectDonateHref('familygraph-2', donatable, '/dona')).toBeNull()
  })

  it('encodes the slug', () => {
    expect(projectDonateHref('a b', [{ slug: 'a b' }], '/donate')).toBe(
      '/donate?project=a%20b'
    )
  })
})
