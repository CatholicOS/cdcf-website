/**
 * Pure helpers for the notification bell and the inbox page.
 */
import type { InboxItem } from '@/lib/review-types'

/** The workspace, at the entry when the event has one. */
export function inboxHref(item: InboxItem): string {
  return `/review/${item.project.id}${item.entry_id ? `#entry-${item.entry_id}` : ''}`
}

/** The message key under Review.inbox.event. */
export function inboxMessageKey(item: InboxItem): string {
  if (item.type === 'reply_posted' && item.to_you) return 'reply_posted_to_you'
  if (item.type === 'admin_override' && item.data.backfill === true)
    return 'admin_override_backfill'
  return item.type
}

/** The Review.inbox key that names an actor without an account name. */
export function inboxActorKey(item: InboxItem): 'submitter' | 'someone' | null {
  if (item.actor) return null
  return item.type === 'submitter_answered' ||
    item.type === 'submission_received'
    ? 'submitter'
    : 'someone'
}

export function inboxTitle(item: InboxItem): string {
  return item.project.title || '—'
}

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['year', 31536000],
  ['month', 2592000],
  ['week', 604800],
  ['day', 86400],
  ['hour', 3600],
  ['minute', 60],
]

export function relativeTime(date: Date, now: Date, locale: string): string {
  const seconds = Math.round((date.getTime() - now.getTime()) / 1000)
  const rtf = new Intl.RelativeTimeFormat(locale, {
    numeric: 'auto',
    style: 'short',
  })
  for (const [unit, size] of UNITS) {
    if (Math.abs(seconds) >= size)
      return rtf.format(Math.round(seconds / size), unit)
  }
  return rtf.format(0, 'second')
}
