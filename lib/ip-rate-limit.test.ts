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
  it('takes the first forwarded address, then x-real-ip', () => {
    const req = (h: Record<string, string>) =>
      new Request('http://x/', { headers: h })
    expect(clientIp(req({ 'x-forwarded-for': '203.0.113.5, 10.0.0.1' }))).toBe(
      '203.0.113.5'
    )
    expect(clientIp(req({ 'x-real-ip': '203.0.113.6' }))).toBe('203.0.113.6')
    expect(clientIp(req({}))).toBe('unknown')
  })
})
