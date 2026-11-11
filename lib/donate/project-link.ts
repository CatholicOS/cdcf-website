/**
 * The "Support this project" link for a project page: the Donate page's path
 * in the current language with the project preselected, or null when the
 * project is not one of `donatable` (it does not accept donations).
 *
 * `donatePath` must be the Donate page's real localized path (from
 * `getLocalizedPagePaths`): the canonical redirect from a guessed path
 * would drop the query string, and with it the preselection.
 */
export function projectDonateHref(
  projectSlug: string,
  donatable: { slug: string }[],
  donatePath: string
): string | null {
  if (!donatable.some((project) => project.slug === projectSlug)) return null
  return `${donatePath}?project=${encodeURIComponent(projectSlug)}`
}
