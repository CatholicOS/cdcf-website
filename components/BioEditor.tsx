'use client'

import { useCallback, useState } from 'react'
import { useTranslations } from 'next-intl'
import { useEditor, EditorContent } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import clsx from 'clsx'

import type { BioLanguage, BioPostContent } from '@/lib/bio-api'
import BioEditorToolbar from '@/components/BioEditorToolbar'

// Bio length targets. The team agreed bios should land around 80 words; the
// zones below give the author live feedback as they type. Boundaries are
// inclusive on the green side so 70 and 90 read as "good", not "borderline".
const BIO_TARGET_MIN = 70
const BIO_TARGET_MAX = 90
const BIO_WARNING_MIN = 60
const BIO_WARNING_MAX = 100

function countWords(text: string): number {
  // Plain whitespace split is fine for the 6 European languages we support.
  // CJK / Arabic would need Intl.Segmenter with granularity: 'word'.
  const trimmed = text.trim()
  return trimmed === '' ? 0 : trimmed.split(/\s+/).length
}

function wordCountZone(count: number): 'green' | 'orange' | 'red' {
  if (count >= BIO_TARGET_MIN && count <= BIO_TARGET_MAX) return 'green'
  if (count >= BIO_WARNING_MIN && count <= BIO_WARNING_MAX) return 'orange'
  return 'red'
}

type EditableFields = {
  member_title: string
  member_linkedin_url: string
  member_github_url: string
}

function postToEditable(post: BioPostContent): EditableFields {
  return {
    member_title: post.member_title ?? '',
    member_linkedin_url: post.member_linkedin_url ?? '',
    member_github_url: post.member_github_url ?? '',
  }
}

