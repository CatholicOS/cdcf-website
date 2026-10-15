'use client'

import { useTranslations } from 'next-intl'
import { XMarkIcon } from '@heroicons/react/24/outline'
import type { PendingAttachment } from '@/lib/review-types'
import { pendingName } from '@/lib/review-ui'

export function PendingList({
  pending,
  onRemove,
}: {
  pending: PendingAttachment[]
  onRemove: (key: string) => void
}) {
  const t = useTranslations('Review')
  if (pending.length === 0) return null
  return (
    <ul className="flex flex-wrap gap-2">
      {pending.map((p) => {
        const name = pendingName(p)
        return (
          <li
            key={p.key}
            className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
          >
            {name}
            <button
              type="button"
              onClick={() => onRemove(p.key)}
              aria-label={t('attach.remove', { name })}
              className="rounded-full p-0.5 hover:bg-gray-200"
            >
              <XMarkIcon className="h-3 w-3" aria-hidden="true" />
            </button>
          </li>
        )
      })}
    </ul>
  )
}
