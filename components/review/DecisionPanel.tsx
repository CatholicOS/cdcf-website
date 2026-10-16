'use client'

import { useId, useState, type FormEvent } from 'react'
import clsx from 'clsx'
import { useLocale, useTranslations } from 'next-intl'
import { jsonInit, reviewFetch } from '@/lib/review-client'
import {
  DECISION_SUMMARY_MAX,
  REVIEW_VOTES,
  type ReviewDecisionEmail,
  type ReviewDecisionPayload,
  type ReviewDecisionResult,
  type ReviewVote,
} from '@/lib/review-types'
import type { ReviewErrorKey } from '@/lib/review-ui'
import { useRouter } from '@/src/i18n/navigation'
import { ComposerStatus } from './ComposerStatus'

const textareaClass =
  'block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy'
const labelClass =
  'block text-xs font-medium uppercase tracking-wide text-gray-500'

function languageName(locale: string, code: string): string {
  try {
    return (
      new Intl.DisplayNames([locale], { type: 'language' }).of(code) ?? code
    )
  } catch {
    return code
  }
}

/**
 * Board members record the final decision here. The decision is final and
 * the submitter is emailed, so the button asks for a second click and the
 * email is previewed exactly as WordPress will fill it in.
 */
export function DecisionPanel({
  projectId,
  plurality,
  email,
}: {
  projectId: number
  plurality: ReviewVote | null
  email: ReviewDecisionEmail
}) {
  const t = useTranslations('Review')
  const locale = useLocale()
  const router = useRouter()
  const id = useId()
  const [selected, setSelected] = useState<ReviewVote | null>(plurality)
  const [rationale, setRationale] = useState('')
  const [summary, setSummary] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null)
  const [emailFailed, setEmailFailed] = useState(false)

  const rationaleNeeded = selected === null || selected !== plurality
  const valid =
    selected !== null &&
    summary.trim() !== '' &&
    (!rationaleNeeded || rationale.trim() !== '')

  const preview = `${email.subject}\n\n${email.body}`
    .replaceAll('{outcome}', selected ? email.outcomes[selected] : '…')
    .replaceAll('{summary}', summary.trim() || t('decision.summaryPlaceholder'))
    .replaceAll('{link}', t('decision.linkPlaceholder'))

  function edited<T>(set: (v: T) => void) {
    return (v: T) => {
      set(v)
      setConfirming(false)
    }
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (!valid || busy || selected === null) return
    if (!confirming) {
      setConfirming(true)
      return
    }
    setBusy(true)
    setErrorKey(null)
    setEmailFailed(false)
    const payload: ReviewDecisionPayload = {
      decision: selected,
      rationale,
      submitter_summary: summary,
    }
    const result = await reviewFetch<ReviewDecisionResult>(
      `/api/review/projects/${projectId}/decision`,
      jsonInit('POST', payload)
    )
    setBusy(false)
    setConfirming(false)
    if (!result.ok) {
      setErrorKey(result.errorKey)
      return
    }
    setEmailFailed(result.data.warnings?.includes('email_failed') ?? false)
    router.refresh()
  }

  const disabled = busy || !valid

  return (
    <form onSubmit={onSubmit} className="cdcf-card space-y-4">
      <h2 className="cdcf-heading text-xl">{t('decision.panelHeading')}</h2>
      <p className="text-sm text-gray-700">
        {plurality
          ? t('decision.plurality', { vote: t(`vote.${plurality}`) })
          : t('decision.noPlurality')}
      </p>

      <fieldset className="space-y-1" disabled={busy}>
        <legend className={labelClass}>{t('decision.outcomeLabel')}</legend>
        {REVIEW_VOTES.map((v) => (
          <label key={v} className="flex items-center gap-2 text-sm">
            <input
              type="radio"
              name={`${id}-outcome`}
              value={v}
              checked={selected === v}
              onChange={() => edited(setSelected)(v)}
            />
            {t(`vote.${v}`)}
          </label>
        ))}
      </fieldset>

      <div className="space-y-1">
        <label htmlFor={`${id}-rationale`} className={labelClass}>
          {t('decision.rationaleLabel')} (
          {rationaleNeeded
            ? t('decision.rationaleRequired')
            : t('decision.rationaleOptional')}
          )
        </label>
        <textarea
          id={`${id}-rationale`}
          maxLength={50000}
          rows={4}
          value={rationale}
          onChange={(e) => edited(setRationale)(e.target.value)}
          disabled={busy}
          className={textareaClass}
        />
      </div>

      <div className="space-y-1">
        <label htmlFor={`${id}-summary`} className={labelClass}>
          {t('decision.summaryLabel', {
            language: languageName(locale, email.language),
          })}
        </label>
        <textarea
          id={`${id}-summary`}
          maxLength={DECISION_SUMMARY_MAX}
          rows={5}
          required
          value={summary}
          onChange={(e) => edited(setSummary)(e.target.value)}
          disabled={busy}
          className={textareaClass}
        />
      </div>

      <details open className="rounded-md border border-gray-200 p-3">
        <summary className="cursor-pointer text-sm font-medium">
          {t('decision.preview')}
        </summary>
        <pre className="mt-2 whitespace-pre-wrap font-sans text-sm text-gray-700">
          {preview}
        </pre>
      </details>

      <button
        type="submit"
        disabled={disabled}
        className={clsx(
          'cdcf-btn-primary px-4 py-2 text-sm',
          disabled && 'cursor-not-allowed opacity-60'
        )}
      >
        {busy
          ? t('decision.submitting')
          : confirming
            ? t('decision.confirm')
            : t('decision.submit')}
      </button>
      <ComposerStatus errorKey={errorKey} failed={[]} />
      {emailFailed && (
        <p role="status" className="text-sm text-amber-800">
          {t('decision.emailFailed')}
        </p>
      )}
    </form>
  )
}