export default function BioEditor({
  availableLanguages,
  initialLang,
  initialPost,
  isBoardMember = false,
}: {
  availableLanguages: BioLanguage[]
  initialLang: string
  initialPost: BioPostContent
  /**
   * When true, the "Position / Affiliation" (member_title) input is
   * rendered as disabled and a hint explains why. The backend
   * (PATCH /cdcf/v1/my-team-member/{lang}) ALSO rejects member_title
   * writes from Board members; this prop only drives the UI.
   */
  isBoardMember?: boolean
}) {
  const t = useTranslations('MyBio')

  const [currentLang, setCurrentLang] = useState(initialLang)
  const [currentTitle, setCurrentTitle] = useState(initialPost.title)
  const [fields, setFields] = useState<EditableFields>(postToEditable(initialPost))
  const [isDirty, setIsDirty] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [isLoadingLang, setIsLoadingLang] = useState(false)
  const [wordCount, setWordCount] = useState(0)
  const [status, setStatus] = useState<
    | { kind: 'idle' }
    | { kind: 'success'; queued: string[] }
    | { kind: 'error'; message: string }
  >({ kind: 'idle' })

  // Wraps "mark dirty + clear any prior save status" — both the field
  // inputs and the TipTap onUpdate flip these together, so a fresh edit
  // after a save makes the green/red banner disappear without a
  // setState-in-effect cascade.
  const markDirty = useCallback(() => {
    setIsDirty(true)
    setStatus({ kind: 'idle' })
  }, [])

  const editor = useEditor({
    extensions: [
      // StarterKit v3 bundles the Link extension by default. Registering
      // our own Link.configure() alongside the bundled one produces a
      // "multiple instances of the Link extension" console warning AND
      // the bundled instance's default openOnClick=true wins the click
      // race so plain clicks still navigate out of the editor. Disable
      // the bundled link so our explicit configuration is the only one
      // ProseMirror knows about. See
      // https://tiptap.dev/docs/editor/extensions/marks/link
      StarterKit.configure({ link: false }),
      Link.configure({
        openOnClick: false,
        autolink: false,
        // `rel` is the only attribute we force globally — every link
        // gets `noopener noreferrer` for tabnabbing-prevention even
        // when `target` is `_self`, since outbound HTTPS clicks can
        // still leak `Referer` we don't want. `target` is intentionally
        // omitted from the global HTMLAttributes so each link can
        // carry its own value (configured via the toolbar popover).
        HTMLAttributes: { rel: 'noopener noreferrer' },
      }),
    ],
    content: initialPost.content,
    immediatelyRender: false,
    onCreate({ editor }) {
      setWordCount(countWords(editor.getText()))
    },
    onUpdate({ editor }) {
      markDirty()
      setWordCount(countWords(editor.getText()))
    },
    editorProps: {
      attributes: {
        // rounded-b-md (not rounded-md) + border-t-0 because the
        // BioEditorToolbar sits flush above us with rounded-t-md +
        // border-b-0 — together they form a single visual block.
        class:
          'prose max-w-none min-h-[16rem] rounded-b-md border border-t-0 border-gray-300 bg-white px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cdcf-navy',
      },
      // Suppress link-click navigation inside the editable surface.
      // The Link extension's `openOnClick: false` only stops its own
      // open-on-click handler; the browser still follows an
      // `<a href="…" target="_blank">` when the user clicks it plainly
      // (or Ctrl/Cmd-clicks any `<a>`). Intercept at the editor level
      // so plain clicks ALWAYS just position the cursor — the only
      // navigation path is the toolbar popover's "Navigate to URL"
      // action (uses window.open out-of-band, bypassing this handler).
      handleClick(_view, _pos, event) {
        const target = event.target as HTMLElement | null
        if (target?.closest('a')) {
          event.preventDefault()
        }
        return false
      },
    },
  })

  const updateField = useCallback(
    <K extends keyof EditableFields>(key: K, value: EditableFields[K]) => {
      setFields((prev) => ({ ...prev, [key]: value }))
      markDirty()
    },
    [markDirty]
  )

  const switchLanguage = useCallback(
    async (nextLang: string) => {
      if (nextLang === currentLang) return
      if (isDirty) {
        const proceed = window.confirm(t('unsavedSwitch'))
        if (!proceed) return
      }
      setIsLoadingLang(true)
      setStatus({ kind: 'idle' })
      try {
        const response = await fetch(`/api/my-bio/load/${nextLang}`, {
          cache: 'no-store',
        })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const post = (await response.json()) as BioPostContent
        setCurrentLang(nextLang)
        setCurrentTitle(post.title)
        setFields(postToEditable(post))
        editor?.commands.setContent(post.content, { emitUpdate: false })
        // setContent with emitUpdate: false suppresses onUpdate by design
        // (we don't want the language switch to flip isDirty), so sync the
        // word count manually here.
        if (editor) {
          setWordCount(countWords(editor.getText()))
        }
        setIsDirty(false)
      } catch (err) {
        setStatus({ kind: 'error', message: (err as Error).message })
      } finally {
        setIsLoadingLang(false)
      }
    },
    [currentLang, editor, isDirty, t]
  )

  const handleSave = useCallback(async () => {
    if (!editor) return
    setIsSaving(true)
    setStatus({ kind: 'idle' })
    try {
      // Defense in depth: even though the input is disabled, strip
      // member_title from the request when the caller is on the
      // Board so a tampered DOM can't sneak a change past the
      // server. The PATCH handler rejects this anyway, but failing
      // cleanly client-side keeps the user-visible flow consistent.
      const { member_title: localMemberTitle, ...rest } = fields
      const payload: Record<string, unknown> = {
        lang: currentLang,
        content: editor.getHTML(),
        ...(isBoardMember ? rest : { ...rest, member_title: localMemberTitle }),
      }
      const response = await fetch('/api/my-bio/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
      const body = (await response.json()) as
        | { post_id: number; queued: string[]; errors: string[] }
        | { error: string; message?: string }
      if (!response.ok || 'error' in body) {
        const message =
          'message' in body && body.message
            ? body.message
            : 'error' in body
              ? body.error
              : 'Save failed'
        setStatus({ kind: 'error', message })
        return
      }
      setStatus({ kind: 'success', queued: body.queued })
      setIsDirty(false)
    } catch (err) {
      setStatus({ kind: 'error', message: (err as Error).message })
    } finally {
      setIsSaving(false)
    }
  }, [currentLang, editor, fields, isBoardMember])

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="cdcf-heading text-2xl">{t('title')}</h1>
        <label className="flex items-center gap-2 text-sm">
          <span className="text-gray-600">{t('languageLabel')}</span>
          <select
            value={currentLang}
            onChange={(e) => switchLanguage(e.target.value)}
            disabled={isLoadingLang || isSaving}
            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
          >
            {availableLanguages.map((lang) => (
              <option key={lang.slug} value={lang.slug}>
                {lang.slug.toUpperCase()}
              </option>
            ))}
          </select>
        </label>
      </header>

      <p className="text-sm text-gray-600">{t('intro')}</p>

      <fieldset className="space-y-4" disabled={isLoadingLang || isSaving}>
        <div>
          {/* Not a <label>: there's no editable input here — the team
              member's display name is admin-managed and surfaced read-
              only. A <label> with no associated control fails the
              jsx-a11y rule. */}
          <span className="block text-xs font-medium uppercase tracking-wide text-gray-500">
            {t('postTitleLabel')}
          </span>
          <p className="mt-1 text-base">{currentTitle}</p>
        </div>

        <div>
          <label
            htmlFor="bio-content"
            className="block text-xs font-medium uppercase tracking-wide text-gray-500"
          >
            {t('contentLabel')}
          </label>
          <div id="bio-content" className="mt-1">
            <BioEditorToolbar editor={editor} />
            <EditorContent editor={editor} />
            <div className="mt-1 flex items-center justify-end gap-3 text-xs">
              <span
                className={clsx(
                  'font-medium tabular-nums',
                  wordCountZone(wordCount) === 'green' && 'text-green-700',
                  wordCountZone(wordCount) === 'orange' && 'text-orange-600',
                  wordCountZone(wordCount) === 'red' && 'text-red-700'
                )}
                aria-live="polite"
              >
                {t('wordCount', { count: wordCount })}
              </span>
              <span className="text-gray-500">
                {t('wordCountTarget', { min: BIO_TARGET_MIN, max: BIO_TARGET_MAX })}
              </span>
            </div>
          </div>
        </div>

        <div>
          <label
            htmlFor="member_title"
            className="block text-xs font-medium uppercase tracking-wide text-gray-500"
          >
            {t('memberTitleLabel')}
          </label>
          <input
            id="member_title"
            type="text"
            value={fields.member_title}
            onChange={(e) => updateField('member_title', e.target.value)}
            disabled={isBoardMember}
            readOnly={isBoardMember}
            aria-describedby={isBoardMember ? 'member_title_readonly_hint' : undefined}
            className={clsx(
              'mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy',
              isBoardMember && 'cursor-not-allowed bg-gray-50 text-gray-500'
            )}
          />
          {isBoardMember && (
            <p
              id="member_title_readonly_hint"
              className="mt-1 text-xs text-gray-500"
            >
              {t('memberTitleBoardReadonly')}
            </p>
          )}
        </div>

        <div>
          <label
            htmlFor="member_linkedin_url"
            className="block text-xs font-medium uppercase tracking-wide text-gray-500"
          >
            {t('linkedinLabel')}
          </label>
          <input
            id="member_linkedin_url"
            type="url"
            inputMode="url"
            placeholder="https://www.linkedin.com/in/…"
            value={fields.member_linkedin_url}
            onChange={(e) => updateField('member_linkedin_url', e.target.value)}
            className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
          />
        </div>

        <div>
          <label
            htmlFor="member_github_url"
            className="block text-xs font-medium uppercase tracking-wide text-gray-500"
          >
            {t('githubLabel')}
          </label>
          <input
            id="member_github_url"
            type="url"
            inputMode="url"
            placeholder="https://github.com/…"
            value={fields.member_github_url}
            onChange={(e) => updateField('member_github_url', e.target.value)}
            className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
          />
        </div>
      </fieldset>

      <div className="flex items-center justify-between gap-3">
        <div className="min-h-[1.25rem] text-sm">
          {status.kind === 'success' && (
            <span className="text-green-700">
              {status.queued.length > 0
                ? t('savedQueued', { langs: status.queued.join(', ').toUpperCase() })
                : t('savedNoChange')}
            </span>
          )}
          {status.kind === 'error' && (
            <span className="text-red-700">{t('saveError', { error: status.message })}</span>
          )}
        </div>
        <button
          type="button"
          onClick={handleSave}
          disabled={!isDirty || isSaving || isLoadingLang}
          className={clsx(
            'cdcf-btn-primary text-sm',
            (!isDirty || isSaving || isLoadingLang) && 'cursor-not-allowed opacity-60'
          )}
        >
          {isSaving ? t('saving') : t('save')}
        </button>
      </div>
    </div>
  )
}
