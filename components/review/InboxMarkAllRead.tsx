'use client'

import { useState } from 'react'
import { useTranslations } from 'next-intl'
import { useRouter } from '@/src/i18n/navigation'
import { jsonInit, reviewFetch } from '@/lib/review-client'
import type { InboxCount } from '@/lib/review-types'

export function InboxMarkAllRead({ disabled }: { disabled: boolean }) {
  const t = useTranslations('Review.inbox')
  const router = useRouter()
  const [busy, setBusy] = useState(false)
  const markAll = async () => {
    setBusy(true)
    const result = await reviewFetch<InboxCount>(
      '/api/review/inbox/read',
      jsonInit('POST', { all: true })
    )
    setBusy(false)
    if (result.ok) router.refresh()
  }
  return (
    <button
      type="button"
      onClick={() => void markAll()}
      disabled={disabled || busy}
      className="text-sm text-cdcf-navy hover:underline disabled:text-gray-400 disabled:no-underline"
    >
      {t('markAllRead')}
    </button>
  )
}
