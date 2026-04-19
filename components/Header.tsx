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
  children?: { href: string; label: string }[]
}

interface NavLink {
  href: string
  label: string
  children?: NavChild[]
}

function DesktopDropdown({
  items,
  onClose,
}: {
  items: NavChild[]
  onClose: () => void
}) {
  const hasNested = items.some((item) => item.children?.length)

  if (hasNested) {
    return (
      <div className="absolute left-1/2 top-full -translate-x-1/2 rounded-md border border-gray-200 bg-white py-3 shadow-lg">
        <div className="flex divide-x divide-gray-100">
          {items.map((item) => (
            <div key={item.href} className="min-w-44 px-4">
              <Link
                href={item.href}
                onClick={onClose}
                className="block pb-1.5 text-xs font-semibold tracking-wide text-cdcf-navy uppercase transition-colors hover:text-cdcf-gold"
              >
                {item.label}
              </Link>
              {item.children?.map((grandchild) => (
                <Link
                  key={grandchild.href}
                  href={grandchild.href}
                  onClick={onClose}
                  className="block py-1.5 text-sm text-gray-600 transition-colors hover:text-cdcf-navy"
                >
                  {grandchild.label}
                </Link>
              ))}
            </div>
          ))}
        </div>
      </div>
    )
  }

  // Flat dropdown (no nested children)
  return (
    <div className="absolute left-0 top-full min-w-48 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
      {items.map((child, i, arr) => {
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
              onClick={onClose}
              className="block px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
            >
              {child.label}
            </Link>
          </div>
        )
      })}
    </div>
  )
}

export default function Header() {
  const t = useTranslations('nav')
  const [mobileOpen, setMobileOpen] = useState(false)
  const [desktopDropdown, setDesktopDropdown] = useState<string | null>(null)
  const [mobileDropdown, setMobileDropdown] = useState<string | null>(null)
  const [mobileSubDropdown, setMobileSubDropdown] = useState<string | null>(null)
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
        {
          href: '/governance/project-governance',
          label: t('govProjectGovernance'),
          children: [
            { href: '/governance/project-governance/project-vetting-criteria', label: t('govProjectVetting') },
            { href: '/governance/project-governance/lifecycle', label: t('govLifecycle') },
            { href: '/governance/project-governance/committees', label: t('govCommittees') },
            { href: '/governance/project-governance/project-types', label: t('govProjectTypes') },
            { href: '/governance/project-governance/definitions', label: t('govDefinitions') },
          ],
        },
        {
          href: '/governance/standards',
          label: t('govStandards'),
          children: [
            { href: '/governance/standards/standards-overview', label: t('govStandardsOverview') },
            { href: '/governance/standards/standards-committees', label: t('govStandardsCommittees') },
          ],
        },
        {
          href: '/governance/research',
          label: t('govResearch'),
          children: [
            { href: '/governance/research/fragmented-catholic-digital-governance', label: t('govFragmented') },
            { href: '/governance/research/governance-as-code-catholic-technology', label: t('govAsCode') },
            { href: '/governance/research/trusted-data-infrastructure-catholic-ministry', label: t('govTrustedData') },
          ],
        },
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
                  <DesktopDropdown
                    items={link.children}
                    onClose={() => setDesktopDropdown(null)}
                  />
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
                    {
                      setMobileSubDropdown(null)
                      setMobileDropdown(mobileDropdown === link.href ? null : link.href)
                    }
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
                  link.children.map((child) => (
                    <div key={child.href}>
                      {child.children ? (
                        <>
                          <div className="flex items-center justify-between">
                            <Link
                              href={child.href}
                              onClick={() => setMobileOpen(false)}
                              className="flex-1 rounded-l-md py-2 pr-3 pl-10 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                            >
                              {child.label}
                            </Link>
                            <button
                              onClick={() =>
                                setMobileSubDropdown(
                                  mobileSubDropdown === child.href ? null : child.href
                                )
                              }
                              className="rounded-r-md px-3 py-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                              aria-label={`${child.label} submenu`}
                            >
                              <ChevronDownIcon
                                className={clsx(
                                  'h-3.5 w-3.5 transition-transform',
                                  mobileSubDropdown === child.href && 'rotate-180'
                                )}
                              />
                            </button>
                          </div>
                          {mobileSubDropdown === child.href &&
                            child.children.map((grandchild) => (
                              <Link
                                key={grandchild.href}
                                href={grandchild.href}
                                onClick={() => setMobileOpen(false)}
                                className="block rounded-md py-1.5 pr-3 pl-14 text-sm text-gray-500 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                              >
                                {grandchild.label}
                              </Link>
                            ))}
                        </>
                      ) : (
                        <Link
                          href={child.href}
                          onClick={() => setMobileOpen(false)}
                          className="block rounded-md py-2 pr-3 pl-10 text-sm text-gray-600 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
                        >
                          {child.label}
                        </Link>
                      )}
                    </div>
                  ))}
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
