import Image from 'next/image'
import clsx from 'clsx'
import { Link } from '@/src/i18n/navigation'
import type { WPAcademicCollaboration } from '@/lib/wordpress/types'

interface AcademicCollaborationsSectionProps {
  collaborations: WPAcademicCollaboration[]
  heading?: string
  intro?: string
  id?: string
}

export default function AcademicCollaborationsSection({
  collaborations,
  heading,
  intro,
  id,
}: AcademicCollaborationsSectionProps) {
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

        <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {collaborations.map((collab, i) => (
            <Link
              key={i}
              href={`/academic-collaborations/${collab.slug}`}
              className="cdcf-card group flex flex-col items-center text-center"
            >
              {collab.featuredImage?.node && (
                <div className="mb-4 h-20 w-20 overflow-hidden rounded-full border-4 border-cdcf-gold/20">
                  <Image
                    src={collab.featuredImage.node.sourceUrl}
                    alt={collab.featuredImage.node.altText || collab.title}
                    width={160}
                    height={160}
                    className="h-full w-full object-cover"
                  />
                </div>
              )}

              <h3 className="font-serif text-lg font-bold text-cdcf-navy transition-colors group-hover:text-cdcf-gold">
                {collab.title}
              </h3>

              {collab.collaborationFields.collabDepartment && (
                <span className="mt-1 inline-block rounded-full bg-cdcf-navy/10 px-2.5 py-0.5 text-xs font-medium text-cdcf-navy">
                  {collab.collaborationFields.collabDepartment}
                </span>
              )}

              {collab.collaborationFields.collabDescription && (
                <p className="mt-2 text-sm text-gray-600">
                  {collab.collaborationFields.collabDescription}
                </p>
              )}

              <span className="mt-3 text-sm font-medium text-cdcf-gold transition-colors group-hover:text-cdcf-gold-600">
                Learn more &rarr;
              </span>
            </Link>
          ))}
        </div>
      </div>
    </section>
  )
}
