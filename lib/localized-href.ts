/**
 * Swap an English page path in a site link for the same page's path in the
 * current language (from `getLocalizedPagePaths`), keeping any fragment.
 * Anything not in the map — external links, bare anchors, routes that are
 * not WordPress pages — is returned unchanged.
 */
export function localizeHref(
  href: string,
  paths: Record<string, string>
): string {
  if (!href.startsWith('/')) return href
  const hashAt = href.indexOf('#')
  const path = hashAt === -1 ? href : href.slice(0, hashAt)
  const hash = hashAt === -1 ? '' : href.slice(hashAt)
  return (paths[path] ?? path) + hash
}
