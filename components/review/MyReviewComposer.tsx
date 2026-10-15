'use client'

import { useState, type FormEvent } from 'react'
import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import { jsonInit, reviewFetch } from '@/lib/review-client'
import {
  REVIEW_VOTES,
  type PendingAttachment,
  type ReviewEntry,
  type ReviewVote,
} from '@/lib/review-types'
import { AttachControl } from './AttachControl'
import { ComposerStatus } from './ComposerStatus'
import { MarkdownEditor } from './MarkdownEditor'
import { PendingList } from './PendingList'
import { useEntrySubmit } from './useEntrySubmit'

export function MyReviewComposer({
  projectId,
  existing,
}: {
  projectId: number
  existing: { vote: ReviewVote; body_md: string; version: string } | null
}) {
  const t = useTranslations('Review')
  const [editing, setEditing] = useState(existing === null)
  const [vote, setVote] = useState<ReviewVote | null>(existing?.vote ?? null)
  const [body, setBody] = useState(existing?.body_md ?? '')
  const [pending, setPending] = useState<PendingAttachment[]>([])
  const [voteMissing, setVoteMissing] = useState(false)
  const { busy, errorKey, failed, submit } = useEntrySubmit()

  if (!editing && existing) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
        <p className="text-sm text-gray-700">
          {t('yourVote', { vote: t(`vote.${existing.vote}`) })}
        </p>
        <button
          type="button"
          onClick={() => setEditing(true)}
          className="cdcf-btn-secondary px-3 py-1.5 text-sm"
        >
          {t('myReview.edit')}
        </button>
      </div>
    )
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (!vote) {
      setVoteMissing(true)
      return
    }
    setVoteMissing(false)
    const result = await submit(
      () =>
        reviewFetch<ReviewEntry>(
          `/api/review/projects/${projectId}/my-review`,
          jsonInit('PUT', {
            vote,
            body_md: body,
            ...(existing ? { version: existing.version } : {}),
          })
        ),
      pending
    )
    if (result.saved) {
      setPending([])
      // Stay in the form while an attachment failed, so the reason stays
      // visible; the author can re-attach to the saved review. A first
      // review closes too: the refresh supplies `existing` for the summary.
      if (result.failures.length === 0) setEditing(false)
    }
  }

  return (
    <form onSubmit={onSubmit} className="cdcf-card space-y-4">
      <h2 className="cdcf-heading text-xl">{t('myReview.heading')}</h2>
      <fieldset disabled={busy} className="space-y-2">
        <legend className="text-xs font-medium uppercase tracking-wide text-gray-500">
          {t('myReview.voteLabel')}
        </legend>
        <div className="flex flex-wrap gap-2">
          {REVIEW_VOTES.map((v) => (
            <label
              key={v}
              className={clsx(
                'cursor-pointer rounded-md border px-3 py-2 text-sm',
                vote === v
                  ? 'border-cdcf-navy bg-cdcf-navy text-white'
                  : 'border-gray-300 text-gray-700 hover:border-cdcf-navy'
              )}
            >
              <input
                type="radio"
                name="vote"
                value={v}
                checked={vote === v}
                onChange={() => setVote(v)}
                className="sr-only"
              />
              {t(`vote.${v}`)}
            </label>
          ))}
        </div>
        {voteMissing && (
          <p className="text-sm text-red-700">{t('myReview.voteRequired')}</p>
        )}
      </fieldset>
      <MarkdownEditor
        value={body}
        onChange={setBody}
        label={t('myReview.rationale')}
        rows={8}
        disabled={busy}
      />
      <PendingList
        pending={pending}
        onRemove={(key) => setPending((ps) => ps.filter((p) => p.key !== key))}
      />
      <AttachControl
        onAdd={(items) => setPending((ps) => [...ps, ...items])}
        disabled={busy}
      />
      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={busy || !body.trim()}
          className={clsx(
            'cdcf-btn-primary px-4 py-2 text-sm',
            (busy || !body.trim()) && 'cursor-not-allowed opacity-60'
          )}
        >
          {busy
            ? t('saving')
            : existing
              ? t('myReview.update')
              : t('myReview.submit')}
        </button>
        {existing && (
          <button
            type="button"
            onClick={() => setEditing(false)}
            className="text-sm text-gray-600 hover:underline"
          >
            {t('cancel')}
          </button>
        )}
      </div>
      <ComposerStatus errorKey={errorKey} failed={failed} />
    </form>
  )
}
