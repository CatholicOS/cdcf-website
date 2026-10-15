'use client'

import { signIn } from 'next-auth/react'
import { useTranslations } from 'next-intl'

/**
 * Shown with "session expired": the header still shows the user as
 * signed in, so the message needs its own way back in.
 */
export function SignInAgain() {
  const t = useTranslations('Auth')
  return (
    <button
      type="button"
      onClick={() => signIn('zitadel')}
      className="font-medium underline"
    >
      {t('signIn')}
    </button>
  )
}
