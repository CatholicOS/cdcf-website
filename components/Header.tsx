'use client'

import { useState, useRef } from 'react'
import { Link } from '@/src/i18n/navigation'
import { useTranslations } from 'next-intl'
import { Bars3Icon, XMarkIcon, ChevronDownIcon } from '@heroicons/react/24/outline'
import clsx from 'clsx'
import Logo from './Logo'
import LanguageSwitcher from './LanguageSwitcher'

interface NavChild {
  href: string
  label: string
  group?: string
}

interface NavLink {
  href: string
  label: string
  children?: NavChild[]
}

export default function Header() {
  const t = useTranslations('nav')
  const [mobileOpen, setMobileOpen] = useState(false)
  const [desktopDropdown, setDesktopDropdown] = useState<string | null>(null)
  const [mobileDropdown, setMobileDropdown] = useState<string | null>(null)
  const closeTimeout = useRef<ReturnType<typeof setTimeout> | null>(null)

  function openDropdown(href: string) {
    if (closeTimeout.current) {
      clearTimeout(closeTimeout.current)
      closeTimeout.current = null
    }
    setDesktopDropdown(href)
  }

  function scheduleClose() {
    closeTimeout.current = setTimeout(() => setDesktopDropdown(null), 150)
  }


  const navLinks: NavLink[] = [
    {
      href: '/about',
      label: t('about'),
      children: [
        { href: '/about#board-of-directors', label: t('boardOfDirectors') },
        { href: '/about#ecclesial-advisory-council', label: t('ecclesialAdvisoryCouncil') },
        { href: '/about#technical-advisory-council', label: t('technicalAdvisoryCouncil') },
        { href: '/about/certificate-of-formation', label: t('certificateOfFormation') },
        { href: '/about/bylaws', label: t('bylaws') },
        { href: '/about/manifesto', label: t('manifesto') },
        { href: '/about/logo-symbolism', label: t('logoSymbolism') },
      ],
    },
    {
      href: '/projects',
      label: t('projects'),
      children: [
        { href: '/projects#cdcf-projects', label: t('cdcfProjects') },
        { href: '/projects#community-projects', label: t('communityProjects') },
      ],
    },
    {
      href: '/governance',
      label: t('governance'),
      children: [
        { href: '/governance/project-governance', label: t('govProjectGovernance') },
        { href: '/governance/ai-governance', label: t('govAiGovernance') },
        { href: '/governance/standards', label: t('govStandards') },
      ],
    },
    {
      href: '/community',
      label: t('community'),
      children: [
        { href: '/community#online-communities', label: t('onlineCommunities') },
        { href: '/community#local-groups', label: t('localGroups') },
        { href: '/community#academic-collaborations', label: t('academicCollaborations') },
      ],
    },
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
          {navLinks.map((link) =>
            link.children ? (
              <div
                key={link.href}
                className="relative"
                onMouseEnter={() => openDropdown(link.href)}
                onMouseLeave={scheduleClose}
              >
                <Link
                  href={link.href}
                  className="inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                >
                  {link.label}
                  <ChevronDownIcon
                    className={clsx(
                      'h-3.5 w-3.5 transition-transform',
                      desktopDropdown === link.href && 'rotate-180'
                    )}
                  />
                </Link>
                {desktopDropdown === link.href && (
                  <div className="absolute left-0 top-full min-w-48 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                    {link.children.map((child, i, arr) => {
                      const prevGroup = i > 0 ? arr[i - 1].group : undefined
                      const showGroup = child.group && child.group !== prevGroup
                      const isFirstGroup = showGroup && !prevGroup
                      return (
                        <div key={child.href}>
                          {showGroup && (
                            <div
                              className={clsx(
                                'px-4 pt-2 pb-1 text-xs font-semibold tracking-wide text-gray-400 uppercase',
                                !isFirstGroup && 'mt-1 border-t border-gray-100'
                              )}
                            >
                              {child.group}
                            </div>
                          )}
                          <Link
                            href={child.href}
                            onClick={() => setDesktopDropdown(null)}
                            className="block px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                          >
                            {child.label}
                          </Link>
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            ) : (
              <Link
                key={link.href}
                href={link.href}
                className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
              >
                {link.label}
              </Link>
            )
          )}
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
          mobileOpen ? 'max-h-[80vh] overflow-y-auto' : 'max-h-0 border-t-0'
        )}
      >
        <nav className="flex flex-col px-4 py-2">
          {navLinks.map((link) =>
            link.children ? (
              <div key={link.href}>
                <div className="flex items-center justify-between">
                  <Link
                    href={link.href}
                    onClick={() => setMobileOpen(false)}
                    className="flex-1 rounded-l-md px-3 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                  >
                    {link.label}
                  </Link>
                  <button
                    onClick={() =>
                      setMobileDropdown(mobileDropdown === link.href ? null : link.href)
                    }
                    className="rounded-r-md px-3 py-2.5 text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                    aria-label={`${link.label} submenu`}
                  >
                    <ChevronDownIcon
                      className={clsx(
                        'h-4 w-4 transition-transform',
                        mobileDropdown === link.href && 'rotate-180'
                      )}
                    />
                  </button>
                </div>
                {mobileDropdown === link.href &&
                  link.children.map((child, i, arr) => {
                    const prevGroup = i > 0 ? arr[i - 1].group : undefined
                    const showGroup = child.group && child.group !== prevGroup
                    return (
                      <div key={child.href}>
                        {showGroup && (
                          <div className="mt-1 pl-8 pr-3 pt-2 pb-1 text-xs font-semibold tracking-wide text-gray-400 uppercase">
                            {child.group}
                          </div>
                        )}
                        <Link
                          href={child.href}
                          onClick={() => setMobileOpen(false)}
                          className="block rounded-md py-2 pr-3 pl-10 text-sm text-gray-600 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                        >
                          {child.label}
                        </Link>
                      </div>
                    )
                  })}
              </div>
            ) : (
              <Link
                key={link.href}
                href={link.href}
                onClick={() => setMobileOpen(false)}
                className="rounded-md px-3 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
              >
                {link.label}
              </Link>
            )
          )}
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
