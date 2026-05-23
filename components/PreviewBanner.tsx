import { draftMode } from 'next/headers'
import { getTranslations } from 'next-intl/server'

/**
 * Shown site-wide while a preview (draft mode) session is active. Renders
 * nothing otherwise. The exit link hits the API route directly (not a
 * localized page), so a plain anchor is correct here.
 */
export default async function PreviewBanner() {
  const { isEnabled } = await draftMode()
  if (!isEnabled) return null

  const t = await getTranslations('preview')

  return (
    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-cdcf-gold px-4 py-2 text-center text-sm font-medium text-cdcf-navy">
      <span>{t('banner')}</span>
      {/* Full navigation to a route handler (clears draft mode), not a page —
          next/link would try to RSC-prefetch it. A plain anchor is correct. */}
      {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
      <a href="/api/preview/exit" className="underline hover:no-underline">
        {t('exit')}
      </a>
    </div>
  )
}
