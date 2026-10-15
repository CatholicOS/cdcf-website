'use client'

import { useTranslations } from 'next-intl'
import { pendingName, type ReviewErrorKey } from '@/lib/review-ui'
import { SignInAgain } from './SignInAgain'
import type { UploadFailure } from './useEntrySubmit'

export function ComposerStatus({
  errorKey,
  failed,
}: {
  errorKey: ReviewErrorKey | null
  failed: UploadFailure[]
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
          {errorKey === 'sessionExpired' && <SignInAgain />}
        </p>
      )}
      {failed.map((f) => (
        <p key={f.pending.key}>
          {t('attach.failed', {
            name: pendingName(f.pending),
            error: t(`errors.${f.errorKey}`),
          })}
        </p>
      ))}
    </div>
  )
}
