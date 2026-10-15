'use client'

import { useSearchParams } from 'next/navigation'
import { useLocale } from 'next-intl'
import { DocumentTextIcon, PhotoIcon } from '@heroicons/react/24/outline'
import { usePathname, useRouter } from '@/src/i18n/navigation'
import type { ReviewAttachment } from '@/lib/review-types'
import { formatBytes, withAttachmentParam } from '@/lib/review-ui'

export function AttachmentChips({
  attachments,
}: {
  attachments: ReviewAttachment[]
}) {
  const locale = useLocale()
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  if (attachments.length === 0) return null
  return (
    <ul className="flex flex-wrap gap-2">
      {attachments.map((a) => {
        const Icon = a.kind === 'image' ? PhotoIcon : DocumentTextIcon
        return (
          <li key={a.id}>
            <button
              type="button"
              data-attachment-chip={a.id}
              onClick={() =>
                router.push(
                  `${pathname}${withAttachmentParam(searchParams.toString(), a.id)}`,
                  { scroll: false }
                )
              }
              className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs text-gray-700 hover:border-cdcf-navy hover:text-cdcf-navy"
            >
              <Icon className="h-4 w-4" aria-hidden="true" />
              {a.name}
              <span className="text-gray-400">
                {formatBytes(a.size, locale)}
              </span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}
