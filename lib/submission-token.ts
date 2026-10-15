import { routing } from '@/src/i18n/routing'

/** The raw status token WordPress emails: 64 lowercase hex characters. */
export const STATUS_TOKEN_PATTERN = /^[0-9a-f]{64}$/

const COOKIE_MAX_AGE = 60 * 60 * 24 * 180

/**
 * One cookie per submission, readable by the status page under any locale
 * prefix and by its /api routes, hence path "/" and a per-project name.
 */
export function statusCookieName(projectId: number): string {
  return `cdcf_status_${projectId}`
}

export function statusCookieOptions(secure: boolean) {
  return {
    httpOnly: true,
    secure,
    sameSite: 'lax' as const,
    path: '/',
    maxAge: COOKIE_MAX_AGE,
  }
}

/** The status page path, without the token, in the submission language. */
export function statusPagePath(lang: string, projectId: number): string {
  const known = (routing.locales as readonly string[]).includes(lang)
  const prefix = !known || lang === routing.defaultLocale ? '' : `/${lang}`
  return `${prefix}/submission-status/${projectId}`
}
