import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import { Markdown } from '@tiptap/markdown'
import { isAllowedLinkHref } from '@/lib/review-editor'

/**
 * Extensions for the visual review editor. Everything here must survive a
 * Markdown round trip, since entries are stored as Markdown: underline
 * has no Markdown form, so it is off (Ctrl+U would otherwise be lost on
 * save). Links are limited to what MarkdownView renders as a live link.
 */
export function reviewEditorExtensions() {
  return [
    StarterKit.configure({ link: false, underline: false }),
    Link.configure({
      openOnClick: false,
      autolink: true,
      isAllowedUri: (url) => isAllowedLinkHref(url),
    }),
    Markdown,
  ]
}
