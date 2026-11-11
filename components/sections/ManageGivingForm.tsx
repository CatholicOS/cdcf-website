'use client'

import { useState } from 'react'
import { useTranslations } from 'next-intl'

interface ManageGivingFormProps {
  locale: string
}

/**
 * "Manage your monthly gift": asks /api/donate/portal to email a Customer
 * Portal link. The confirmation is the same for every address, matching the
 * endpoint, which never says whether an address has donated.
 */
export default function ManageGivingForm({ locale }: ManageGivingFormProps) {
  const t = useTranslations('donate')
  const [email, setEmail] = useState('')
  const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>(
    'idle'
  )

  /** Ask the portal endpoint for a link; the answer is the same for everyone. */
  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setState('sending')
    try {
      const response = await fetch('/api/donate/portal', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, locale }),
      })
      setState(response.ok ? 'sent' : 'failed')
    } catch {
      setState('failed')
    }
  }

  return (
    <div className="mx-auto mt-12 max-w-lg border-t border-gray-200 pt-8">
      <h2 className="cdcf-heading text-xl">{t('manageHeading')}</h2>
      {state === 'sent' ? (
        <p className="mt-3 text-sm text-gray-700" role="status">
          {t('manageSent')}
        </p>
      ) : (
        <form onSubmit={handleSubmit} className="mt-3 space-y-3">
          <p className="text-sm text-gray-600">{t('manageIntro')}</p>
          <label className="block">
            <span className="mb-1 block text-sm font-medium">
              {t('manageEmailLabel')}
            </span>
            <input
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-md border border-gray-300 px-3 py-2"
            />
          </label>
          <button
            type="submit"
            disabled={state === 'sending'}
            className="cdcf-btn-primary disabled:opacity-60"
          >
            {t('manageSubmit')}
          </button>
          {state === 'failed' && (
            <p className="text-sm text-red-700" role="alert">
              {t('manageFailed')}
            </p>
          )}
        </form>
      )}
    </div>
  )
}
