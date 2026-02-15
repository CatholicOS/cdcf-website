import Image from 'next/image'
import clsx from 'clsx'
import type { WPProject } from '@/lib/wordpress/types'

type ProjectStatus = 'incubating' | 'active' | 'graduated'

const statusStyles: Record<ProjectStatus, { bg: string; text: string; label: string }> = {
  incubating: { bg: 'bg-amber-100', text: 'text-amber-800', label: 'Incubating' },
  active: { bg: 'bg-green-100', text: 'text-green-800', label: 'Active' },
  graduated: { bg: 'bg-cdcf-navy/10', text: 'text-cdcf-navy', label: 'Graduated' },
}

interface ProjectGridProps {
  projects: WPProject[]
  title?: string
  intro?: string
  columns?: number
}

export default function ProjectGrid({
  projects,
  title,
  intro,
  columns = 3,
}: ProjectGridProps) {
  const gridCols = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 lg:grid-cols-4',
  }[columns] || 'sm:grid-cols-2 lg:grid-cols-3'

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

        <div className={clsx('mt-12 grid gap-6', gridCols)}>
          {projects.map((project) => {
            const status = (project.projectFields.projectStatus?.[0] || 'incubating') as ProjectStatus
            const statusStyle = statusStyles[status]

            return (
              <div key={project.slug} className="cdcf-card flex flex-col">
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
                      'rounded-full px-2.5 py-0.5 text-xs font-medium',
                      statusStyle.bg,
                      statusStyle.text
                    )}
                  >
                    {statusStyle.label}
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
                    className="mt-3 flex-1 text-sm leading-relaxed text-gray-600"
                    dangerouslySetInnerHTML={{ __html: project.excerpt }}
                  />
                )}

                <div className="mt-4 flex gap-3 border-t border-gray-100 pt-4">
                  {project.projectFields.projectUrl && (
                    <a
                      href={project.projectFields.projectUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-sm font-medium text-cdcf-navy transition-colors hover:text-cdcf-gold"
                    >
                      Website
                    </a>
                  )}
                  {project.projectFields.projectRepoUrl && (
                    <a
                      href={project.projectFields.projectRepoUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-sm font-medium text-cdcf-navy transition-colors hover:text-cdcf-gold"
                    >
                      Repository
                    </a>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </section>
  )
}
