'use client'

import { useId, useState, type FormEvent } from 'react'
import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import { jsonInit, reviewFetch } from '@/lib/review-client'
import type { ReviewEntry } from '@/lib/review-types'
import type { ReviewErrorKey } from '@/lib/review-ui'

import { useRouter } from '@/src/i18n/navigation'
import { ComposerStatus } from './ComposerStatus'

/** Plain text: the submitter receives it by email exactly as written. */
export function AskSubmitter({ projectId }: { projectId: number }) {
  const t = useTranslations('Review')
  const router = useRouter()
  const id = useId()
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null)
  const [emailFailed, setEmailFailed] = useState(false)

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErrorKey(null)
    setEmailFailed(false)
    const result = await reviewFetch<ReviewEntry & { warnings: string[] }>(
      `/api/review/projects/${projectId}/question`,
      jsonInit('POST', { body })
    )
    setBusy(false)
    if (!result.ok) {
      setErrorKey(result.errorKey)
      return
    }
    setBody('')
    setEmailFailed(result.data.warnings.includes('email_failed'))
    router.refresh()
  }

  return (
    <form onSubmit={onSubmit} className="cdcf-card space-y-3">
      <h3 className="cdcf-heading text-lg">{t('questions.askHeading')}</h3>
      <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
        {t('questions.askWarning')}
      </p>
      <label
        htmlFor={id}
        className="block text-xs font-medium uppercase tracking-wide text-gray-500"
      >
        {t('questions.askLabel')}
      </label>
      <textarea
        id={id}
        value={body}
        onChange={(e) => setBody(e.target.value)}
        rows={4}
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
        {busy ? t('questions.askSending') : t('questions.askSubmit')}
      </button>
      <ComposerStatus errorKey={errorKey} failed={[]} />
      {emailFailed && (
        <p role="status" className="text-sm text-amber-800">
          {t('questions.emailFailed')}
        </p>
      )}
    </form>
  )
}
