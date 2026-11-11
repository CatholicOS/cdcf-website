'use client'

import { useCallback, type ReactNode } from 'react'
import { useSearchParams } from 'next/navigation'

interface DonationStatusProps {
  thankYouHtml: string
  cancelledText: string
  children: ReactNode
}

/**
 * Reads `?status=` from the URL Stripe returns the donor to. It is read here,
 * in the browser, rather than from the page route's searchParams: awaiting
 * searchParams there would make every CMS page render per request instead
 * of from the cache. The redirect is only a UI cue; the webhook is what
 * acknowledges the gift.
 *
 * A returning donor lands on the page under its hero, so the message is
 * scrolled into view and focused (which also makes screen readers announce
 * it). The scroll is smooth unless the visitor prefers reduced motion. A
 * plain visit is never scrolled.
 */
export default function DonationStatus({
  thankYouHtml,
  cancelledText,
  children,
}: DonationStatusProps) {
  const status = useSearchParams().get('status')
  // A callback ref runs when a message element mounts, which is exactly
  // when there is something to bring into view: no effect needed.
  const bringIntoView = useCallback((message: HTMLDivElement | null) => {
    if (!message) return
    const reduceMotion = window.matchMedia?.(
      '(prefers-reduced-motion: reduce)'
    ).matches
    message.scrollIntoView({
      behavior: reduceMotion ? 'auto' : 'smooth',
      block: 'start',
    })
    message.focus({ preventScroll: true })
  }, [])

  // scroll-mt clears the sticky header; tabIndex lets the message take
  // focus without joining the tab order.
  if (status === 'success') {
    return (
      <div
        ref={bringIntoView}
        tabIndex={-1}
        data-donation-status=""
        className="scroll-mt-24 outline-none"
      >
        <div
          className="prose mx-auto"
          dangerouslySetInnerHTML={{ __html: thankYouHtml }}
        />
      </div>
    )
  }

  return (
    <>
      {status === 'cancelled' && (
        <div
          ref={bringIntoView}
          tabIndex={-1}
          data-donation-status=""
          className="scroll-mt-24 outline-none"
        >
          <p className="mx-auto mb-6 max-w-lg text-sm text-gray-700">
            {cancelledText}
          </p>
        </div>
      )}
      {children}
    </>
  )
}
