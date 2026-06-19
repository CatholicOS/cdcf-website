import { defaultLocale } from '@/src/i18n/routing'

/** Strip leading and trailing slashes. */
function trimSlashes(value: string): string {
  return value.replace(/^\/+|\/+$/g, '')
}

/**
 * Compare a requested locale + slug against the resolved page's real
 * Polylang `uri` and return the canonical path to 308-redirect to, or
 * `null` when the request is already canonical.
 *
 * WPGraphQL resolves a page by its leaf slug regardless of language and
 * then `.translation(locale)` yields the requested-locale version — so any
 * locale prefix crossed with any language's slug returns 200 for the same
 * content (the GSC "duplicate, no user-selected canonical" reports). The
 * one true URL for a piece of content is its Polylang `uri`, and Polylang's
 * uri prefixing (`/it/…`, none for the default locale) matches next-intl's
 * `as-needed` scheme exactly — so the page's `uri` *is* the frontend path.
 */
export function canonicalRedirectPath(
  lang: string,
  slugSegments: string[] | undefined,
  pageUri: string | null | undefined
): string | null {
  if (!pageUri) return null

  const prefix = lang === defaultLocale ? '' : `${lang}/`
  const requested = trimSlashes(`${prefix}${slugSegments?.join('/') ?? ''}`)
  const canonical = trimSlashes(pageUri)

  return canonical === requested ? null : `/${canonical}`
}

/** Build an absolute canonical URL from a site origin and a page `uri`. */
export function canonicalAbsoluteUrl(siteUrl: string, pageUri: string): string {
  return `${siteUrl}/${trimSlashes(pageUri)}`
}
