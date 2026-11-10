/**
 * URL-safe slug from a display string: strips diacritics, drops straight and
 * smart apostrophes (D'Orazio → dorazio), and hyphenates everything else.
 * Returns '' when nothing usable remains, so callers supply their own fallback.
 */
export function slugify(value: string): string {
  return value
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '') // strip diacritics
    .replace(/['’]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}
