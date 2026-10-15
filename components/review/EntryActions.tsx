'use client'

import { useState } from 'react'
import { useTranslations } from 'next-intl'
import { useRouter } from '@/src/i18n/navigation'
import { jsonInit, reviewFetch, uploadAttachment } from '@/lib/review-client'
import type { PendingAttachment, ReviewEntry } from '@/lib/review-types'
import { pendingName, type ReviewErrorKey } from '@/lib/review-ui'
import { AttachControl } from './AttachControl'
import { ComposerStatus } from './ComposerStatus'
import { MarkdownEditor } from './MarkdownEditor'

/**
 * Author-only controls on an existing entry: inline edit (replies and
 * discussion posts; reviews are edited through MyReviewComposer) and
 * attaching more files.
 */
export function EntryActions({
  entryId,
  body,
  version,
  allowEdit,
}: {
  entryId: number
  body: string
  version: string
  allowEdit: boolean
}) {
  const t = useTranslations('Review')
  const router = useRouter()
  const [editing, setEditing] = useState(false)
  const [attaching, setAttaching] = useState(false)
  const [draft, setDraft] = useState(body)
  const [busy, setBusy] = useState(false)
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null)
  const [failed, setFailed] = useState<
    { name: string; errorKey: ReviewErrorKey }[]
  >([])

  async function save() {
    setBusy(true)
    setErrorKey(null)
    const result = await reviewFetch<ReviewEntry>(
      `/api/review/entries/${entryId}`,
      jsonInit('PATCH', { body_md: draft, version })
    )
    setBusy(false)
    if (!result.ok) {
      setErrorKey(result.errorKey)
      return
    }
    setEditing(false)
    router.refresh()
  }

  async function attach(p: PendingAttachment) {
    setBusy(true)
    const result = await uploadAttachment(entryId, p)
    setBusy(false)
    setFailed(
      result.ok ? [] : [{ name: pendingName(p), errorKey: result.errorKey }]
    )
    if (result.ok) router.refresh()
  }

  if (editing) {
    return (
      <div className="space-y-2">
        <MarkdownEditor
          value={draft}
          onChange={setDraft}
          label={t('edit')}
          rows={4}
          disabled={busy}
        />
        <div className="flex gap-3 text-sm">
          <button
            type="button"
            onClick={save}
            disabled={busy || !draft.trim()}
            className="cdcf-btn-primary px-3 py-1.5 text-sm"
          >
            {busy ? t('saving') : t('save')}
          </button>
          <button
            type="button"
            onClick={() => setEditing(false)}
            className="text-gray-600 hover:underline"
          >
            {t('cancel')}
          </button>
        </div>
        <ComposerStatus errorKey={errorKey} failed={[]} />
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <div className="flex gap-3 text-xs">
        {allowEdit && (
          <button
            type="button"
            onClick={() => setEditing(true)}
            className="text-cdcf-navy hover:underline"
          >
            {t('edit')}
          </button>
        )}
        <button
          type="button"
          onClick={() => setAttaching((a) => !a)}
          className="text-cdcf-navy hover:underline"
        >
          {t('attach.button')}
        </button>
      </div>
      {attaching && <AttachControl onAdd={attach} disabled={busy} />}
      <ComposerStatus errorKey={null} failed={failed} />
    </div>
  )
}
