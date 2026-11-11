import { afterEach, describe, expect, it, vi } from 'vitest'

import { portalSessionParams } from './portal-session'

afterEach(() => vi.unstubAllEnvs())

describe('portalSessionParams', () => {
  it('lets an expired emailed link fall back to Stripe customer login', () => {
    // Portal session URLs are short-lived; the email is read days later.
    expect(portalSessionParams('cus_1', 'en').after_expiration).toEqual({
      type: 'customer_login',
    })
  })

  it("opens the portal in the donor's language", () => {
    expect(portalSessionParams('cus_1', 'pt').locale).toBe('pt')
  })

  it('falls back to automatic language detection for anything else', () => {
    expect(portalSessionParams('cus_1', 'ja').locale).toBe('auto')
  })

  it('returns the donor to the site', () => {
    vi.stubEnv('NEXT_PUBLIC_SITE_URL', 'https://example.org')
    expect(portalSessionParams('cus_1', 'en')).toMatchObject({
      customer: 'cus_1',
      return_url: 'https://example.org',
    })
  })
})
