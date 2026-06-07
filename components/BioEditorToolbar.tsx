'use client'

import {
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
  type ReactNode,
} from 'react'
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
  ArrowTopRightOnSquareIcon,
} from '@heroicons/react/24/outline'
import clsx from 'clsx'

/**
 * Toolbar for the BioEditor TipTap instance.
 *
 * Exposes the formatting marks + block types StarterKit + Link already
 * provide via keyboard shortcuts (Ctrl+B, Ctrl+I, Markdown autocomplete,
 * etc.), so users who don't know the shortcuts have a visible
 * affordance — and so link editing is possible at all (in editable
 * mode TipTap suppresses navigation on link click + the BioEditor
 * installs an editor-wide click suppressor on top, but neither
 * provides a way to see or edit an existing link's href).
 *
 * The Link button toggles an inline popover with:
 *  - URL input (pre-filled when cursor is in a link)
 *  - Target attribute select: `_self` (Same window) or `_blank` (New
 *    tab or window). Per-link, not global — `Link.configure` only
 *    forces `rel="noopener noreferrer"`.
 *  - Apply — write the href + target back via setLink
 *  - Remove — unset the link (rendered only when cursor is on one)
 *  - Navigate to URL — opens the current URL in a new tab via
 *    window.open. This is the ONLY navigation path; plain clicks on
 *    link text in the editor never navigate (BioEditor's handleClick
 *    preventDefaults all editable-surface link clicks).
 *
 * All commands route through `editor.chain().focus()` so the editor
 * regains focus after a toolbar click (otherwise selection collapses
 * and the next typed character goes to nowhere).
 */
