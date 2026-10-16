/**
 * A per-process sliding-window limiter, shared by the public form routes.
 * Returns true when `ip` is over the limit.
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

/**
 * The client address as our reverse proxy saw it. X-Real-IP is set (and
 * overwritten) by the proxy; failing that, the last X-Forwarded-For entry
 * is the one the proxy appended. Earlier entries come from the client and
 * could be anything, so they are never used.
 */
export function clientIp(request: Request): string {
  return clientIpFromHeaders(request.headers)
}

/** clientIp for code that has the headers but no Request (server components). */
export function clientIpFromHeaders(headers: Headers): string {
  const realIp = headers.get('x-real-ip')?.trim()
  if (realIp) return realIp
  const forwarded = headers
    .get('x-forwarded-for')
    ?.split(',')
    .map((part) => part.trim())
    .filter(Boolean)
  return forwarded?.at(-1) || 'unknown'
}
