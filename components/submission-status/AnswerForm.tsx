'use client'

import { useId, useState, type FormEvent } from 'react'
import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import { useRouter } from '@/src/i18n/navigation'
import { answerErrorKey, type AnswerErrorKey } from '@/lib/submission-status-ui'

export function AnswerForm({
  projectId,
  questionId,
}: {
  projectId: number
  questionId: number
}) {
  const t = useTranslations('SubmissionStatus')
  const router = useRouter()
  const id = useId()
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<AnswerErrorKey | null>(null)

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await fetch(`/api/submission-status/${projectId}/answer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question_id: questionId, body }),
      })
      if (res.ok) {
        router.refresh()
        return
      }
      const data = (await res.json().catch(() => null)) as {
        error?: string
      } | null
      setError(answerErrorKey(data?.error))
    } catch {
      setError('generic')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-2">
      <label
        htmlFor={id}
        className="block text-xs font-medium uppercase tracking-wide text-gray-500"
      >
        {t('answerLabel')}
      </label>
      <textarea
        id={id}
        value={body}
        onChange={(e) => setBody(e.target.value)}
        rows={5}
        maxLength={10000}
        disabled={busy}
        className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
      />
      <button
        type="submit"
        disabled={busy || !body.trim()}
        className={clsx(
          'cdcf-btn-primary px-4 py-2 text-sm',
          (busy || !body.trim()) && 'cursor-not-allowed opacity-60'
        )}
      >
        {busy ? t('answerSending') : t('answerSubmit')}
      </button>
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {t(`errors.${error}`)}
        </p>
      )}
    </form>
  )
}
