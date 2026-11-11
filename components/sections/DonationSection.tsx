import { Suspense } from 'react'
import { getTranslations } from 'next-intl/server'

import { MAX_DONATION_CENTS } from '@/lib/donate/validation'
import type { DonatableProject } from '@/lib/wordpress/api'
import type { WPPage } from '@/lib/wordpress/types'

import DonationForm from './DonationForm'
import DonationStatus from './DonationStatus'

interface DonationSectionProps {
  page: WPPage
  locale: string
  projects: DonatableProject[]
}

/** "10,25,50,100" -> [10, 25, 50, 100], dropping anything above the cap. */
export function parsePresets(raw: string | null): number[] {
  const presets = (raw ?? '10,25,50,100')
    .split(',')
    .map((part) => Number(part.trim()))
    .filter(
      (value) =>
        Number.isInteger(value) &&
        value > 0 &&
        value * 100 <= MAX_DONATION_CENTS
    )
  return presets.length > 0 ? presets : [10, 25, 50, 100]
}

/**
 * The Donate page body: the editor's appeal, the form and the tax
 * disclaimer, or the thank-you copy once Stripe sends the donor back with
 * ?status=success.
 */
export default async function DonationSection({
  page,
  locale,
  projects,
}: DonationSectionProps) {
  const t = await getTranslations('donate')
  const fields = page.donateFields

  const form = (
    <>
      {fields?.donateAppealBody && (
        <div
          className="prose mx-auto mb-8"
          dangerouslySetInnerHTML={{ __html: fields.donateAppealBody }}
        />
      )}

      <DonationForm
        locale={locale}
        projects={projects}
        presetAmounts={parsePresets(fields?.donatePresetAmounts ?? null)}
        aboveCapHtml={fields?.donateAboveCapBody ?? ''}
      />

      {fields?.donateTaxDisclaimer && (
        <div
          className="prose mx-auto mt-8 text-sm"
          dangerouslySetInnerHTML={{ __html: fields.donateTaxDisclaimer }}
        />
      )}
    </>
  )

  return (
    <section className="cdcf-section">
      {/* The static render shows the form; the browser swaps in the
          thank-you or cancelled state from the query string. */}
      <Suspense fallback={form}>
        <DonationStatus
          thankYouHtml={fields?.donateThankYouBody ?? ''}
          cancelledText={t('cancelled')}
        >
          {form}
        </DonationStatus>
      </Suspense>
    </section>
  )
}
