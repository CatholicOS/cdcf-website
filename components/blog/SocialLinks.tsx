import { getTranslations } from 'next-intl/server'
import type { AuthorProfile } from '@/lib/author-profile'

interface SocialLinksProps {
  links: AuthorProfile['links']
  className?: string
}

const ICONS = {
  website: (
    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 0c2.5 2.5 3.5 6 3.5 10s-1 7.5-3.5 10c-2.5-2.5-3.5-6-3.5-10s1-7.5 3.5-10zM2 12h20" />
  ),
  linkedin: (
    <path d="M4.98 3.5a2.5 2.5 0 11-.02 5 2.5 2.5 0 01.02-5zM3 9h4v12H3zM10 9h3.8v1.7h.05c.53-1 1.83-2.05 3.77-2.05 4.03 0 4.78 2.65 4.78 6.1V21h-4v-5.4c0-1.3 0-2.96-1.8-2.96s-2.08 1.4-2.08 2.86V21h-4z" />
  ),
  github: (
    <path d="M12 2a10 10 0 00-3.16 19.49c.5.09.68-.22.68-.48v-1.7c-2.78.6-3.37-1.34-3.37-1.34-.45-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.6.07-.6 1 .07 1.53 1.03 1.53 1.03.9 1.53 2.36 1.09 2.94.83.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.94 0-1.09.39-1.98 1.03-2.68-.1-.25-.45-1.27.1-2.65 0 0 .84-.27 2.75 1.02a9.6 9.6 0 015 0c1.91-1.29 2.75-1.02 2.75-1.02.55 1.38.2 2.4.1 2.65.64.7 1.03 1.59 1.03 2.68 0 3.84-2.34 4.69-4.57 4.94.36.31.68.92.68 1.85v2.74c0 .27.18.58.69.48A10 10 0 0012 2z" />
  ),
} as const

export default async function SocialLinks({ links, className }: SocialLinksProps) {
  const entries = (['website', 'linkedin', 'github'] as const).filter(
    (key) => links[key]
  )
  if (entries.length === 0) return null

  const t = await getTranslations('authors')
  const labels = {
    website: t('social.website'),
    linkedin: t('social.linkedin'),
    github: t('social.github'),
  } as const

  return (
    <ul className={className ?? 'flex items-center gap-3'}>
      {entries.map((key) => (
        <li key={key}>
          <a
            href={links[key]}
            target="_blank"
            rel="noopener noreferrer"
            className="text-cdcf-navy/60 transition-colors hover:text-cdcf-gold"
          >
            <span className="sr-only">{labels[key]}</span>
            <svg
              viewBox="0 0 24 24"
              className="h-5 w-5"
              fill={key === 'website' ? 'none' : 'currentColor'}
              stroke={key === 'website' ? 'currentColor' : 'none'}
              strokeWidth={key === 'website' ? 1.5 : undefined}
              aria-hidden="true"
            >
              {ICONS[key]}
            </svg>
          </a>
        </li>
      ))}
    </ul>
  )
}
