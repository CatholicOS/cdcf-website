import { describe, expect, it } from 'vitest'
import { normalizeRedirectLocation } from './normalize-redirect'

describe('normalizeRedirectLocation', () => {
  it('strips the leaked standalone bind host AND port for a public host', () => {
    // The exact production/staging failure mode: next-intl emits the Next
    // standalone bind origin (0.0.0.0:3000) as the redirect authority.
    expect(
      normalizeRedirectLocation(
        'https://0.0.0.0:3000/it',
        'staging.catholicdigitalcommons.org'
      )
    ).toBe('https://staging.catholicdigitalcommons.org/it')
  })

  it('strips a port even when the host already matches (the URL.host-setter bug)', () => {
    // Regression guard: an earlier revision used `url.host = hostname`,
    // which leaves an existing port intact. Here hostname already matches,
    // so only the port must be cleared.
    expect(
      normalizeRedirectLocation(
        'https://catholicdigitalcommons.org:3000/de',
        'catholicdigitalcommons.org'
      )
    ).toBe('https://catholicdigitalcommons.org/de')
  })

  it('preserves the path and query while fixing the authority', () => {
    expect(
      normalizeRedirectLocation(
        'https://0.0.0.0:3000/fr/blog?page=2',
        'catholicdigitalcommons.org'
      )
    ).toBe('https://catholicdigitalcommons.org/fr/blog?page=2')
  })

  it('keeps the genuine port when rewriting to a local-dev loopback host', () => {
    expect(
      normalizeRedirectLocation('http://0.0.0.0:3000/it', 'localhost:3000')
    ).toBe('http://localhost:3000/it')
    expect(
      normalizeRedirectLocation('http://0.0.0.0:3000/it', '127.0.0.1:3000')
    ).toBe('http://127.0.0.1:3000/it')
  })

  it('handles bracketed IPv6 loopback Host headers without stripping the port', () => {
    // [::1]:3000 must be recognized as loopback (a naive split(':') would
    // mangle it and wrongly strip the dev port).
    expect(
      normalizeRedirectLocation('http://[::1]:3000/it', '[::1]:3000')
    ).toBeNull()
    expect(
      normalizeRedirectLocation('http://0.0.0.0:3000/it', '[::1]:3000')
    ).toBe('http://[::1]:3000/it')
  })

  it('returns null when the location already matches the public host', () => {
    expect(
      normalizeRedirectLocation(
        'https://catholicdigitalcommons.org/it',
        'catholicdigitalcommons.org'
      )
    ).toBeNull()
  })

  it('returns null for missing or non-absolute locations', () => {
    expect(normalizeRedirectLocation(null, 'catholicdigitalcommons.org')).toBeNull()
    expect(normalizeRedirectLocation(undefined, 'catholicdigitalcommons.org')).toBeNull()
    expect(normalizeRedirectLocation('', 'catholicdigitalcommons.org')).toBeNull()
    expect(normalizeRedirectLocation('/it', 'catholicdigitalcommons.org')).toBeNull()
  })

  it('returns null when the Host header is empty', () => {
    expect(normalizeRedirectLocation('https://0.0.0.0:3000/it', '')).toBeNull()
  })
})
