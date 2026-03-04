'use client'

import { useState, useMemo } from 'react'
import Image from 'next/image'
import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import { Link } from '@/src/i18n/navigation'
import type { WPProject } from '@/lib/wordpress/types'
import SubmitProjectModal from './SubmitProjectModal'

type ProjectStatus = 'incubating' | 'active' | 'archived'

const statusStyles: Record<ProjectStatus, { bg: string; text: string }> = {
  incubating: { bg: 'bg-amber-100', text: 'text-amber-800' },
  active: { bg: 'bg-green-100', text: 'text-green-800' },
  archived: { bg: 'bg-red-100', text: 'text-red-800' },
}

interface ProjectGridProps {
  projects: WPProject[]
  title?: string
  intro?: string
  columns?: number
  submitButtonLabel?: string
}

export default function ProjectGrid({
  projects,
  title,
  intro,
  columns = 3,
  submitButtonLabel,
}: ProjectGridProps) {
  const t = useTranslations('projects')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [categoryFilter, setCategoryFilter] = useState<string>('all')

  // Derive unique categories from projects
  const categories = useMemo(() => {
    const cats = new Set<string>()
    for (const p of projects) {
      if (p.projectFields.projectCategory) {
        cats.add(p.projectFields.projectCategory)
      }
    }
    return Array.from(cats).sort()
  }, [projects])

  const filtered = useMemo(() => {
    return projects.filter((p) => {
      const status = p.projectFields.projectStatus?.[0] || 'incubating'
      if (statusFilter !== 'all' && status !== statusFilter) return false
      if (categoryFilter !== 'all' && p.projectFields.projectCategory !== categoryFilter) return false
      return true
    })
  }, [projects, statusFilter, categoryFilter])

  const gridCols = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 lg:grid-cols-4',
  }[columns] || 'sm:grid-cols-2 lg:grid-cols-3'

  // All possible statuses for filter pills (always shown)
  const allStatuses: ProjectStatus[] = ['incubating', 'active', 'archived']

  return (
    <section className="py-16 sm:py-20">
      <div className="cdcf-section">
        {(title || intro) && (
          <div className="text-center">
            {title && (
              <h2 className="cdcf-heading text-3xl sm:text-4xl">{title}</h2>
            )}
            {intro && (
              <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                {intro}
              </p>
            )}
          </div>
        )}

        {projects.length > 0 && (
          <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
            {/* Status filter pills */}
            <button
              onClick={() => setStatusFilter('all')}
              className={clsx(
                'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                statusFilter === 'all'
                  ? 'bg-cdcf-navy text-white'
                  : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
              )}
            >
              {t('filterAll')}
            </button>
            {allStatuses.map((s) => {
              const style = statusStyles[s]
              return (
                <button
                  key={s}
                  onClick={() => setStatusFilter(s)}
                  className={clsx(
                    'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                    statusFilter === s
                      ? `${style.bg} ${style.text} ring-2 ring-current`
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  )}
                >
                  {t(`status.${s}`)}
                </button>
              )
            })}

            {/* Category dropdown */}
            {categories.length > 0 && (
              <>
                <span className="mx-1 text-gray-300">|</span>
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
              </>
            )}
          </div>
        )}

        <div className={clsx('mt-12 grid gap-6', gridCols)}>
          {filtered.map((project) => {
            const status = (project.projectFields.projectStatus?.[0] || 'incubating') as ProjectStatus
            const style = statusStyles[status] || statusStyles.incubating

            return (
              <Link
                key={project.slug}
                href={`/projects/${project.slug}`}
                className="cdcf-card flex flex-col transition-shadow transition-transform duration-200 hover:shadow-lg hover:-translate-y-0.5"
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
                  <span
                    className={clsx(
                      'ml-auto rounded-full px-2.5 py-0.5 text-xs font-medium',
                      style.bg,
                      style.text
                    )}
                  >
                    {t(`status.${status}`)}
                  </span>
                </div>

                <h3 className="mt-4 font-serif text-lg font-bold text-cdcf-navy">
                  {project.title}
                </h3>

                {project.projectFields.projectCategory && (
                  <span className="mt-1 text-xs font-medium text-cdcf-gold">
                    {project.projectFields.projectCategory}
                  </span>
                )}

                {project.excerpt && (
                  <div
                    className="cdcf-content mt-3 flex-1 text-sm leading-relaxed text-gray-600"
                    dangerouslySetInnerHTML={{ __html: project.excerpt }}
                  />
                )}
              </Link>
            )
          })}
        </div>

        {filtered.length === 0 && (
          <p className="mt-12 text-center text-gray-500">{t('noResults')}</p>
        )}

        {submitButtonLabel && (
          <div className="mt-10 text-center">
            <SubmitProjectModal buttonLabel={submitButtonLabel} />
          </div>
        )}
      </div>
    </section>
  )
}
