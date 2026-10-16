'use client'

import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslations } from 'next-intl'
import { BellIcon } from '@heroicons/react/24/outline'
import { Link, useRouter } from '@/src/i18n/navigation'
import { jsonInit, reviewFetch } from '@/lib/review-client'
import { inboxHref } from '@/lib/review-inbox'
import type { InboxCount, InboxItem, InboxPage } from '@/lib/review-types'
import { InboxItemSummary } from './InboxItemSummary'

export const BELL_POLL_MS = 60_000
export const BELL_ITEMS = 10

/**
 * Header bell for council and Board members. Polls the unread count every
 * minute while the tab is visible; the dropdown shows the latest
 * notifications. Every failure keeps the last known state.
 */
export function NotificationBell() {
  const t = useTranslations('Review.inbox')
  const router = useRouter()
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<InboxItem[] | null>(null)
  const [failed, setFailed] = useState(false)
  const root = useRef<HTMLDivElement>(null)

  const refreshCount = useCallback(async () => {
    const result = await reviewFetch<InboxCount>('/api/review/inbox/count')
    if (result.ok) setUnread(result.data.unread)
  }, [])

  useEffect(() => {
    let timer: ReturnType<typeof setInterval> | null = null
    const stop = () => {
      if (timer) clearInterval(timer)
      timer = null
    }
    const sync = () => {
      if (document.visibilityState !== 'visible') return stop()
      if (timer) return
      void refreshCount()
      timer = setInterval(() => void refreshCount(), BELL_POLL_MS)
    }
    sync()
    document.addEventListener('visibilitychange', sync)
    return () => {
      stop()
      document.removeEventListener('visibilitychange', sync)
    }
  }, [refreshCount])

  useEffect(() => {
    if (!open) return
    const onPointer = (e: MouseEvent) => {
      if (root.current && !root.current.contains(e.target as Node))
        setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const toggle = async () => {
    if (open) return setOpen(false)
    setOpen(true)
    setFailed(false)
    const result = await reviewFetch<InboxPage>('/api/review/inbox')
    if (result.ok) {
      setItems(result.data.items.slice(0, BELL_ITEMS))
      setUnread(result.data.unread)
    } else {
      setFailed(true)
    }
  }

  const follow = async (item: InboxItem) => {
    setOpen(false)
    if (!item.read) {
      const result = await reviewFetch<InboxCount>(
        '/api/review/inbox/read',
        jsonInit('POST', { event_ids: [item.event_id] })
      )
      if (result.ok) setUnread(result.data.unread)
    }
    router.push(inboxHref(item))
  }

  const markAll = async () => {
    const result = await reviewFetch<InboxCount>(
      '/api/review/inbox/read',
      jsonInit('POST', { all: true })
    )
    if (!result.ok) return
    setUnread(result.data.unread)
    setItems((prev) => prev?.map((i) => ({ ...i, read: true })) ?? prev)
  }

  const now = new Date()
  return (
    <div ref={root} className="relative">
      <button
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={unread > 0 ? t('bellUnread', { count: unread }) : t('bell')}
        onClick={() => void toggle()}
        className="relative inline-flex items-center rounded-md p-2 text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
      >
        <BellIcon className="h-5 w-5" aria-hidden="true" />
        {unread > 0 && (
          <span
            aria-hidden="true"
            className="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] font-semibold leading-4 text-white"
          >
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>
      {open && (
        <div className="absolute right-0 top-full z-50 mt-1 w-80 max-w-[calc(100vw-2rem)] rounded-md border border-gray-200 bg-white shadow-lg">
          <div className="flex items-center justify-between border-b border-gray-100 px-4 py-2">
            <span className="text-sm font-semibold text-gray-900">
              {t('title')}
            </span>
            <button
              type="button"
              onClick={() => void markAll()}
              disabled={unread === 0}
              className="text-xs text-cdcf-navy hover:underline disabled:text-gray-400 disabled:no-underline"
            >
              {t('markAllRead')}
            </button>
          </div>
          {failed ? (
            <p className="px-4 py-3 text-sm text-red-700">{t('loadFailed')}</p>
          ) : items === null ? (
            <p className="px-4 py-3 text-sm text-gray-500">{t('loading')}</p>
          ) : items.length === 0 ? (
            <p className="px-4 py-3 text-sm text-gray-500">{t('empty')}</p>
          ) : (
            <ul className="max-h-96 divide-y divide-gray-100 overflow-y-auto">
              {items.map((item) => (
                <li key={item.event_id}>
                  <button
                    type="button"
                    onClick={() => void follow(item)}
                    className="flex w-full items-start gap-2 px-4 py-2 text-left text-sm hover:bg-gray-50"
                  >
                    <span
                      aria-hidden="true"
                      className={
                        item.read
                          ? 'mt-1.5 h-2 w-2 shrink-0'
                          : 'mt-1.5 h-2 w-2 shrink-0 rounded-full bg-cdcf-navy'
                      }
                    />
                    <InboxItemSummary item={item} now={now} />
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="border-t border-gray-100 px-4 py-2 text-right">
            <Link
              href="/review/inbox"
              onClick={() => setOpen(false)}
              className="text-sm text-cdcf-navy hover:underline"
            >
              {t('viewAll')}
            </Link>
          </div>
        </div>
      )}
    </div>
  )
}
