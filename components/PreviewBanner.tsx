import { draftMode } from 'next/headers'

/**
 * Shown site-wide while a preview (draft mode) session is active. Renders
 * nothing otherwise. The exit link hits the API route directly (not a
 * localized page), so a plain anchor is correct here.
 */
export default async function PreviewBanner() {
  const { isEnabled } = await draftMode()
  if (!isEnabled) return null

  return (
    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-cdcf-gold px-4 py-2 text-center text-sm font-medium text-cdcf-navy">
      <span>Preview mode — showing unpublished draft content.</span>
      {/* Full navigation to a route handler (clears draft mode), not a page —
          next/link would try to RSC-prefetch it. A plain anchor is correct. */}
      {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
      <a href="/api/preview/exit" className="underline hover:no-underline">
        Exit preview
      </a>
    </div>
  )
}
