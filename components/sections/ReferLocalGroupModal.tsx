'use client'

import { useState, useRef, useCallback } from 'react'
import { useTranslations } from 'next-intl'

interface ReferLocalGroupModalProps {
  buttonLabel: string
}

export default function ReferLocalGroupModal({ buttonLabel }: ReferLocalGroupModalProps) {
  const t = useTranslations('community')
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [status, setStatus] = useState<'idle' | 'submitting' | 'success' | 'error'>('idle')

  const openDialog = useCallback(() => {
    setStatus('idle')
    dialogRef.current?.showModal()
  }, [])

  const closeDialog = useCallback(() => {
    dialogRef.current?.close()
  }, [])

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setStatus('submitting')

    const form = e.currentTarget
    const data = new FormData(form)

    try {
      const res = await fetch('/api/refer-local-group', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          group_name: data.get('group_name'),
          location: data.get('location'),
          url: data.get('url'),
          description: data.get('description'),
          submitter_name: data.get('submitter_name'),
          submitter_email: data.get('submitter_email'),
          website: data.get('website'),
        }),
      })

      if (res.ok) {
        setStatus('success')
        form.reset()
      } else {
        setStatus('error')
      }
    } catch {
      setStatus('error')
    }
  }

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
          ) : (
            <>
              <p className="mb-6 text-sm text-gray-600">{t('referDescription')}</p>

              {status === 'error' && (
                <div className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                  <strong>{t('errorTitle')}:</strong> {t('errorMessage')}
                </div>
              )}

              <form onSubmit={handleSubmit} className="space-y-4">
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
                    placeholder={t('fieldYourEmailPlaceholder')}
                    className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
                  />
                </div>

                <button
                  type="submit"
                  disabled={status === 'submitting'}
                  className="cdcf-btn-primary w-full rounded-lg px-6 py-3 text-sm font-medium disabled:opacity-60"
                >
                  {status === 'submitting' ? t('submitting') : t('submit')}
                </button>
              </form>
            </>
          )}
        </div>
      </dialog>
    </>
  )
}
