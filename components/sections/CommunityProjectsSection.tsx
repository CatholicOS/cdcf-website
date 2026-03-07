'use client'

import { useState, useMemo } from 'react'
import Image from 'next/image'
import clsx from 'clsx'
import { GlobeAltIcon } from '@heroicons/react/24/outline'
import { useTranslations } from 'next-intl'
import type { WPCommunityProject } from '@/lib/wordpress/types'
import ReferCommunityProjectModal from './ReferCommunityProjectModal'

interface CommunityProjectsSectionProps {
  projects: WPCommunityProject[]
  heading?: string
  intro?: string
  id?: string
  columns?: number
  referButtonLabel?: string
}

export default function CommunityProjectsSection({
  projects,
  heading,
  intro,
  id,
  columns = 3,
  referButtonLabel,
}: CommunityProjectsSectionProps) {
  const t = useTranslations('projects')
  const [categoryFilter, setCategoryFilter] = useState<string>('all')

  const categories = useMemo(() => {
    const cats = new Set<string>()
    for (const p of projects) {
      if (p.communityProjectFields.projectCategory) {
        cats.add(p.communityProjectFields.projectCategory)
      }
    }
    return Array.from(cats).sort()
  }, [projects])

  const filtered = useMemo(() => {
    if (categoryFilter === 'all') return projects
    return projects.filter(
      (p) => p.communityProjectFields.projectCategory === categoryFilter
    )
  }, [projects, categoryFilter])

  const gridCols = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 lg:grid-cols-4',
  }[columns] || 'sm:grid-cols-2 lg:grid-cols-3'

  return (
    <section id={id} className={clsx('py-16 sm:py-20', id && 'scroll-mt-16')}>
      <div className="cdcf-section">
        {(heading || intro) && (
          <div className="text-center">
            {heading && (
              <h2 className="cdcf-heading text-3xl sm:text-4xl">{heading}</h2>
            )}
            {intro && (
              <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                {intro}
              </p>
            )}
          </div>
        )}

        {projects.length > 0 && categories.length > 0 && (
          <div className="mt-8 flex justify-center">
            <select
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value)}
              className="rounded-full border border-gray-200 bg-white px-4 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:border-gray-300 focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
            >
              <option value="all">{t('filterAllCategories')}</option>
              {categories.map((cat) => (
                <option key={cat} value={cat}>{cat}</option>
              ))}
            </select>
          </div>
        )}

        {projects.length > 0 && <div className={clsx('mt-12 grid gap-6', gridCols)}>
          {filtered.map((project) => (
            <div
              key={project.slug}
              className="cdcf-card flex flex-col"
            >
              <div className="flex items-start justify-between">
                {project.featuredImage?.node && (
                  <div className="h-12 w-12 shrink-0 overflow-hidden rounded-lg">
                    <Image
                      src={project.featuredImage.node.sourceUrl}
                      alt={project.featuredImage.node.altText || project.title}
                      width={96}
                      height={96}
                      className="h-full w-full object-contain"
                    />
                  </div>
                )}
                {project.communityProjectFields.projectCategory && (
                  <span className="ml-auto rounded-full bg-cdcf-navy/10 px-2.5 py-0.5 text-xs font-medium text-cdcf-navy">
                    {project.communityProjectFields.projectCategory}
                  </span>
                )}
              </div>

              <h3 className="mt-4 font-serif text-lg font-bold text-cdcf-navy">
                {project.title}
              </h3>

              {project.excerpt && (
                <div
                  className="cdcf-content mt-3 flex-1 text-sm leading-relaxed text-gray-600"
                  dangerouslySetInnerHTML={{ __html: project.excerpt }}
                />
              )}

              {(project.communityProjectFields.projectUrl || project.communityProjectFields.projectGithubUrl) && (
                <div className="mt-4 flex items-center gap-3 border-t border-gray-100 pt-4">
                  {project.communityProjectFields.projectUrl && (
                    <a
                      href={project.communityProjectFields.projectUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 text-sm font-medium text-cdcf-gold transition-colors hover:text-cdcf-gold-600"
                    >
                      <GlobeAltIcon className="h-4 w-4" />
                      {t('viewWebsite')}
                    </a>
                  )}
                  {project.communityProjectFields.projectGithubUrl && (
                    <a
                      href={project.communityProjectFields.projectGithubUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 transition-colors hover:text-cdcf-navy"
                    >
                      <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z" />
                      </svg>
                      {t('viewGitHub')}
                    </a>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>}

        {projects.length > 0 && filtered.length === 0 && (
          <p className="mt-12 text-center text-gray-500">{t('noResults')}</p>
        )}

        {referButtonLabel && (
          <div className="mt-10 text-center">
            <ReferCommunityProjectModal buttonLabel={referButtonLabel} />
          </div>
        )}
      </div>
    </section>
  )
}
