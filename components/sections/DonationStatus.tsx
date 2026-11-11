'use client'

import type { ReactNode } from 'react'
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
 */
export default function DonationStatus({
  thankYouHtml,
  cancelledText,
  children,
}: DonationStatusProps) {
  const status = useSearchParams().get('status')

  if (status === 'success') {
    return (
      <div
        className="prose mx-auto"
        dangerouslySetInnerHTML={{ __html: thankYouHtml }}
      />
    )
  }

  return (
    <>
      {status === 'cancelled' && (
        <p className="mx-auto mb-6 max-w-lg text-sm text-gray-700">
          {cancelledText}
        </p>
      )}
      {children}
    </>
  )
}
