'use client'

import { useEffect, useRef, type ReactNode } from 'react'
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
  const messageRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const message = messageRef.current
    if (!message) return
    const reduceMotion = window.matchMedia?.(
      '(prefers-reduced-motion: reduce)'
    ).matches
    message.scrollIntoView({
      behavior: reduceMotion ? 'auto' : 'smooth',
      block: 'start',
    })
    message.focus({ preventScroll: true })
  }, [status])

  // scroll-mt clears the sticky header; tabIndex lets the message take
  // focus without joining the tab order.
  const messageProps = {
    ref: messageRef,
    tabIndex: -1,
    'data-donation-status': '',
    className: 'scroll-mt-24 outline-none',
  }

  if (status === 'success') {
    return (
      <div {...messageProps}>
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
        <div {...messageProps}>
          <p className="mx-auto mb-6 max-w-lg text-sm text-gray-700">
            {cancelledText}
          </p>
        </div>
      )}
      {children}
    </>
  )
}
