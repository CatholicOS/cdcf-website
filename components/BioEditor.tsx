'use client'

import { useCallback, useState } from 'react'
import { useTranslations } from 'next-intl'
import { useEditor, EditorContent } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import clsx from 'clsx'

import type { BioLanguage, BioPostContent } from '@/lib/bio-api'

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
}: {
  availableLanguages: BioLanguage[]
  initialLang: string
  initialPost: BioPostContent
}) {
  const t = useTranslations('MyBio')

  const [currentLang, setCurrentLang] = useState(initialLang)
  const [currentTitle, setCurrentTitle] = useState(initialPost.title)
  const [fields, setFields] = useState<EditableFields>(postToEditable(initialPost))
  const [isDirty, setIsDirty] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [isLoadingLang, setIsLoadingLang] = useState(false)
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
      StarterKit,
      Link.configure({
        openOnClick: false,
        autolink: false,
        HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
      }),
    ],
    content: initialPost.content,
    immediatelyRender: false,
    onUpdate: markDirty,
    editorProps: {
      attributes: {
        class:
          'prose max-w-none min-h-[16rem] rounded-md border border-gray-300 bg-white px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cdcf-navy',
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
      const response = await fetch('/api/my-bio/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          lang: currentLang,
          content: editor.getHTML(),
          ...fields,
        }),
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
  }, [currentLang, editor, fields])

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
          <label className="block text-xs font-medium uppercase tracking-wide text-gray-500">
            {t('postTitleLabel')}
          </label>
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
            <EditorContent editor={editor} />
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
            className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
          />
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
