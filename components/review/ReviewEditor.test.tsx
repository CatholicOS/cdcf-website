// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, useState } from 'react'
import type { Editor } from '@tiptap/core'
import { EDITOR_MODE_KEY } from '@/lib/review-editor'
import { ReviewEditor } from './ReviewEditor'
import {
  buttonByText,
  byLabel,
  click,
  renderDom,
  typeInto,
} from '@/components/testing/renderDom'

let unmounts: (() => void)[] = []
beforeEach(() => window.localStorage.clear())
afterEach(() => {
  for (const fn of unmounts) fn()
  unmounts = []
})

const LABEL = 'Your review'

function Harness({
  initial,
  onChange,
  disabled = false,
}: {
  initial: string
  onChange?: (v: string) => void
  disabled?: boolean
}) {
  const [value, setValue] = useState(initial)
  return (
    <>
      <ReviewEditor
        value={value}
        onChange={(v) => {
          setValue(v)
          onChange?.(v)
        }}
        label={LABEL}
        disabled={disabled}
      />
      <button type="button" onClick={() => setValue('')}>
        reset
      </button>
    </>
  )
}

function mount(node: React.ReactNode) {
  const r = renderDom(node)
  unmounts.push(r.unmount)
  return r
}

const TABLE = '| a | b |\n| - | - |\n| 1 | 2 |'

function editorOf(root: ParentNode): Editor {
  const dom = root.querySelector('.ProseMirror') as
    (HTMLElement & { editor?: Editor }) | null
  expect(dom?.editor).toBeDefined()
  return dom?.editor as Editor
}

describe('ReviewEditor', () => {
  it('opens the visual editor with a toolbar by default', () => {
    const { container } = mount(<Harness initial="hello" />)
    expect(container.querySelector('[contenteditable="true"]')).not.toBeNull()
    expect(byLabel(container, 'Formatting')).not.toBeNull()
    expect(container.querySelector('textarea')).toBeNull()
  })

  it('saves visual edits as Markdown', () => {
    const onChange = vi.fn()
    const { container } = mount(<Harness initial="hello" onChange={onChange} />)
    act(() => {
      editorOf(container).commands.selectAll()
    })
    click(byLabel(container, 'Bold'))
    expect(onChange).toHaveBeenLastCalledWith('**hello**')
  })

  it('follows a value reset from outside, as after posting', () => {
    const { container } = mount(<Harness initial="hello" />)
    click(buttonByText(container, 'reset'))
    expect(editorOf(container).getText()).toBe('')
  })

  it('is read-only while disabled', () => {
    const { container } = mount(<Harness initial="hello" disabled />)
    expect(container.querySelector('[contenteditable="false"]')).not.toBeNull()
  })

  it('uses the Markdown editor, with preview, when that is the preference', () => {
    window.localStorage.setItem(EDITOR_MODE_KEY, 'markdown')
    const { container } = mount(<Harness initial="**hi**" />)
    expect(container.querySelector('textarea')?.value).toBe('**hi**')
    expect(buttonByText(container, 'Preview')).toBeDefined()
    expect(container.querySelector('.ProseMirror')).toBeNull()
  })

  it('switches to Markdown carrying the text, and remembers the choice', () => {
    const { container } = mount(<Harness initial="hello" />)
    act(() => {
      editorOf(container).commands.selectAll()
    })
    click(byLabel(container, 'Bold'))
    click(buttonByText(container, 'Use the Markdown editor'))
    expect(container.querySelector('textarea')?.value).toBe('**hello**')
    expect(window.localStorage.getItem(EDITOR_MODE_KEY)).toBe('markdown')
  })

  it('switches back to the visual editor when the text allows it', () => {
    window.localStorage.setItem(EDITOR_MODE_KEY, 'markdown')
    const { container } = mount(<Harness initial="" />)
    typeInto(container.querySelector('textarea'), 'Some *emphasis*')
    click(buttonByText(container, 'Use the visual editor'))
    expect(editorOf(container).getMarkdown().trim()).toBe('Some *emphasis*')
    expect(window.localStorage.getItem(EDITOR_MODE_KEY)).toBe('visual')
  })

  it('keeps text the visual editor cannot show in the Markdown editor', () => {
    const { container } = mount(<Harness initial={TABLE} />)
    expect(container.querySelector('textarea')?.value).toBe(TABLE)
    expect(container.textContent).toContain("can't show")
    expect(window.localStorage.getItem(EDITOR_MODE_KEY)).toBeNull()
  })

  it('refuses to switch to visual when that would lose formatting', () => {
    window.localStorage.setItem(EDITOR_MODE_KEY, 'markdown')
    const { container } = mount(<Harness initial={TABLE} />)
    click(buttonByText(container, 'Use the visual editor'))
    expect(container.querySelector('textarea')?.value).toBe(TABLE)
    expect(container.textContent).toContain("can't show")
    expect(window.localStorage.getItem(EDITOR_MODE_KEY)).toBe('markdown')
  })
})
