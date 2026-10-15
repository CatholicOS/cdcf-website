'use client'

import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'next/navigation'
import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import { XMarkIcon } from '@heroicons/react/24/outline'
import { usePathname, useRouter } from '@/src/i18n/navigation'
import type { ReviewAttachment } from '@/lib/review-types'
import {
  imageNeighbours,
  parseAttachmentParam,
  withAttachmentParam,
  type AttachmentIndex,
} from '@/lib/review-ui'
import { MarkdownView } from './MarkdownView'

/**
 * Side panel (45% width on desktop, full screen on mobile) for the
 * attachment named by ?att=<id>. The URL is the state, so a link opens
 * the same attachment and Back closes it.
 */
export function AttachmentPanel({ index }: { index: AttachmentIndex }) {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const id = parseAttachmentParam(searchParams.get('att'))
  const item = id !== null ? index[String(id)] : undefined

  // Stepping to another attachment pushes a history entry (Back returns
  // to the previous one); closing replaces, so the panel-less URL doesn't
  // stack up.
  const go = useCallback(
    (next: number | null) => {
      const href = `${pathname}${withAttachmentParam(searchParams.toString(), next)}`
      if (next === null) router.replace(href, { scroll: false })
      else router.push(href, { scroll: false })
    },
    [router, pathname, searchParams]
  )

  useEffect(() => {
    if (!item) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') go(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [item, go])

  if (!item) return null
  // Keyed by id so per-attachment state (text, raw toggle, zoom) resets.
  return (
    <AttachmentView
      key={item.attachment.id}
      attachment={item.attachment}
      siblings={item.siblings}
      go={go}
    />
  )
}

function AttachmentView({
  attachment,
  siblings,
  go,
}: {
  attachment: ReviewAttachment
  siblings: ReviewAttachment[]
  go: (id: number | null) => void
}) {
  const t = useTranslations('Review.panel')
  const [text, setText] = useState<string | null>(null)
  const [loadFailed, setLoadFailed] = useState(false)
  const [raw, setRaw] = useState(false)
  const [zoomed, setZoomed] = useState(false)
  const src = `/api/review/attachments/${attachment.id}`

  useEffect(() => {
    if (attachment.kind !== 'markdown') return
    const controller = new AbortController()
    fetch(src, { signal: controller.signal, cache: 'no-store' })
      .then((res) =>
        res.ok ? res.text() : Promise.reject(new Error(String(res.status)))
      )
      .then(setText)
      .catch(() => {
        if (!controller.signal.aborted) setLoadFailed(true)
      })
    return () => controller.abort()
  }, [attachment.kind, src])

  const { prev, next } = imageNeighbours(siblings, attachment.id)

  return (
    <aside
      aria-label={attachment.name}
      className="fixed inset-0 z-40 flex flex-col bg-white shadow-xl md:inset-y-0 md:left-auto md:right-0 md:w-[45vw] md:border-l md:border-gray-200"
    >
      <header className="flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3">
        <h2 className="truncate text-sm font-semibold text-gray-900">
          {attachment.name}
        </h2>
        <div className="flex shrink-0 items-center gap-3 text-sm">
          {attachment.kind === 'markdown' && (
            <button
              type="button"
              onClick={() => setRaw((r) => !r)}
              className="text-cdcf-navy hover:underline"
            >
              {raw ? t('rendered') : t('raw')}
            </button>
          )}
          <a
            href={`${src}?download=1`}
            className="text-cdcf-navy hover:underline"
          >
            {t('download')}
          </a>
          <button
            type="button"
            onClick={() => go(null)}
            aria-label={t('close')}
            className="rounded p-1 hover:bg-gray-100"
          >
            <XMarkIcon className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
      </header>
      <div className="flex-1 overflow-auto p-4">
        {attachment.kind === 'image' ? (
          <div className="flex h-full flex-col">
            <div
              className={clsx(
                'flex-1',
                zoomed ? 'overflow-auto' : 'flex items-center justify-center'
              )}
            >
              {/* eslint-disable-next-line @next/next/no-img-element -- authenticated same-origin stream; next/image would refetch it server-side without the session cookie */}
              <button
                type="button"
                aria-pressed={zoomed}
                onClick={() => setZoomed((z) => !z)}
                className={clsx(
                  zoomed
                    ? 'cursor-zoom-out'
                    : 'flex max-h-full max-w-full cursor-zoom-in'
                )}
              >
                <img
                  src={src}
                  alt={attachment.name}
                  className={clsx(
                    zoomed
                      ? 'max-w-none'
                      : 'max-h-full max-w-full object-contain'
                  )}
                />
              </button>
            </div>
            {(prev !== null || next !== null) && (
              <div className="mt-3 flex justify-between text-sm">
                <button
                  type="button"
                  disabled={prev === null}
                  onClick={() => prev !== null && go(prev)}
                  className="text-cdcf-navy hover:underline disabled:opacity-40"
                >
                  ← {t('previous')}
                </button>
                <button
                  type="button"
                  disabled={next === null}
                  onClick={() => next !== null && go(next)}
                  className="text-cdcf-navy hover:underline disabled:opacity-40"
                >
                  {t('next')} →
                </button>
              </div>
            )}
          </div>
        ) : loadFailed ? (
          <p className="text-sm text-red-700">{t('loadFailed')}</p>
        ) : text === null ? (
          <p className="text-sm text-gray-500">{t('loading')}</p>
        ) : raw ? (
          <pre className="whitespace-pre-wrap break-words font-mono text-xs">
            {text}
          </pre>
        ) : (
          <MarkdownView source={text} />
        )}
      </div>
    </aside>
  )
}
