'use client'

import { useCallback } from 'react'
import { useTranslations } from 'next-intl'
import type { Editor } from '@tiptap/react'
import {
  BoldIcon,
  ItalicIcon,
  H2Icon,
  H3Icon,
  ListBulletIcon,
  NumberedListIcon,
  LinkIcon,
  LinkSlashIcon,
} from '@heroicons/react/24/outline'
import clsx from 'clsx'

/**
 * Toolbar for the BioEditor TipTap instance.
 *
 * Exposes the formatting marks + block types the StarterKit + Link
 * extensions already provide via keyboard shortcuts (Ctrl+B, Ctrl+I,
 * Markdown autocompletion, etc.), so users who don't know the
 * shortcuts have a visible affordance — and so link editing is
 * possible at all (in editable mode TipTap suppresses navigation on
 * link click but provides no built-in way to edit the URL).
 *
 * The Link button is contextual:
 *  - cursor in / spanning a link → window.prompt pre-fills the
 *    existing href so the user can edit it, OR clear it to unlink
 *  - cursor in plain text → prompts for the URL to wrap the
 *    selection (or the next typed-in text) with
 *  - cursor in a link AND an Unlink button is shown alongside,
 *    so removing the link doesn't require opening the prompt and
 *    clearing the field
 *
 * All commands route through `editor.chain().focus()` so the editor
 * regains focus after a toolbar click (otherwise selection collapses
 * and the next typed character goes to nowhere).
 */
export default function BioEditorToolbar({ editor }: { editor: Editor | null }) {
  const t = useTranslations('MyBio.toolbar')

  const promptForLink = useCallback(() => {
    if (!editor) return
    const previous = (editor.getAttributes('link').href as string | undefined) ?? ''
    const next = window.prompt(t('linkUrlPrompt'), previous)
    // User dismissed (Cancel) — leave selection unchanged. `null` is
    // the Cancel signal; '' is the empty-string Enter signal, which
    // means "clear the link".
    if (next === null) return
    if (next === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
      return
    }
    editor
      .chain()
      .focus()
      .extendMarkRange('link')
      .setLink({ href: next })
      .run()
  }, [editor, t])

  if (!editor) return null

  return (
    <div
      role="toolbar"
      aria-label={t('ariaLabel')}
      className="flex flex-wrap items-center gap-1 rounded-t-md border border-b-0 border-gray-300 bg-gray-50 px-2 py-1.5"
    >
      <ToolbarButton
        label={t('bold')}
        isActive={editor.isActive('bold')}
        onClick={() => editor.chain().focus().toggleBold().run()}
      >
        <BoldIcon className="h-4 w-4" />
      </ToolbarButton>
      <ToolbarButton
        label={t('italic')}
        isActive={editor.isActive('italic')}
        onClick={() => editor.chain().focus().toggleItalic().run()}
      >
        <ItalicIcon className="h-4 w-4" />
      </ToolbarButton>

      <ToolbarSeparator />

      <ToolbarButton
        label={t('h2')}
        isActive={editor.isActive('heading', { level: 2 })}
        onClick={() =>
          editor.chain().focus().toggleHeading({ level: 2 }).run()
        }
      >
        <H2Icon className="h-4 w-4" />
      </ToolbarButton>
      <ToolbarButton
        label={t('h3')}
        isActive={editor.isActive('heading', { level: 3 })}
        onClick={() =>
          editor.chain().focus().toggleHeading({ level: 3 }).run()
        }
      >
        <H3Icon className="h-4 w-4" />
      </ToolbarButton>

      <ToolbarSeparator />

      <ToolbarButton
        label={t('bulletList')}
        isActive={editor.isActive('bulletList')}
        onClick={() => editor.chain().focus().toggleBulletList().run()}
      >
        <ListBulletIcon className="h-4 w-4" />
      </ToolbarButton>
      <ToolbarButton
        label={t('orderedList')}
        isActive={editor.isActive('orderedList')}
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
      >
        <NumberedListIcon className="h-4 w-4" />
      </ToolbarButton>

      <ToolbarSeparator />

      <ToolbarButton
        label={t('link')}
        isActive={editor.isActive('link')}
        onClick={promptForLink}
      >
        <LinkIcon className="h-4 w-4" />
      </ToolbarButton>
      {editor.isActive('link') && (
        <ToolbarButton
          label={t('unlink')}
          onClick={() => editor.chain().focus().unsetLink().run()}
        >
          <LinkSlashIcon className="h-4 w-4" />
        </ToolbarButton>
      )}
    </div>
  )
}

function ToolbarButton({
  label,
  isActive,
  onClick,
  children,
}: {
  label: string
  isActive?: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      // type="button" is critical: without it, the browser treats the
      // toolbar button as a submit, which inside the BioEditor's
      // <form> fires save before the editor change lands.
      title={label}
      aria-label={label}
      aria-pressed={isActive ?? false}
      onClick={onClick}
      className={clsx(
        'inline-flex h-7 w-7 items-center justify-center rounded transition-colors',
        isActive
          ? 'bg-cdcf-navy text-white'
          : 'text-gray-700 hover:bg-gray-200 hover:text-cdcf-navy'
      )}
    >
      {children}
    </button>
  )
}

function ToolbarSeparator() {
  return <span aria-hidden="true" className="mx-1 h-5 w-px bg-gray-300" />
}
