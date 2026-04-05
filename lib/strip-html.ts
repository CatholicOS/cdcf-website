import striptags from 'striptags'

/**
 * Strips HTML tags from a string using the striptags parser.
 */
export function stripHtml(html: string): string {
  return striptags(html).trim()
}
