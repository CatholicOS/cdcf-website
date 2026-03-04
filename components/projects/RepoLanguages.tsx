'use client'

import { useEffect, useState } from 'react'

// GitHub language colors (subset of common languages)
const LANGUAGE_COLORS: Record<string, string> = {
  TypeScript: '#3178c6',
  JavaScript: '#f1e05a',
  PHP: '#4F5D95',
  Python: '#3572A5',
  CSS: '#563d7c',
  HTML: '#e34c26',
  Java: '#b07219',
  Go: '#00ADD8',
  Rust: '#dea584',
  Ruby: '#701516',
  'C#': '#178600',
  Swift: '#F05138',
  Kotlin: '#A97BFF',
  C: '#555555',
  'C++': '#f34b7d',
  Shell: '#89e051',
  Dart: '#00B4AB',
  Lua: '#000080',
  SCSS: '#c6538c',
  Vue: '#41b883',
  Svelte: '#ff3e00',
  Dockerfile: '#384d54',
  Makefile: '#427819',
}

interface RepoLanguagesProps {
  repos: string[]
  label: string
}

type LanguageData = Record<string, Record<string, number>>

export default function RepoLanguages({ repos, label }: RepoLanguagesProps) {
  const [languages, setLanguages] = useState<LanguageData | null>(null)
  const [loading, setLoading] = useState(true)

  // Extract owner/repo from GitHub URLs
  const repoIds = repos
    .map((url) => {
      try {
        const u = new URL(url)
        if (u.hostname !== 'github.com') return null
        const parts = u.pathname.split('/').filter(Boolean)
        if (parts.length >= 2) return `${parts[0]}/${parts[1]}`
        return null
      } catch {
        return null
      }
    })
    .filter((id): id is string => id !== null)

  useEffect(() => {
    if (repoIds.length === 0) {
      setLoading(false)
      return
    }

    fetch(`/api/github/languages?repos=${repoIds.join(',')}`)
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => setLanguages(data))
      .catch(() => setLanguages(null))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [repos.join(',')])

  if (loading) {
    return (
      <div className="mt-2 flex gap-2">
        {[1, 2, 3].map((i) => (
          <div
            key={i}
            className="h-6 w-16 animate-pulse rounded-full bg-gray-200"
          />
        ))}
      </div>
    )
  }

  if (!languages) return null

  // Merge all languages across repos
  const merged: Record<string, number> = {}
  for (const repoLangs of Object.values(languages)) {
    for (const [lang, bytes] of Object.entries(repoLangs)) {
      merged[lang] = (merged[lang] || 0) + bytes
    }
  }

  const sorted = Object.entries(merged).sort(([, a], [, b]) => b - a)
  if (sorted.length === 0) return null

  return (
    <div>
      <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wide">
        {label}
      </h3>
      <div className="mt-2 flex flex-wrap gap-2">
        {sorted.map(([lang]) => (
          <span
            key={lang}
            className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700"
          >
            <span
              className="inline-block h-2.5 w-2.5 rounded-full"
              style={{
                backgroundColor: LANGUAGE_COLORS[lang] || '#8b8b8b',
              }}
            />
            {lang}
          </span>
        ))}
      </div>
    </div>
  )
}
