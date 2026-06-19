import { describe, expect, it } from 'vitest'

import { canonicalAbsoluteUrl, canonicalRedirectPath } from './canonical'

describe('canonicalRedirectPath', () => {
  it('returns null when the default-locale home is already canonical', () => {
    expect(canonicalRedirectPath('en', undefined, '/')).toBeNull()
  })

  it('returns null when a non-default-locale home is already canonical', () => {
    expect(canonicalRedirectPath('it', undefined, '/it/')).toBeNull()
  })

  it('returns null when a deep localized path matches the page uri', () => {
    expect(
      canonicalRedirectPath(
        'it',
        ['governance-2', 'governanza-del-progetto'],
        '/it/governance-2/governanza-del-progetto/'
      )
    ).toBeNull()
  })

  it('redirects a wrong-slug request to the real localized uri', () => {
    // /it/about resolves to the IT about page whose real slug is about-2.
    expect(canonicalRedirectPath('it', ['about'], '/it/about-2/')).toBe(
      '/it/about-2'
    )
  })

  it('redirects a cross-language leaf-slug request to the localized uri', () => {
    // /it/governance-2/research serves IT "ricerca" content.
    expect(
      canonicalRedirectPath(
        'it',
        ['governance-2', 'research'],
        '/it/governance-2/ricerca/'
      )
    ).toBe('/it/governance-2/ricerca')
  })

  it('redirects an English-fallback request to the English canonical', () => {
    // /it/some-page with no IT translation falls back to EN content,
    // whose uri carries no locale prefix.
    expect(canonicalRedirectPath('it', ['some-page'], '/some-page/')).toBe(
      '/some-page'
    )
  })

  it('returns null when the page uri is missing', () => {
    expect(canonicalRedirectPath('it', ['about'], null)).toBeNull()
    expect(canonicalRedirectPath('it', ['about'], undefined)).toBeNull()
  })
})

describe('canonicalAbsoluteUrl', () => {
  it('builds an absolute url from a localized page uri', () => {
    expect(
      canonicalAbsoluteUrl(
        'https://catholicdigitalcommons.org',
        '/it/about-2/'
      )
    ).toBe('https://catholicdigitalcommons.org/it/about-2')
  })

  it('builds the home url from a root uri', () => {
    expect(
      canonicalAbsoluteUrl('https://catholicdigitalcommons.org', '/')
    ).toBe('https://catholicdigitalcommons.org/')
  })

  it('does not double the slash when siteUrl has a trailing slash', () => {
    expect(
      canonicalAbsoluteUrl('https://catholicdigitalcommons.org/', '/it/about-2/')
    ).toBe('https://catholicdigitalcommons.org/it/about-2')
  })
})
