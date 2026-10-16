import { createHmac } from 'node:crypto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { forwardedClientIpHeaders } from './forwarded-client-ip'

afterEach(() => {
  vi.unstubAllEnvs()
})

describe('forwardedClientIpHeaders', () => {
  it('signs the visitor address with the shared preview secret', () => {
    vi.stubEnv('CDCF_CLIENT_IP_SECRET', 'shared')
    expect(forwardedClientIpHeaders('203.0.113.9')).toEqual({
      'X-CDCF-Client-IP': '203.0.113.9',
      'X-CDCF-Client-IP-Signature': createHmac('sha256', 'shared')
        .update('203.0.113.9')
        .digest('hex'),
    })
  })

  it('never signs with the preview secret, which reaches browsers', () => {
    vi.stubEnv('CDCF_CLIENT_IP_SECRET', '')
    vi.stubEnv('WP_PREVIEW_SECRET', 'shared')
    expect(forwardedClientIpHeaders('203.0.113.9')).toEqual({})
  })

  it('sends nothing without a secret or a known address', () => {
    vi.stubEnv('CDCF_CLIENT_IP_SECRET', '')
    expect(forwardedClientIpHeaders('203.0.113.9')).toEqual({})
    vi.stubEnv('CDCF_CLIENT_IP_SECRET', 'shared')
    expect(forwardedClientIpHeaders('unknown')).toEqual({})
    expect(forwardedClientIpHeaders('')).toEqual({})
  })
})
