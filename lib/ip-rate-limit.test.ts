import { describe, expect, it } from 'vitest'
import { clientIp, createIpRateLimiter } from './ip-rate-limit'

describe('createIpRateLimiter', () => {
  it('limits each IP separately within the window', () => {
    const limited = createIpRateLimiter(2, 1000)
    expect(limited('a', 0)).toBe(false)
    expect(limited('a', 10)).toBe(false)
    expect(limited('a', 20)).toBe(true)
    expect(limited('b', 20)).toBe(false)
    expect(limited('a', 1015)).toBe(false)
  })
})

describe('clientIp', () => {
  const req = (h: Record<string, string>) =>
    new Request('http://x/', { headers: h })

  it('prefers x-real-ip, which the proxy overwrites', () => {
    expect(
      clientIp(
        req({ 'x-real-ip': '203.0.113.6', 'x-forwarded-for': '1.2.3.4' })
      )
    ).toBe('203.0.113.6')
  })

  it('otherwise takes the address our proxy appended, not one the client sent', () => {
    // A client can send any X-Forwarded-For; the proxy appends the real
    // peer address at the end.
    expect(clientIp(req({ 'x-forwarded-for': '1.2.3.4, 203.0.113.5' }))).toBe(
      '203.0.113.5'
    )
  })

  it('falls back to "unknown"', () => {
    expect(clientIp(req({}))).toBe('unknown')
  })
})
