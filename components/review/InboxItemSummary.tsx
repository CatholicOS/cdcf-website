import { useLocale, useTranslations } from 'next-intl'
import {
  inboxActorKey,
  inboxMessageKey,
  inboxTitle,
  relativeTime,
} from '@/lib/review-inbox'
import { parseWpGmt } from '@/lib/review-ui'
import type { InboxItem } from '@/lib/review-types'

/**
 * One notification's sentence and age. No 'use client': renders in the
 * inbox page (server) and inside the bell (client) alike.
 */
export function InboxItemSummary({
  item,
  now,
}: {
  item: InboxItem
  now: Date
}) {
  const t = useTranslations('Review.inbox')
  const locale = useLocale()
  const actorKey = inboxActorKey(item)
  const created = parseWpGmt(item.created_at)
  return (
    <span className="flex flex-col gap-0.5">
      <span
        className={item.read ? 'text-gray-600' : 'font-medium text-gray-900'}
      >
        {t(`event.${inboxMessageKey(item)}` as 'event.review_posted', {
          actor: actorKey ? t(actorKey) : (item.actor?.name ?? ''),
          title: inboxTitle(item),
        })}
      </span>
      <time dateTime={created.toISOString()} className="text-xs text-gray-500">
        {relativeTime(created, now, locale)}
      </time>
    </span>
  )
}
