/**
 * A per-process sliding-window limiter (the same in-memory approach as the
 * submit-project route). Returns true when `ip` is over the limit.
 */
export function createIpRateLimiter(limit: number, windowMs: number) {
  const hits = new Map<string, number[]>()
  return (ip: string, now: number = Date.now()): boolean => {
    const recent = (hits.get(ip) ?? []).filter((t) => now - t < windowMs)
    if (recent.length >= limit) {
      hits.set(ip, recent)
      return true
    }
    recent.push(now)
    hits.set(ip, recent)
    return false
  }
}

export function clientIp(request: Request): string {
  return (
    request.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ||
    request.headers.get('x-real-ip') ||
    'unknown'
  )
}
