import { describe, expect, it } from 'vitest'
import { slugify } from './slugify'

describe('slugify', () => {
  it('lowercases and hyphenates a name', () => {
    expect(slugify('Tom Ciszek')).toBe('tom-ciszek')
  })

  it('drops apostrophes, punctuation and diacritics', () => {
    expect(slugify('Fr. John R. D’Orazio')).toBe('fr-john-r-dorazio')
    expect(slugify('Fr. Corwin Low, O.P.')).toBe('fr-corwin-low-o-p')
    expect(slugify('José Manuel de Urquidi')).toBe('jose-manuel-de-urquidi')
  })

  it('returns an empty string when nothing usable remains', () => {
    expect(slugify('—')).toBe('')
  })
})
