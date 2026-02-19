'use client'

import { useState } from 'react'
import { Link } from '@/src/i18n/navigation'
import { useTranslations } from 'next-intl'
import { Bars3Icon, XMarkIcon } from '@heroicons/react/24/outline'
import clsx from 'clsx'
import Logo from './Logo'
import LanguageSwitcher from './LanguageSwitcher'

export default function Header() {
  const t = useTranslations('nav')
  const [mobileOpen, setMobileOpen] = useState(false)

  const navLinks = [
    { href: '/about', label: t('about') },
    { href: '/projects', label: t('projects') },
    { href: '/community', label: t('community') },
    { href: '/blog', label: t('news') },
    { href: '/contact', label: t('contact') },
  ]

  return (
    <header className="sticky top-0 z-40 border-b border-gray-200 bg-white/95 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        {/* Logo + brand */}
        <Link href="/" className="flex items-center gap-3">
          <Logo width={40} height={40} />
          <span className="hidden font-serif text-lg font-bold text-cdcf-navy sm:inline">
            CDCF
          </span>
        </Link>

        {/* Desktop nav */}
        <nav className="hidden items-center gap-1 md:flex">
          {navLinks.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
            >
              {link.label}
            </Link>
          ))}
        </nav>

        {/* Right side: language switcher + donate CTA */}
        <div className="flex items-center gap-2">
          <LanguageSwitcher />
          <Link href="/donate" className="cdcf-btn-primary hidden text-sm sm:inline-flex">
            {t('donate')}
          </Link>

          {/* Mobile hamburger */}
          <button
            className="rounded-md p-2 text-gray-700 md:hidden"
            onClick={() => setMobileOpen(!mobileOpen)}
            aria-label="Toggle menu"
          >
            {mobileOpen ? (
              <XMarkIcon className="h-6 w-6" />
            ) : (
              <Bars3Icon className="h-6 w-6" />
            )}
          </button>
        </div>
      </div>

      {/* Mobile nav */}
      <div
        className={clsx(
          'overflow-hidden border-t border-gray-200 bg-white transition-all md:hidden',
          mobileOpen ? 'max-h-96' : 'max-h-0 border-t-0'
        )}
      >
        <nav className="flex flex-col px-4 py-2">
          {navLinks.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              onClick={() => setMobileOpen(false)}
              className="rounded-md px-3 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
            >
              {link.label}
            </Link>
          ))}
          <Link
            href="/donate"
            onClick={() => setMobileOpen(false)}
            className="cdcf-btn-primary mt-2 mb-2 text-center text-sm"
          >
            {t('donate')}
          </Link>
        </nav>
      </div>
    </header>
  )
}
