'use client'

import { useEffect, useId, useRef, useState, type FormEvent } from 'react'
import { useTranslations } from 'next-intl'

export function ResendLinkForm({ projectId }: { projectId: number }) {
  const t = useTranslations('SubmissionStatus')
  const id = useId()
  const [email, setEmail] = useState('')
  const [state, setState] = useState<'idle' | 'busy' | 'done'>('idle')
  const statusRef = useRef<HTMLParagraphElement>(null)

  // The form (and its focused button) goes away once sent: hand focus to
  // the confirmation so keyboard users are not dropped at the page top.
  useEffect(() => {
    if (state === 'done') statusRef.current?.focus()
  }, [state])

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setState('busy')
    try {
      await fetch('/api/submission-status/resend-link', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_id: projectId, email }),
      })
    } catch {
      // The answer is the same whatever happened.
    }
    setState('done')
  }

  return (
    <div className="space-y-2">
      <p
        ref={statusRef}
        role="status"
        tabIndex={-1}
        className="text-sm text-gray-700 focus:outline-none"
      >
        {state === 'done' ? t('resendDone') : null}
      </p>
      {state !== 'done' && (
        <form onSubmit={onSubmit} className="space-y-2">
          <p className="text-sm text-gray-700">{t('resendIntro')}</p>
          <label
            htmlFor={id}
            className="block text-xs font-medium uppercase tracking-wide text-gray-500"
          >
            {t('resendEmail')}
          </label>
          <input
            id={id}
            type="email"
            required
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
          />
          <button
            type="submit"
            disabled={state === 'busy'}
            className="cdcf-btn-primary px-4 py-2 text-sm"
          >
            {state === 'busy' ? t('resendSending') : t('resendSubmit')}
          </button>
        </form>
      )}
    </div>
  )
}
