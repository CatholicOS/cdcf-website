'use client'

import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
} from 'react'
import { useTranslations } from 'next-intl'
import { EditorContent, useEditor } from '@tiptap/react'
import {
  readEditorMode,
  writeEditorMode,
  type EditorMode,
} from '@/lib/review-editor'
import { EditorToolbar } from '@/components/EditorToolbar'
import { MarkdownEditor } from './MarkdownEditor'
import { reviewEditorExtensions } from './reviewEditorExtensions'
import { canEditVisually } from './visualEditorSafety'

type Props = {
  value: string
  onChange: (value: string) => void
  label: string
  rows?: number
  disabled?: boolean
}

const MODE_EVENT = 'cdcf-review-editor-mode'

function browserStorage(): Storage | null {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

function subscribeMode(onChange: () => void) {
  window.addEventListener('storage', onChange)
  window.addEventListener(MODE_EVENT, onChange)
  return () => {
    window.removeEventListener('storage', onChange)
    window.removeEventListener(MODE_EVENT, onChange)
  }
}

function setMode(mode: EditorMode) {
  writeEditorMode(browserStorage(), mode)
  window.dispatchEvent(new Event(MODE_EVENT))
}

const noopSubscribe = () => () => {}

/**
 * Editor for review entries. Each viewer picks the visual editor (TipTap
 * with a toolbar, the default) or the Markdown editor (textarea with
 * preview); the choice is remembered in their browser. Either way the
 * value is Markdown, rendered by MarkdownView. Text the visual editor
 * cannot hold without changing what readers see (tables, images, …)
 * stays in the Markdown editor, with a note.
 */
export function ReviewEditor({
  value,
  onChange,
  label,
  rows = 6,
  disabled = false,
}: Props) {
  const t = useTranslations('Review.editor')
  const mounted = useSyncExternalStore(
    noopSubscribe,
    () => true,
    () => false
  )
  const preferred = useSyncExternalStore(
    subscribeMode,
    () => readEditorMode(browserStorage()),
    () => 'visual' as const
  )
  // The text the visual editor is checked against: what the entry held
  // when it opened, or when the viewer last switched to visual.
  const [checkedValue, setCheckedValue] = useState(value)
  const [refused, setRefused] = useState(false)
  const blocked = useMemo(
    () => mounted && preferred === 'visual' && !canEditVisually(checkedValue),
    [mounted, preferred, checkedValue]
  )

  if (!mounted) {
    // Server render and hydration: the preference lives in the browser.
    return (
      <div
        className="min-h-32 rounded-md border border-gray-200"
        aria-hidden="true"
      />
    )
  }

  const visual = preferred === 'visual' && !blocked

  function switchToVisual() {
    if (!canEditVisually(value)) {
      setRefused(true)
      return
    }
    setRefused(false)
    setCheckedValue(value)
    setMode('visual')
  }

  return (
    <div className="space-y-1">
      {(blocked || refused) && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
          {t('visualUnavailable')}
        </p>
      )}
      {visual ? (
        <VisualEditor
          value={value}
          onChange={onChange}
          label={label}
          rows={rows}
          disabled={disabled}
        />
      ) : (
        <MarkdownEditor
          value={value}
          onChange={(v) => {
            setRefused(false)
            onChange(v)
          }}
          label={label}
          rows={rows}
          disabled={disabled}
        />
      )}
      <div className="flex justify-end">
        <button
          type="button"
          onClick={() => (visual ? setMode('markdown') : switchToVisual())}
          className="text-xs text-gray-500 hover:text-cdcf-navy hover:underline"
        >
          {visual ? t('useMarkdown') : t('useVisual')}
        </button>
      </div>
    </div>
  )
}

function VisualEditor({
  value,
  onChange,
  label,
  rows,
  disabled,
}: Required<Props>) {
  const t = useTranslations('Review.editor')
  const labelId = useId()
  // The Markdown this editor last produced, so a value change that came
  // from outside (a reset after posting) can be told apart from typing.
  const lastEmitted = useRef(value)
  const editor = useEditor({
    extensions: reviewEditorExtensions(),
    content: value,
    contentType: 'markdown',
    immediatelyRender: false,
    editable: !disabled,
    editorProps: {
      attributes: {
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-labelledby': labelId,
        class:
          'prose prose-sm max-w-none rounded-b-md border border-gray-300 bg-white px-3 py-2 focus:outline-none focus:ring-1 focus:ring-cdcf-navy',
        style: `min-height: ${rows * 1.5}rem`,
      },
    },
    onUpdate: ({ editor: e }) => {
      const md = e.getMarkdown()
      lastEmitted.current = md
      onChange(md)
    },
  })

  useEffect(() => {
    if (!editor || value === lastEmitted.current) return
    lastEmitted.current = value
    editor.commands.setContent(value, {
      contentType: 'markdown',
      emitUpdate: false,
    })
  }, [editor, value])

  useEffect(() => {
    editor?.setEditable(!disabled)
  }, [editor, disabled])

  return (
    <div>
      <span
        id={labelId}
        className="block text-xs font-medium uppercase tracking-wide text-gray-500"
      >
        {label}
      </span>
      <div className="mt-1">
        <EditorToolbar
          editor={editor}
          ariaLabel={t('toolbarLabel')}
          variant="review"
        />
        <EditorContent editor={editor} />
      </div>
    </div>
  )
}
