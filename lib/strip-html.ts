/**
 * Strips all HTML tags from a string, handling nested/malformed tags
 * by looping until no more tags are found.
 */
export function stripHtml(html: string): string {
  let stripped = html
  let prev
  do {
    prev = stripped
    stripped = stripped.replace(/<[^>]*>/g, '')
  } while (stripped !== prev)
  return stripped.trim()
}
