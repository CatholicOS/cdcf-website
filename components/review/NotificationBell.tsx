'use client'

import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslations } from 'next-intl'
import { BellIcon } from '@heroicons/react/24/outline'
import { Link, usePathname, useRouter } from '@/src/i18n/navigation'
import { jsonInit, reviewFetch } from '@/lib/review-client'
import { INBOX_CHANGED_EVENT, inboxHref } from '@/lib/review-inbox'
import type { InboxCount, InboxItem, InboxPage } from '@/lib/review-types'
import { InboxItemSummary } from './InboxItemSummary'

// Just under a minute: WordPress caches a verified bearer token for 60 s,
// so a poll inside that window skips the identity provider round-trip.
export const BELL_POLL_MS = 55_000
export const BELL_ITEMS = 10

const isCount = (d: unknown): d is InboxCount =>
  !!d && typeof (d as InboxCount).unread === 'number'
const isPage = (d: unknown): d is InboxPage =>
  isCount(d) && Array.isArray((d as InboxPage).items)

/**
 * Header bell for council and Board members. Polls the unread count about
 * once a minute while the tab is visible, and again on navigation (opening
 * a workspace marks its notifications read) or when the inbox page marks
 * them read. The dropdown shows the latest notifications. Every failure
 * keeps the last known state.
 */
export function NotificationBell() {
  const t = useTranslations('Review.inbox')
  const router = useRouter()
  const pathname = usePathname()
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<InboxItem[] | null>(null)
  const [failed, setFailed] = useState(false)
  const root = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)
  const panel = useRef<HTMLElement>(null)
  const request = useRef(0)
  const panelId = useId()

  const refreshCount = useCallback(async () => {
    const result = await reviewFetch<InboxCount>('/api/review/inbox/count')
    if (result.ok && isCount(result.data)) setUnread(result.data.unread)
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

  // Only when the path changes: the polling effect fetched the first count.
  const lastPath = useRef(pathname)
  useEffect(() => {
    if (lastPath.current === pathname) return
    lastPath.current = pathname
    void refreshCount()
  }, [pathname, refreshCount])

  useEffect(() => {
    const onChanged = () => void refreshCount()
    window.addEventListener(INBOX_CHANGED_EVENT, onChanged)
    return () => window.removeEventListener(INBOX_CHANGED_EVENT, onChanged)
  }, [refreshCount])

  /** Close the panel; a list request still in flight is then ignored. */
  const close = useCallback((returnFocus = false) => {
    request.current++
    setOpen(false)
    if (returnFocus) button.current?.focus()
  }, [])

  useEffect(() => {
    if (open) panel.current?.focus()
  }, [open])

  useEffect(() => {
    if (!open) return
    const onPointer = (e: MouseEvent) => {
      if (root.current && !root.current.contains(e.target as Node)) close()
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') close(true)
    }
    // Tabbing (or clicking) out of the bell and its panel closes it.
    const onFocusOut = (e: FocusEvent) => {
      if (!root.current?.contains(e.relatedTarget as Node | null)) close()
    }
    const el = root.current
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    el?.addEventListener('focusout', onFocusOut)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
      el?.removeEventListener('focusout', onFocusOut)
    }
  }, [open, close])

  const toggle = async () => {
    if (open) return close()
    const mine = ++request.current
    setOpen(true)
    setFailed(false)
    const result = await reviewFetch<InboxPage>('/api/review/inbox')
    if (mine !== request.current) return
    if (result.ok && isPage(result.data)) {
      setItems(result.data.items.slice(0, BELL_ITEMS))
      setUnread(result.data.unread)
    } else {
      setFailed(true)
    }
  }

  const follow = async (item: InboxItem) => {
    close()
    router.push(inboxHref(item))
    if (!item.read) {
      const result = await reviewFetch<InboxCount>(
        '/api/review/inbox/read',
        jsonInit('POST', { event_ids: [item.event_id] })
      )
      if (result.ok && isCount(result.data)) setUnread(result.data.unread)
    }
  }

  const markAll = async () => {
    const result = await reviewFetch<InboxCount>(
      '/api/review/inbox/read',
      jsonInit('POST', { all: true })
    )
    if (!result.ok || !isCount(result.data)) return
    setUnread(result.data.unread)
    setItems((prev) => prev?.map((i) => ({ ...i, read: true })) ?? prev)
  }

  const now = new Date()
  return (
    <div ref={root} className="relative">
      <button
        ref={button}
        type="button"
        aria-controls={open ? panelId : undefined}
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
        // Below sm the bell sits left of the account and menu buttons, so a
        // panel anchored to it would run off screen: span the viewport
        // under the 4rem header instead.
        <section
          ref={panel}
          id={panelId}
          tabIndex={-1}
          aria-label={t('title')}
          className="fixed inset-x-4 top-16 z-50 mt-1 rounded-md border border-gray-200 bg-white shadow-lg focus:outline-none sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:w-80"
        >
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
                    {!item.read && (
                      <span className="sr-only">{t('unreadMarker')}</span>
                    )}
                    <InboxItemSummary item={item} now={now} />
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="border-t border-gray-100 px-4 py-2 text-right">
            <Link
              href="/review/inbox"
              onClick={() => close()}
              className="text-sm text-cdcf-navy hover:underline"
            >
              {t('viewAll')}
            </Link>
          </div>
        </section>
      )}
    </div>
  )
}
