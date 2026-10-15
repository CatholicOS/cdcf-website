'use client'

import { useTranslations } from 'next-intl'
import type { ReviewErrorKey } from '@/lib/review-ui'

export function ComposerStatus({
  errorKey,
  failed,
}: {
  errorKey: ReviewErrorKey | null
  failed: { name: string; errorKey: ReviewErrorKey }[]
}) {
  const t = useTranslations('Review')
  if (!errorKey && failed.length === 0) return null
  const needsReload = errorKey === 'stale' || errorKey === 'reviewExists'
  return (
    <div className="space-y-1 text-sm text-red-700" role="alert">
      {errorKey && (
        <p>
          {t(`errors.${errorKey}`)}{' '}
          {needsReload && (
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="font-medium underline"
            >
              {t('errors.reload')}
            </button>
          )}
        </p>
      )}
      {failed.map((f) => (
        <p key={f.name}>
          {t('attach.failed', {
            name: f.name,
            error: t(`errors.${f.errorKey}`),
          })}
        </p>
      ))}
    </div>
  )
}
