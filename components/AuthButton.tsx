'use client'

import { useCallback, useEffect, useRef, useState } from 'react'
import { useSession, signIn, signOut } from 'next-auth/react'
import { useTranslations } from 'next-intl'
import { ChevronDownIcon, UserIcon } from '@heroicons/react/24/outline'
import clsx from 'clsx'
import { Link } from '@/src/i18n/navigation'

/**
 * Header sign-in / sign-out control.
 *
 * Unauthenticated → "Sign in" button kicking off the Zitadel OIDC flow.
 * Authenticated   → user dropdown with email + sign-out, plus an "Edit
 * my bio" entry when the caller is linked to a team_member post (resolved
 * via the /api/my-bio/check route).
 */
export default function AuthButton() {
  const { data: session, status } = useSession()
  const t = useTranslations('Auth')
  const [isOpen, setIsOpen] = useState(false)
  const [hasBioLink, setHasBioLink] = useState(false)
  const closeTimeout = useRef<ReturnType<typeof setTimeout> | null>(null)

  const openDropdown = useCallback(() => {
    if (closeTimeout.current) {
      clearTimeout(closeTimeout.current)
      closeTimeout.current = null
    }
    setIsOpen(true)
  }, [])

  const closeDropdown = useCallback(() => {
    setIsOpen(false)
  }, [])

  const scheduleClose = useCallback(() => {
    closeTimeout.current = setTimeout(() => setIsOpen(false), 150)
  }, [])

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        setIsOpen((prev) => !prev)
      } else if (e.key === 'Escape') {
        closeDropdown()
      }
    },
    [closeDropdown]
  )

  useEffect(() => {
    return () => {
      if (closeTimeout.current) {
        clearTimeout(closeTimeout.current)
      }
    }
  }, [])

  // Resolve the user's team_member link via the server route once the
  // session is authenticated. The route is cheap (it short-circuits to
  // 200 for anon and for authenticated-but-not-linked users), so a
  // single fire-and-forget request keeps the dropdown decision off the
  // hot path. We don't reset hasBioLink when the session disappears —
  // the dropdown is gated on `session.user` below, so any stale `true`
  // is invisible until the user signs in again, at which point the
  // effect re-fires.
  useEffect(() => {
    if (status !== 'authenticated') return
    const controller = new AbortController()
    fetch('/api/my-bio/check', { signal: controller.signal, cache: 'no-store' })
      .then((res) => (res.ok ? res.json() : null))
      .then((body) => {
        if (body && typeof body === 'object' && 'linked' in body) {
          setHasBioLink(Boolean(body.linked))
        }
      })
      .catch(() => {
        /* aborted or network error — leave hasBioLink as-is */
      })
    return () => controller.abort()
  }, [status])

  if (status === 'loading') {
    return (
      <div className="flex h-9 items-center px-3 text-sm text-gray-500">
        {t('loading')}
      </div>
    )
  }

  if (!session?.user) {
    return (
      <button
        type="button"
        onClick={() => signIn('zitadel')}
        className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
      >
        {t('signIn')}
      </button>
    )
  }

  return (
    <div className="relative">
      <button
        type="button"
        className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
        aria-haspopup="menu"
        aria-expanded={isOpen}
        onMouseEnter={openDropdown}
        onMouseLeave={scheduleClose}
        onFocus={openDropdown}
        onBlur={scheduleClose}
        onKeyDown={handleKeyDown}
      >
        <UserIcon className="h-5 w-5" />
        <span className="hidden max-w-[100px] truncate lg:inline">
          {session.user.name ?? session.user.email}
        </span>
        <ChevronDownIcon
          className={clsx(
            'h-3.5 w-3.5 transition-transform',
            isOpen && 'rotate-180'
          )}
        />
      </button>

      {isOpen && (
        <div
          role="menu"
          // Mouse + focus handlers also live on the trigger button; the
          // menu mirrors them so transitions INTO the menu (cursor OR
          // keyboard) cancel the close-on-leave timer scheduled when the
          // cursor or focus left the button. Without onFocus here,
          // tabbing from the button into a menu item would let the
          // 150ms timer fire and close the menu mid-traversal — React's
          // onFocus bubbles, so item focus reaches us here.
          onMouseEnter={openDropdown}
          onMouseLeave={scheduleClose}
          onFocus={openDropdown}
          onBlur={scheduleClose}
          className="absolute right-0 top-full min-w-44 rounded-md border border-gray-200 bg-white py-1 shadow-lg"
        >
          {session.user.email && (
            <div className="mb-1 border-b border-gray-100 px-4 py-2 text-xs text-gray-500">
              {session.user.email}
            </div>
          )}
          {hasBioLink && (
            <Link
              href="/my-bio"
              role="menuitem"
              onClick={() => setIsOpen(false)}
              className="block px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
            >
              {t('editMyBio')}
            </Link>
          )}
          <button
            type="button"
            role="menuitem"
            onClick={() => signOut()}
            className="block w-full px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
          >
            {t('signOut')}
          </button>
        </div>
      )}
    </div>
  )
}
