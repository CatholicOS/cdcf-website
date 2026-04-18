'use client'

import { useState, useRef, useCallback } from 'react'
import { useTranslations } from 'next-intl'

interface ReferLocalGroupModalProps {
  buttonLabel: string
}

type Status = 'idle' | 'sending_code' | 'awaiting_code' | 'submitting' | 'success' | 'error'

export default function ReferLocalGroupModal({ buttonLabel }: ReferLocalGroupModalProps) {
  const t = useTranslations('community')
  const dialogRef = useRef<HTMLDialogElement>(null)
  const openedAtRef = useRef<number>(0)
  const [formData, setFormData] = useState<Record<string, string>>({})
  const [status, setStatus] = useState<Status>('idle')
  const [verificationCode, setVerificationCode] = useState('')
  const [codeError, setCodeError] = useState('')

  const openDialog = useCallback(() => {
    setStatus('idle')
    setVerificationCode('')
    setCodeError('')
    setFormData({})
    openedAtRef.current = Date.now()
    dialogRef.current?.showModal()
  }, [])

  const closeDialog = useCallback(() => {
    dialogRef.current?.close()
  }, [])

  async function handleSendCode(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setStatus('sending_code')

    const form = e.currentTarget
    const data = new FormData(form)

    const payload = {
      group_name: data.get('group_name') as string,
      location: data.get('location') as string,
      url: data.get('url') as string,
      description: data.get('description') as string,
      submitter_name: data.get('submitter_name') as string,
      submitter_email: data.get('submitter_email') as string,
      website: data.get('website') as string,
      elapsed_ms: Date.now() - openedAtRef.current,
    }

    // Store form data for the final submission
    setFormData({
      group_name: payload.group_name,
      location: payload.location,
      url: payload.url,
      description: payload.description,
      submitter_name: payload.submitter_name,
      submitter_email: payload.submitter_email,
      website: payload.website,
    })

    try {
      const res = await fetch('/api/refer-local-group/send-code', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })

      if (res.ok) {
        setStatus('awaiting_code')
        setCodeError('')
      } else {
        setStatus('error')
      }
    } catch {
      setStatus('error')
    }
  }

  async function handleVerifyAndSubmit() {
    setStatus('submitting')
    setCodeError('')

    try {
      const res = await fetch('/api/refer-local-group', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ...formData,
          verification_code: verificationCode,
          elapsed_ms: Date.now() - openedAtRef.current,
        }),
      })

      if (res.ok) {
        setStatus('success')
      } else {
        const err = await res.json().catch(() => ({}))
        const msg = err.error || ''
        if (msg.includes('expired')) {
          setCodeError(t('codeExpired'))
        } else if (msg.includes('many')) {
          setCodeError(t('tooManyAttempts'))
        } else if (msg.includes('Invalid') || msg.includes('invalid')) {
          setCodeError(t('invalidCode'))
        } else {
          setCodeError(msg || t('errorMessage'))
        }
        setStatus('awaiting_code')
      }
    } catch {
      setCodeError(t('errorMessage'))
      setStatus('awaiting_code')
    }
  }

  async function handleResendCode() {
    setStatus('sending_code')
    setCodeError('')
    setVerificationCode('')

    try {
      const res = await fetch('/api/refer-local-group/send-code', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ...formData,
          elapsed_ms: Date.now() - openedAtRef.current,
        }),
      })

      if (res.ok) {
        setStatus('awaiting_code')
      } else {
        setStatus('error')
      }
    } catch {
      setStatus('error')
    }
  }

  function handleBackToForm() {
    setStatus('idle')
    setVerificationCode('')
    setCodeError('')
  }

  const isCodeView = status === 'awaiting_code' || status === 'submitting'

  return (
    <>
      <button
        onClick={openDialog}
        className="cdcf-btn-primary inline-flex items-center gap-2 rounded-lg px-6 py-3"
      >
        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        {buttonLabel}
      </button>

      <dialog
        ref={dialogRef}
        className="mx-auto max-h-[85vh] w-full max-w-lg rounded-xl border-0 p-0 shadow-2xl backdrop:bg-black/50 open:flex open:flex-col"
      >
        <div className="flex shrink-0 items-center justify-between border-b bg-cdcf-navy px-6 py-4">
          <h2 className="font-serif text-xl font-bold text-white">
            {t('referTitle')}
          </h2>
          <button
            onClick={closeDialog}
            className="rounded-full p-1 text-white/70 transition-colors hover:bg-white/10 hover:text-white"
            aria-label="Close"
          >
            <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" strokeWidth="2" fill="none">
              <path d="M18 6L6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="overflow-y-auto p-6">
          {status === 'success' ? (
            <div className="text-center py-8">
              <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                <svg className="h-6 w-6 text-green-600" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
              </div>
              <h3 className="text-lg font-semibold text-cdcf-navy">{t('successTitle')}</h3>
              <p className="mt-2 text-gray-600">{t('successMessage')}</p>
              <button
                onClick={closeDialog}
                className="cdcf-btn-primary mt-6 rounded-lg px-6 py-2"
              >
                OK
              </button>
            </div>
          ) : isCodeView ? (
            <div className="py-4">
              <div className="mb-6 text-center">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-blue-100">
                  <svg className="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                  </svg>
                </div>
                <h3 className="text-lg font-semibold text-cdcf-navy">{t('checkEmailTitle')}</h3>
                <p className="mt-2 text-sm text-gray-600">
                  {t('checkEmailMessage', { email: formData.submitter_email })}
                </p>
              </div>

              {codeError && (
                <div className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                  {codeError}
                </div>
              )}

              <div className="mb-4">
                <label htmlFor="verification_code" className="block text-sm font-medium text-gray-700">
                  {t('codeLabel')}
                </label>
                <input
                  type="text"
                  id="verification_code"
                  inputMode="numeric"
                  maxLength={6}
                  autoComplete="one-time-code"
                  value={verificationCode}
                  onChange={(e) => setVerificationCode(e.target.value.replace(/\D/g, ''))}
                  placeholder={t('codePlaceholder')}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-center text-lg tracking-widest focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                />
                <p className="mt-1 text-xs text-gray-500">{t('codeExpiry')}</p>
              </div>

              <button
                onClick={handleVerifyAndSubmit}
                disabled={status === 'submitting' || verificationCode.length !== 6}
                className="cdcf-btn-primary w-full rounded-lg px-6 py-3 text-sm font-medium disabled:opacity-60"
              >
                {status === 'submitting' ? t('submitting') : t('verifyAndSubmit')}
              </button>

              <div className="mt-4 flex justify-between text-sm">
                <button
                  type="button"
                  onClick={handleBackToForm}
                  className="text-gray-500 hover:text-gray-700 underline"
                >
                  {t('backToForm')}
                </button>
                <button
                  type="button"
                  onClick={handleResendCode}
                  className="text-cdcf-navy hover:text-cdcf-gold underline"
                >
                  {t('resendCode')}
                </button>
              </div>
            </div>
          ) : (
            <>
              <p className="mb-6 text-sm text-gray-600">{t('referDescription')}</p>

              {status === 'error' && (
                <div className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                  <strong>{t('errorTitle')}:</strong> {t('errorMessage')}
                </div>
              )}

              <form onSubmit={handleSendCode} className="space-y-4">
                {/* Honeypot — hidden from real users */}
                <div className="absolute -left-[9999px]" aria-hidden="true">
                  <label htmlFor="website">Website</label>
                  <input
                    type="text"
                    id="website"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                  />
                </div>

                <div>
                  <label htmlFor="group_name" className="block text-sm font-medium text-gray-700">
                    {t('fieldGroupName')} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="group_name"
                    name="group_name"
                    required
                    defaultValue={formData.group_name}
                    placeholder={t('fieldGroupNamePlaceholder')}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                  />
                </div>

                <div>
                  <label htmlFor="location" className="block text-sm font-medium text-gray-700">
                    {t('fieldLocation')}
                  </label>
                  <input
                    type="text"
                    id="location"
                    name="location"
                    defaultValue={formData.location}
                    placeholder={t('fieldLocationPlaceholder')}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                  />
                </div>

                <div>
                  <label htmlFor="url" className="block text-sm font-medium text-gray-700">
                    {t('fieldUrl')} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="url"
                    id="url"
                    name="url"
                    required
                    defaultValue={formData.url}
                    placeholder={t('fieldUrlPlaceholder')}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                  />
                </div>

                <div>
                  <label htmlFor="description" className="block text-sm font-medium text-gray-700">
                    {t('fieldDescription')} <span className="text-red-500">*</span>
                  </label>
                  <textarea
                    id="description"
                    name="description"
                    required
                    rows={3}
                    defaultValue={formData.description}
                    placeholder={t('fieldDescriptionPlaceholder')}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                  />
                </div>

                <hr className="my-2 border-gray-200" />

                <div>
                  <label htmlFor="submitter_name" className="block text-sm font-medium text-gray-700">
                    {t('fieldYourName')} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="submitter_name"
                    name="submitter_name"
                    required
                    defaultValue={formData.submitter_name}
                    placeholder={t('fieldYourNamePlaceholder')}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                  />
                </div>

                <div>
                  <label htmlFor="submitter_email" className="block text-sm font-medium text-gray-700">
                    {t('fieldYourEmail')} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="email"
                    id="submitter_email"
                    name="submitter_email"
                    required
                    defaultValue={formData.submitter_email}
                    placeholder={t('fieldYourEmailPlaceholder')}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                  />
                </div>

                <button
                  type="submit"
                  disabled={status === 'sending_code'}
                  className="cdcf-btn-primary w-full rounded-lg px-6 py-3 text-sm font-medium disabled:opacity-60"
                >
                  {status === 'sending_code' ? t('sendingCode') : t('sendCode')}
                </button>
              </form>
            </>
          )}
        </div>
      </dialog>
    </>
  )
}
