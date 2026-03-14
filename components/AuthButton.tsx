'use client'

import { useSession, signIn, signOut } from "next-auth/react"
import { useTranslations } from "next-intl"
import { Link } from "@/src/i18n/navigation"
import { ChevronDownIcon, UserIcon } from "@heroicons/react/24/outline"
import { useState, useRef, useEffect, useCallback } from "react"
import clsx from "clsx"

export default function AuthButton() {
  const { data: session, status } = useSession()
  const t = useTranslations("auth")
  const [isOpen, setIsOpen] = useState(false)
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

  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      setIsOpen(prev => !prev)
    } else if (e.key === 'Escape') {
      closeDropdown()
    }
  }, [closeDropdown])

  useEffect(() => {
    return () => {
      if (closeTimeout.current) {
        clearTimeout(closeTimeout.current)
      }
    }
  }, [])

  if (status === "loading") {
    return (
      <div className="flex h-9 items-center px-3 text-sm text-gray-500">
        {t("loading")}
      </div>
    )
  }

  if (!session || !session.user) {
    return (
      <button
        onClick={() => signIn("zitadel")}
        className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
      >
        {t("signIn")}
      </button>
    )
  }

  return (
    <div
      className="relative"
      onMouseEnter={openDropdown}
      onMouseLeave={scheduleClose}
    >
      <button
        className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
        aria-haspopup="menu"
        aria-expanded={isOpen}
        onKeyDown={handleKeyDown}
      >
        {session.user?.image ? (
          <img
            src={session.user.image}
            alt={session.user.name || ""}
            className="h-5 w-5 rounded-full"
          />
        ) : (
          <UserIcon className="h-5 w-5" />
        )}
        <span className="max-w-[100px] truncate hidden lg:inline">
          {session.user?.name || session.user?.email}
        </span>
        <ChevronDownIcon
          className={clsx(
            'h-3.5 w-3.5 transition-transform',
            isOpen && 'rotate-180'
          )}
        />
      </button>

      {isOpen && (
        <div role="menu" className="absolute right-0 top-full min-w-40 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
          <div className="px-4 py-2 text-xs text-gray-500 border-b border-gray-100 mb-1">
            {session.user?.email}
          </div>
          <Link
            href="/profile"
            role="menuitem"
            className="block px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
            onClick={() => setIsOpen(false)}
          >
            {t("profile")}
          </Link>
          <button
            role="menuitem"
            onClick={() => signOut()}
            className="block w-full text-left px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 hover:text-cdcf-navy"
          >
            {t("signOut")}
          </button>
        </div>
      )}
    </div>
  )
}
