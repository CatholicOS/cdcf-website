'use client'

import { useState } from 'react'
import { useRouter } from '@/src/i18n/navigation'
import { uploadAll, type ReviewResult } from '@/lib/review-client'
import type { PendingAttachment, ReviewEntry } from '@/lib/review-types'
import type { ReviewErrorKey } from '@/lib/review-ui'

export type UploadFailure = {
  pending: PendingAttachment
  errorKey: ReviewErrorKey
}

/**
 * Save an entry, then upload its pending attachments one by one (they can
 * only be attached once the entry exists), then refresh the server page.
 * An attachment failure does not undo the saved entry; it is returned so
 * the composer can stay open and show why.
 */
export function useEntrySubmit() {
  const router = useRouter()
  const [busy, setBusy] = useState(false)
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null)
  const [failed, setFailed] = useState<UploadFailure[]>([])

  async function submit(
    save: () => Promise<ReviewResult<ReviewEntry>>,
    pending: PendingAttachment[]
  ): Promise<{ saved: boolean; failures: UploadFailure[] }> {
    setBusy(true)
    setErrorKey(null)
    setFailed([])
    const saved = await save()
    if (!saved.ok) {
      setErrorKey(saved.errorKey)
      setBusy(false)
      return { saved: false, failures: [] }
    }
    const failures = await uploadAll(saved.data.id, pending)
    setFailed(failures)
    setBusy(false)
    router.refresh()
    return { saved: true, failures }
  }

  return { busy, errorKey, failed, submit }
}
