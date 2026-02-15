'use client'

import { useLocale } from 'next-intl'
import { useRouter, usePathname } from '@/src/i18n/navigation'
import { locales, type Locale } from '@/src/i18n/routing'
import { GlobeAltIcon } from '@heroicons/react/24/outline'
import { useState, useRef, useEffect, useTransition } from 'react'
import clsx from 'clsx'

const localeLabels: Record<Locale, string> = {
  en: 'English',
  it: 'Italiano',
  es: 'Espanol',
  fr: 'Francais',
  pt: 'Portugues',
  de: 'Deutsch',
}

export default function LanguageSwitcher() {
  const locale = useLocale()
  const router = useRouter()
  const pathname = usePathname()
  const [open, setOpen] = useState(false)
  const [isPending, startTransition] = useTransition()
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  function switchLocale(newLocale: Locale) {
    setOpen(false)
    startTransition(() => {
      router.replace(pathname, { locale: newLocale })
    })
  }

  return (
    <div ref={ref} className="relative">
      <button
        onClick={() => setOpen(!open)}
        className={clsx(
          'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm text-cdcf-navy hover:bg-gray-100 transition-colors',
          isPending && 'opacity-50 pointer-events-none'
        )}
        aria-label="Switch language"
      >
        <GlobeAltIcon className="h-5 w-5" />
        <span className="hidden sm:inline">{localeLabels[locale as Locale]}</span>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-1 w-40 rounded-md border border-gray-200 bg-white py-1 shadow-lg z-50">
          {locales.map((loc) => (
            <button
              key={loc}
              onClick={() => switchLocale(loc)}
              className={clsx(
                'block w-full px-4 py-2 text-left text-sm transition-colors',
                loc === locale
                  ? 'bg-cdcf-navy/5 font-semibold text-cdcf-navy'
                  : 'text-gray-700 hover:bg-gray-50'
              )}
            >
              {localeLabels[loc]}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
