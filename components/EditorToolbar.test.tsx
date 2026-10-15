// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest'
import { Editor } from '@tiptap/core'
import { reviewEditorExtensions } from '@/components/review/reviewEditorExtensions'
import { EditorToolbar } from './EditorToolbar'
import BioEditorToolbar from './BioEditorToolbar'
import {
  buttonByText,
  byLabel,
  click,
  renderDom,
  typeInto,
} from './testing/renderDom'

let cleanup: (() => void)[] = []
afterEach(() => {
  cleanup.forEach((fn) => fn())
  cleanup = []
})

function makeEditor(markdown: string) {
  const element = document.createElement('div')
  document.body.append(element)
  const editor = new Editor({
    element,
    extensions: reviewEditorExtensions(),
    content: markdown,
    contentType: 'markdown',
  })
  cleanup.push(() => {
    editor.destroy()
    element.remove()
  })
  return editor
}

function mount(node: React.ReactNode) {
  const r = renderDom(node)
  cleanup.push(r.unmount)
  return r.container
}

const labels = (root: ParentNode) =>
  [...root.querySelectorAll('[role="toolbar"] button')].map((b) =>
    b.getAttribute('aria-label')
  )

describe('BioEditorToolbar', () => {
  it('keeps the bio buttons and the link target choice', () => {
    const c = mount(<BioEditorToolbar editor={makeEditor('Bio')} />)
    expect(
      c.querySelector('[role="toolbar"]')?.getAttribute('aria-label')
    ).toBe('Bio formatting toolbar')
    expect(labels(c)).toEqual([
      'Bold',
      'Italic',
      'Heading 2',
      'Heading 3',
      'Bullet list',
      'Numbered list',
      'Insert or edit link',
    ])
    click(byLabel(c, 'Insert or edit link'))
    expect(c.querySelector('select')).not.toBeNull()
  })
})

describe('EditorToolbar, review variant', () => {
  it('adds strikethrough, code, quote and code block buttons', () => {
    const c = mount(
      <EditorToolbar
        editor={makeEditor('x')}
        ariaLabel="Formatting"
        variant="review"
      />
    )
    expect(labels(c)).toEqual([
      'Bold',
      'Italic',
      'Strikethrough',
      'Inline code',
      'Heading 2',
      'Heading 3',
      'Bullet list',
      'Numbered list',
      'Quote',
      'Code block',
      'Insert or edit link',
    ])
  })

  it('applies formatting that is saved as Markdown', () => {
    const editor = makeEditor('hello')
    const c = mount(
      <EditorToolbar editor={editor} ariaLabel="Formatting" variant="review" />
    )
    editor.commands.selectAll()
    click(byLabel(c, 'Bold'))
    expect(editor.getMarkdown().trim()).toBe('**hello**')
  })

  it('has no link target choice and refuses unsafe links', () => {
    const editor = makeEditor('site')
    const c = mount(
      <EditorToolbar editor={editor} ariaLabel="Formatting" variant="review" />
    )
    editor.commands.selectAll()
    click(byLabel(c, 'Insert or edit link'))
    expect(c.querySelector('select')).toBeNull()

    typeInto(c.querySelector('input[type="url"]'), 'javascript:alert(1)')
    click(buttonByText(c, 'Apply'))
    expect(c.textContent).toContain(
      'Use a link starting with https://, http:// or mailto:'
    )
    expect(editor.getMarkdown()).not.toContain('javascript:')

    typeInto(c.querySelector('input[type="url"]'), 'https://example.org')
    click(buttonByText(c, 'Apply'))
    expect(editor.getMarkdown().trim()).toBe('[site](https://example.org)')
  })
})
