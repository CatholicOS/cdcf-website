'use client'

import { useId, useState } from 'react'
import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import { MarkdownView } from './MarkdownView'

export function MarkdownEditor({
  value,
  onChange,
  label,
  rows = 6,
  disabled = false,
}: {
  value: string
  onChange: (value: string) => void
  label: string
  rows?: number
  disabled?: boolean
}) {
  const t = useTranslations('Review.editor')
  const [tab, setTab] = useState<'write' | 'preview'>('write')
  const id = useId()
  return (
    <div>
      <div className="flex items-center justify-between">
        <label
          htmlFor={id}
          className="block text-xs font-medium uppercase tracking-wide text-gray-500"
        >
          {label}
        </label>
        <div role="tablist" className="flex gap-1 text-xs">
          {(['write', 'preview'] as const).map((k) => (
            <button
              key={k}
              type="button"
              role="tab"
              aria-selected={tab === k}
              onClick={() => setTab(k)}
              className={clsx(
                'rounded px-2 py-1',
                tab === k
                  ? 'bg-cdcf-navy text-white'
                  : 'text-gray-600 hover:bg-gray-100'
              )}
            >
              {t(k)}
            </button>
          ))}
        </div>
      </div>
      {tab === 'write' ? (
        <textarea
          id={id}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          rows={rows}
          disabled={disabled}
          placeholder={t('placeholder')}
          className="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-cdcf-navy focus:outline-none focus:ring-1 focus:ring-cdcf-navy"
        />
      ) : (
        <div className="mt-1 min-h-24 rounded-md border border-gray-200 px-3 py-2">
          {value.trim() ? (
            <MarkdownView source={value} />
          ) : (
            <p className="text-sm text-gray-400">{t('emptyPreview')}</p>
          )}
        </div>
      )}
    </div>
  )
}
