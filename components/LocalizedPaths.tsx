'use client'

import { createContext, useCallback, useContext, type ReactNode } from 'react'

import { localizeHref } from '@/lib/localized-href'

const LocalizedPathsContext = createContext<Record<string, string>>({})

/** Hands the layout's English-to-localized page path map to client links. */
export function LocalizedPathsProvider({
  paths,
  children,
}: {
  paths: Record<string, string>
  children: ReactNode
}) {
  return (
    <LocalizedPathsContext.Provider value={paths}>
      {children}
    </LocalizedPathsContext.Provider>
  )
}

/**
 * `localize(href)` for the current language: links straight to the page's
 * real URL instead of through the canonical redirect, which flashes an
 * empty page body during client navigation.
 */
export function useLocalizedHref(): (href: string) => string {
  const paths = useContext(LocalizedPathsContext)
  return useCallback((href: string) => localizeHref(href, paths), [paths])
}
