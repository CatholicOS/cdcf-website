'use client'

import { useRef, useState } from 'react'
import { useTranslations } from 'next-intl'
import { DocumentTextIcon, PaperClipIcon } from '@heroicons/react/24/outline'
import type { PendingAttachment } from '@/lib/review-types'
import { checkAttachmentFile, type ReviewErrorKey } from '@/lib/review-ui'

const ACCEPT =
  '.md,.png,.jpg,.jpeg,.webp,.gif,text/markdown,image/png,image/jpeg,image/webp,image/gif'

let counter = 0
const nextKey = () => `pending-${Date.now()}-${counter++}`

export function AttachControl({
  onAdd,
  disabled = false,
}: {
  onAdd: (p: PendingAttachment) => void
  disabled?: boolean
}) {
  const t = useTranslations('Review')
  const input = useRef<HTMLInputElement>(null)
  const [pasting, setPasting] = useState(false)
  const [title, setTitle] = useState('')
  const [markdown, setMarkdown] = useState('')
  const [rejected, setRejected] = useState<
    { name: string; errorKey: ReviewErrorKey }[]
  >([])

  function onFiles(files: FileList | null) {
    const problems: { name: string; errorKey: ReviewErrorKey }[] = []
    for (const file of Array.from(files ?? [])) {
      const problem = checkAttachmentFile(file.name, file.size)
      if (problem) problems.push({ name: file.name, errorKey: problem })
      else onAdd({ key: nextKey(), kind: 'file', file })
    }
    setRejected(problems)
    if (input.current) input.current.value = ''
  }

  function addPaste() {
    if (!markdown.trim()) return
    onAdd({ key: nextKey(), kind: 'paste', title, markdown })
    setTitle('')
    setMarkdown('')
    setPasting(false)
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-3 text-sm">
        <button
          type="button"
          disabled={disabled}
          onClick={() => input.current?.click()}
          className="inline-flex items-center gap-1 text-cdcf-navy hover:underline disabled:opacity-50"
        >
          <PaperClipIcon className="h-4 w-4" aria-hidden="true" />
          {t('attach.button')}
        </button>
        <button
          type="button"
          disabled={disabled}
          onClick={() => setPasting((p) => !p)}
          className="inline-flex items-center gap-1 text-cdcf-navy hover:underline disabled:opacity-50"
        >
          <DocumentTextIcon className="h-4 w-4" aria-hidden="true" />
          {t('attach.paste')}
        </button>
        <input
          ref={input}
          type="file"
          accept={ACCEPT}
          multiple
          hidden
          onChange={(e) => onFiles(e.target.files)}
        />
      </div>
      <p className="text-xs text-gray-500">{t('attach.hint')}</p>
      {rejected.map((r) => (
        <p key={r.name} className="text-xs text-red-700">
          {t('attach.failed', {
            name: r.name,
            error: t(`errors.${r.errorKey}`),
          })}
        </p>
      ))}
      {pasting && (
        <div className="space-y-2 rounded-md border border-gray-200 p-3">
          <label className="block text-xs font-medium uppercase tracking-wide text-gray-500">
            {t('attach.titleLabel')}
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm normal-case tracking-normal"
            />
          </label>
          <label className="block text-xs font-medium uppercase tracking-wide text-gray-500">
            {t('attach.markdownLabel')}
            <textarea
              value={markdown}
              onChange={(e) => setMarkdown(e.target.value)}
              rows={8}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm normal-case tracking-normal"
            />
          </label>
          <div className="flex gap-3 text-sm">
            <button
              type="button"
              onClick={addPaste}
              disabled={!markdown.trim()}
              className="cdcf-btn-secondary px-3 py-1.5 text-sm"
            >
              {t('attach.add')}
            </button>
            <button
              type="button"
              onClick={() => setPasting(false)}
              className="text-gray-600 hover:underline"
            >
              {t('cancel')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
