import { Editor } from '@tiptap/core'
import { renderToStaticMarkup } from 'react-dom/server'
import { MarkdownView } from './MarkdownView'
import { reviewEditorExtensions } from './reviewEditorExtensions'

/** Markdown after a trip through the visual editor (browser only). */
export function roundTripMarkdown(markdown: string): string {
  const editor = new Editor({
    extensions: reviewEditorExtensions(),
    content: markdown,
    contentType: 'markdown',
  })
  try {
    return editor.getMarkdown()
  } finally {
    editor.destroy()
  }
}

function rendered(markdown: string): string {
  return renderToStaticMarkup(<MarkdownView source={markdown} />)
    .replace(/>\s+</g, '><')
    .trim()
}

/**
 * True when the visual editor can hold this Markdown without changing what
 * readers see. Syntax may be normalised (`*a*` becomes `_a_`); content the
 * editor cannot represent (tables, task lists, footnotes, images) fails,
 * so such entries stay in the Markdown editor.
 */
export function canEditVisually(markdown: string): boolean {
  if (markdown.trim() === '') return true
  return rendered(markdown) === rendered(roundTripMarkdown(markdown))
}
