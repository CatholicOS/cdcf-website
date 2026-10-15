'use client'

import { useState, type FormEvent } from 'react'
import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import { jsonInit, reviewFetch } from '@/lib/review-client'
import type { PendingAttachment, ReviewEntry } from '@/lib/review-types'
import { AttachControl } from './AttachControl'
import { ComposerStatus } from './ComposerStatus'
import { ReviewEditor } from './ReviewEditor'
import { PendingList } from './PendingList'
import { useEntrySubmit } from './useEntrySubmit'

export function EntryComposer({
  projectId,
  kind,
  parentId,
}: {
  projectId: number
  kind: 'reply' | 'discussion'
  parentId?: number
}) {
  const t = useTranslations('Review')
  const [open, setOpen] = useState(kind === 'discussion')
  const [body, setBody] = useState('')
  const [pending, setPending] = useState<PendingAttachment[]>([])
  const { busy, errorKey, failed, submit } = useEntrySubmit()

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="text-sm font-medium text-cdcf-navy hover:underline"
      >
        {t('reviews.reply')}
      </button>
    )
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    const result = await submit(
      () =>
        reviewFetch<ReviewEntry>(
          `/api/review/projects/${projectId}/entries`,
          jsonInit('POST', {
            kind,
            body_md: body,
            ...(kind === 'reply' ? { parent_id: parentId } : {}),
          })
        ),
      pending
    )
    if (result.saved) {
      setBody('')
      setPending([])
      // Keep the composer open while an attachment failed, so the reason
      // stays visible; the author can re-attach to the saved post.
      if (kind === 'reply' && result.failures.length === 0) setOpen(false)
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-3">
      <ReviewEditor
        value={body}
        onChange={setBody}
        label={
          kind === 'reply' ? t('reviews.replyLabel') : t('discussion.label')
        }
        rows={kind === 'reply' ? 3 : 4}
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
            : kind === 'reply'
              ? t('reviews.postReply')
              : t('discussion.post')}
        </button>
        {kind === 'reply' && (
          <button
            type="button"
            onClick={() => setOpen(false)}
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
