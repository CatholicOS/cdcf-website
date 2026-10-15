import { createHash, timingSafeEqual } from 'node:crypto'

/**
 * Constant-time shared-secret check. Both sides are hashed first so the
 * comparison never leaks the expected secret's length. Fails closed when
 * the expected secret is unset or empty, so a misconfigured deployment
 * never accepts a missing secret.
 */
export function secretMatches(
  given: unknown,
  expected: string | undefined
): boolean {
  if (!expected || typeof given !== 'string') return false
  const digest = (s: string) => createHash('sha256').update(s).digest()
  return timingSafeEqual(digest(given), digest(expected))
}
