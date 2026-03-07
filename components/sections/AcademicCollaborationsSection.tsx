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
                <div className="mb-4 flex h-20 items-center">
                  <Image
                    src={collab.featuredImage.node.sourceUrl}
                    alt={collab.featuredImage.node.altText || collab.title}
                    width={collab.featuredImage.node.mediaDetails?.width || 160}
                    height={collab.featuredImage.node.mediaDetails?.height || 160}
                    className="max-h-20 object-contain"
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

              {collab.collaborationFields.collabLocation && (
                <span className="mt-1 flex items-center gap-1 text-xs text-gray-500">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    className="h-3.5 w-3.5 shrink-0"
                  >
                    <path
                      fillRule="evenodd"
                      d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 00.281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 103 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 002.273 1.765 11.842 11.842 0 00.976.544l.062.029.018.008.006.003zM10 11.25a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5z"
                      clipRule="evenodd"
                    />
                  </svg>
                  {collab.collaborationFields.collabLocation}
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
