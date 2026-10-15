'use client'

import { useState } from 'react'
import { useRouter } from '@/src/i18n/navigation'
import { uploadAttachment, type ReviewResult } from '@/lib/review-client'
import type { PendingAttachment, ReviewEntry } from '@/lib/review-types'
import { pendingName, type ReviewErrorKey } from '@/lib/review-ui'

/**
 * Save an entry, then upload its pending attachments one by one (they can
 * only be attached once the entry exists), then refresh the server page.
 * An attachment failure does not undo the saved entry; it is reported.
 */
export function useEntrySubmit() {
  const router = useRouter()
  const [busy, setBusy] = useState(false)
  const [errorKey, setErrorKey] = useState<ReviewErrorKey | null>(null)
  const [failed, setFailed] = useState<
    { name: string; errorKey: ReviewErrorKey }[]
  >([])

  async function submit(
    save: () => Promise<ReviewResult<ReviewEntry>>,
    pending: PendingAttachment[]
  ): Promise<boolean> {
    setBusy(true)
    setErrorKey(null)
    setFailed([])
    const saved = await save()
    if (!saved.ok) {
      setErrorKey(saved.errorKey)
      setBusy(false)
      return false
    }
    const failures: { name: string; errorKey: ReviewErrorKey }[] = []
    for (const p of pending) {
      const result = await uploadAttachment(saved.data.id, p)
      if (!result.ok)
        failures.push({ name: pendingName(p), errorKey: result.errorKey })
    }
    setFailed(failures)
    setBusy(false)
    router.refresh()
    return true
  }

  return { busy, errorKey, failed, submit }
}
