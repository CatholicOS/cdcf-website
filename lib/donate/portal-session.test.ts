import { afterEach, describe, expect, it, vi } from 'vitest'

import { createPortalSession, portalSessionParams } from './portal-session'

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

describe('createPortalSession', () => {
  it('uses the login fallback when Stripe accepts it', async () => {
    const create = vi
      .fn()
      .mockResolvedValue({ url: 'https://billing.stripe.com/p/s/1' })

    await expect(createPortalSession(create, 'cus_1', 'it')).resolves.toBe(
      'https://billing.stripe.com/p/s/1'
    )
    expect(create).toHaveBeenCalledTimes(1)
  })

  it('retries without it when customer login is not enabled in the Dashboard', async () => {
    // Stripe: "Enable customer login on the selected customer portal
    // configuration to use session recovery."
    const create = vi
      .fn()
      .mockRejectedValueOnce(new Error('Enable customer login …'))
      .mockResolvedValueOnce({ url: 'https://billing.stripe.com/p/s/2' })

    await expect(createPortalSession(create, 'cus_1', 'it')).resolves.toBe(
      'https://billing.stripe.com/p/s/2'
    )
    expect(create.mock.calls[1][0].after_expiration).toBeUndefined()
    expect(create.mock.calls[1][0].locale).toBe('it')
  })

  it('gives up when the plain session fails too', async () => {
    const create = vi.fn().mockRejectedValue(new Error('No such customer'))

    await expect(createPortalSession(create, 'cus_x', 'en')).rejects.toThrow(
      'No such customer'
    )
  })
})
