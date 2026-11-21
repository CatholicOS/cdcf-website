// components/sections/DonationForm.tsx
'use client'

import { useEffect, useRef, useState } from 'react'
import { useTranslations } from 'next-intl'
import clsx from 'clsx'

import {
  MAX_DONATION_CENTS,
  MIN_DONATION_CENTS,
  type DonationFrequency,
} from '@/lib/donate/validation'
import type { DonatableProject } from '@/lib/wordpress/api'

interface DonationFormProps {
  locale: string
  projects: DonatableProject[]
  presetAmounts: number[]
  aboveCapHtml: string
}

/**
 * The donation form: frequency, preset and custom amount, optional project
 * designation. Above the cap it shows the editor's above-cap panel instead
 * of the button; the server enforces the same limits regardless.
 */
export default function DonationForm({
  locale,
  projects,
  presetAmounts,
  aboveCapHtml,
}: DonationFormProps) {
  const t = useTranslations('donate')
  const [dollars, setDollars] = useState<string>(
    presetAmounts[0] ? String(presetAmounts[0]) : '25'
  )
  const [frequency, setFrequency] = useState<DonationFrequency>('once')
  const [projectSlug, setProjectSlug] = useState<string>('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string>('')
  const formRef = useRef<HTMLFormElement>(null)

  // A project page's "Support this project" link names the project in
  // ?project=. Read in the browser, like ?status=, so the page stays cached;
  // anything not in the designation list is ignored (general fund).
  useEffect(() => {
    const wanted = new URLSearchParams(window.location.search).get('project')
    if (!wanted || !projects.some((project) => project.slug === wanted)) {
      return
    }
    setProjectSlug(wanted)
    const reduceMotion = window.matchMedia?.(
      '(prefers-reduced-motion: reduce)'
    ).matches
    formRef.current?.scrollIntoView({
      behavior: reduceMotion ? 'auto' : 'smooth',
      block: 'start',
    })
  }, [projects])

  const parsed = Number(dollars)
  const amountCents = Number.isInteger(parsed) ? parsed * 100 : Number.NaN
  const aboveCap =
    Number.isFinite(amountCents) && amountCents > MAX_DONATION_CENTS
  const belowMin =
    Number.isFinite(amountCents) && amountCents < MIN_DONATION_CENTS

  /** Set the amount and drop any message about the previous one. */
  function changeAmount(value: string) {
    setDollars(value)
    setError('')
  }

  /** Validate locally, then ask the server for a Checkout URL and go there. */
  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError('')

    if (!Number.isInteger(parsed)) {
      setError(t('invalidAmount'))
      return
    }
    if (belowMin) {
      setError(t('belowMinimum'))
      return
    }

    setSubmitting(true)
    try {
      const response = await fetch('/api/donate/create-session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amountCents,
          frequency,
          projectSlug: projectSlug || null,
          locale,
          // Where Stripe sends the donor back, so ?status survives.
          returnPath: window.location.pathname,
        }),
      })
      const data = await response.json()
      if (!response.ok || !data.url) {
        setError(t('failed'))
        setSubmitting(false)
        return
      }
      window.location.href = data.url
    } catch {
      setError(t('failed'))
      setSubmitting(false)
    }
  }

  return (
    <form
      ref={formRef}
      onSubmit={handleSubmit}
      className="mx-auto max-w-lg scroll-mt-24 space-y-6"
    >
      <div className="flex gap-2">
        {(['once', 'monthly'] as const).map((option) => (
          <button
            key={option}
            type="button"
            onClick={() => setFrequency(option)}
            className={clsx(
              'flex-1 rounded-md border px-4 py-2 text-sm',
              frequency === option
                ? 'cdcf-btn-primary'
                : 'border-gray-300 bg-white'
            )}
          >
            {option === 'once' ? t('frequencyOnce') : t('frequencyMonthly')}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        {presetAmounts.map((preset) => (
          <button
            key={preset}
            type="button"
            onClick={() => changeAmount(String(preset))}
            className={clsx(
              'rounded-md border px-4 py-2 text-sm',
              dollars === String(preset)
                ? 'cdcf-btn-primary'
                : 'border-gray-300 bg-white'
            )}
          >
            ${preset}
          </button>
        ))}
      </div>

      <label className="block">
        <span className="mb-1 block text-sm font-medium">
          {t('amountLabel')}
        </span>
        <input
          type="number"
          inputMode="numeric"
          min={MIN_DONATION_CENTS / 100}
          step={1}
          value={dollars}
          onChange={(e) => changeAmount(e.target.value)}
          className="w-full rounded-md border border-gray-300 px-3 py-2"
        />
      </label>

      {projects.length > 0 && (
        <label className="block">
          <span className="mb-1 block text-sm font-medium">
            {t('designationLabel')}
          </span>
          <select
            value={projectSlug}
            onChange={(e) => setProjectSlug(e.target.value)}
            className="w-full rounded-md border border-gray-300 px-3 py-2"
          >
            <option value="">{t('designationGeneral')}</option>
            {projects.map((project) => (
              <option key={project.slug} value={project.slug}>
                {project.title}
              </option>
            ))}
          </select>
        </label>
      )}

      {aboveCap ? (
        <div
          className="prose rounded-md bg-amber-50 p-4"
          dangerouslySetInnerHTML={{ __html: aboveCapHtml }}
        />
      ) : (
        <button
          type="submit"
          disabled={submitting}
          className="cdcf-btn-primary w-full disabled:opacity-60"
        >
          {submitting ? t('submitting') : t('submit')}
        </button>
      )}

      {error && <p className="text-sm text-red-700">{error}</p>}
    </form>
  )
}