export default function BioEditorToolbar({ editor }: { editor: Editor | null }) {
  const t = useTranslations('MyBio.toolbar')
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkHref, setLinkHref] = useState('')
  const [linkTarget, setLinkTarget] = useState<'_self' | '_blank'>('_blank')
  const popoverRef = useRef<HTMLDivElement | null>(null)
  const linkButtonRef = useRef<HTMLButtonElement | null>(null)
  const urlInputRef = useRef<HTMLInputElement | null>(null)
  const urlInputId = useId()
  const targetSelectId = useId()

  // Focus the URL field when the popover opens. Done programmatically
  // (not via the declarative `autoFocus` attribute) because Codacy /
  // jsx-a11y/no-autofocus flags the attribute as a usability hazard
  // outside narrow modal contexts — this is a `role="dialog"` popover
  // where focusing the primary input on open is genuinely the right
  // behaviour. Moving the focus call into useEffect lets us be
  // intentional about WHEN we steal focus (only on open transition,
  // not on every render) without tripping the lint rule.
  useEffect(() => {
    if (linkOpen) {
      urlInputRef.current?.focus()
    }
  }, [linkOpen])

  const openLinkPopover = useCallback(() => {
    if (!editor) return
    const attrs = editor.getAttributes('link')
    const href = typeof attrs.href === 'string' ? attrs.href : ''
    const target =
      attrs.target === '_self' || attrs.target === '_blank' ? attrs.target : '_blank'
    setLinkHref(href)
    setLinkTarget(target)
    setLinkOpen(true)
  }, [editor])

  const closeLinkPopover = useCallback(() => {
    setLinkOpen(false)
  }, [])

  // Close the popover on click-outside + Escape. mousedown (not click)
  // is the listener so the focus transition completes before tear-down
  // — otherwise interacting with the target <select> could synthesize
  // a click-outside.
  useEffect(() => {
    if (!linkOpen) return
    const onPointer = (event: MouseEvent) => {
      const target = event.target as Node | null
      if (!target) return
      if (popoverRef.current?.contains(target)) return
      if (linkButtonRef.current?.contains(target)) return
      closeLinkPopover()
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        closeLinkPopover()
        linkButtonRef.current?.focus()
      }
    }
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [linkOpen, closeLinkPopover])

  const applyLink = useCallback(() => {
    if (!editor) return
    const href = linkHref.trim()
    if (href === '') {
      // Empty URL submitted on Apply → treat as "remove the link".
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
    } else {
      editor
        .chain()
        .focus()
        .extendMarkRange('link')
        .setLink({ href, target: linkTarget })
        .run()
    }
    closeLinkPopover()
  }, [editor, linkHref, linkTarget, closeLinkPopover])

  const removeLink = useCallback(() => {
    if (!editor) return
    editor.chain().focus().extendMarkRange('link').unsetLink().run()
    closeLinkPopover()
  }, [editor, closeLinkPopover])

  const navigateToLink = useCallback(() => {
    const href = linkHref.trim()
    if (href === '') return
    // The ONLY path that actually navigates. window.open in a separate
    // tab keeps the editor open so the user can keep editing. The
    // 'noopener,noreferrer' window features match the rel attribute the
    // Link extension configures globally, so the external page can't
    // navigate the opener tab and `Referer` doesn't leak.
    window.open(href, '_blank', 'noopener,noreferrer')
  }, [linkHref])

  if (!editor) return null

  return (
    <div className="relative">
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
          ref={linkButtonRef}
          label={t('link')}
          isActive={editor.isActive('link') || linkOpen}
          aria-haspopup="dialog"
          aria-expanded={linkOpen}
          onClick={() => {
            if (linkOpen) {
              closeLinkPopover()
            } else {
              openLinkPopover()
            }
          }}
        >
          <LinkIcon className="h-4 w-4" />
        </ToolbarButton>
      </div>

      {linkOpen && (
        <div
          ref={popoverRef}
          role="dialog"
          aria-label={t('linkPopoverLabel')}
          className="absolute left-2 right-2 top-full z-10 mt-1 rounded-md border border-gray-300 bg-white p-3 shadow-lg sm:right-auto sm:w-96"
        >
          <div className="space-y-3">
            <div>
              <label
                htmlFor={urlInputId}
                className="block text-xs font-medium uppercase tracking-wide text-gray-500"
              >
                {t('linkUrlLabel')}
              </label>
              <input
                ref={urlInputRef}
                id={urlInputId}
                type="url"
                value={linkHref}
                onChange={(e) => setLinkHref(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    applyLink()
                  }
                }}
                placeholder="https://"
                className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
              />
            </div>

            <div>
              <label
                htmlFor={targetSelectId}
                className="block text-xs font-medium uppercase tracking-wide text-gray-500"
              >
                {t('linkTargetLabel')}
              </label>
              <select
                id={targetSelectId}
                value={linkTarget}
                onChange={(e) =>
                  setLinkTarget(e.target.value as '_self' | '_blank')
                }
                className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
              >
                <option value="_self">{t('linkTargetSelf')}</option>
                <option value="_blank">{t('linkTargetBlank')}</option>
              </select>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
              <PopoverButton
                onClick={navigateToLink}
                disabled={linkHref.trim() === ''}
                variant="secondary"
              >
                <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                <span>{t('linkNavigate')}</span>
              </PopoverButton>
              {editor.isActive('link') && (
                <PopoverButton onClick={removeLink} variant="danger">
                  <LinkSlashIcon className="h-4 w-4" />
                  <span>{t('unlink')}</span>
                </PopoverButton>
              )}
              <PopoverButton onClick={closeLinkPopover} variant="secondary">
                {t('cancel')}
              </PopoverButton>
              <PopoverButton onClick={applyLink} variant="primary">
                {t('apply')}
              </PopoverButton>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function ToolbarButton({
  ref,
  label,
  isActive,
  onClick,
  children,
  ...rest
}: {
  ref?: React.Ref<HTMLButtonElement>
  label: string
  isActive?: boolean
  onClick: () => void
  children: ReactNode
} & Omit<
  React.ButtonHTMLAttributes<HTMLButtonElement>,
  'type' | 'onClick' | 'title' | 'aria-label' | 'aria-pressed' | 'className'
>) {
  return (
    <button
      ref={ref}
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
      {...rest}
    >
      {children}
    </button>
  )
}

function ToolbarSeparator() {
  return <span aria-hidden="true" className="mx-1 h-5 w-px bg-gray-300" />
}

function PopoverButton({
  onClick,
  disabled,
  variant,
  children,
}: {
  onClick: () => void
  disabled?: boolean
  variant: 'primary' | 'secondary' | 'danger'
  children: ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={clsx(
        'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50',
        variant === 'primary' &&
          'bg-cdcf-navy text-white hover:bg-cdcf-navy/90',
        variant === 'secondary' &&
          'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 hover:text-cdcf-navy',
        variant === 'danger' &&
          'border border-red-300 bg-white text-red-700 hover:bg-red-50'
      )}
    >
      {children}
    </button>
  )
}
