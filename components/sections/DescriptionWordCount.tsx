'use client'

import { useTranslations } from 'next-intl'
import clsx from 'clsx'

// Truncation cutoff in preview cards. WordPress's default excerpt_length
// is 55 words and the theme doesn't override it, so descriptions past
// this point get cropped with "[…]" on /projects (and locale siblings).
const TRUNCATE_AT_WORDS = 55

// Zone thresholds for the live indicator below the description textarea.
// Green is the comfortable target; orange warns we're approaching the
// truncation cutoff; red is past the upper word ceiling.
const SOFT_WORD_LIMIT = 50
const HARD_WORD_LIMIT = 60
const SOFT_CHAR_LIMIT = 260

function countWords(text: string): number {
  const trimmed = text.trim()
  return trimmed === '' ? 0 : trimmed.split(/\s+/).length
}

function zoneFor(words: number, chars: number): 'green' | 'orange' | 'red' {
  if (words > HARD_WORD_LIMIT) return 'red'
  if (words > SOFT_WORD_LIMIT || chars > SOFT_CHAR_LIMIT) return 'orange'
  return 'green'
}

export default function DescriptionWordCount({ value }: { value: string }) {
  const t = useTranslations('common')
  const text = value ?? ''
  const words = countWords(text)
  const chars = text.trim().length
  const zone = zoneFor(words, chars)

  return (
    <div className="mt-1 flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5 text-xs">
      <span
        className={clsx(
          'font-medium tabular-nums',
          zone === 'green' && 'text-green-700',
          zone === 'orange' && 'text-orange-600',
          zone === 'red' && 'text-red-700'
        )}
        aria-live="polite"
      >
        {t('descriptionWordCount', { words, chars })}
      </span>
      {words > TRUNCATE_AT_WORDS && (
        <span className="text-gray-500">
          {t('descriptionTruncatedHint', { limit: TRUNCATE_AT_WORDS })}
        </span>
      )}
    </div>
  )
}
