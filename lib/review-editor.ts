/**
 * Review editor preferences and link rules. Pure helpers, shared by the
 * visual (TipTap) and Markdown editors.
 */

export type EditorMode = 'visual' | 'markdown'

export const EDITOR_MODE_KEY = 'cdcf.review.editorMode'

type ReadableStorage = Pick<Storage, 'getItem'>
type WritableStorage = Pick<Storage, 'setItem'>

/** The viewer's preferred editor; visual unless they chose Markdown. */
export function readEditorMode(storage: ReadableStorage | null): EditorMode {
  try {
    return storage?.getItem(EDITOR_MODE_KEY) === 'markdown'
      ? 'markdown'
      : 'visual'
  } catch {
    // Private windows and blocked site data can throw on access.
    return 'visual'
  }
}

export function writeEditorMode(
  storage: WritableStorage | null,
  mode: EditorMode
): void {
  try {
    storage?.setItem(EDITOR_MODE_KEY, mode)
  } catch {
    // The preference is a convenience; losing it is harmless.
  }
}

/**
 * Links a reviewer may insert: absolute http(s) or mailto only, matching
 * what MarkdownView renders as a live link.
 */
export function isAllowedLinkHref(href: string): boolean {
  const value = href.trim()
  if (value === '') return false
  let url: URL
  try {
    url = new URL(value)
  } catch {
    return false
  }
  return ['http:', 'https:', 'mailto:'].includes(url.protocol)
}
