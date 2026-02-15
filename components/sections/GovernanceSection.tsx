import Image from 'next/image'
import clsx from 'clsx'
import type { WPTeamMember } from '@/lib/wordpress/types'

interface GovernanceSectionProps {
  members: WPTeamMember[]
  title?: string
  description?: string
  columns?: number
}

export default function GovernanceSection({
  members,
  title,
  description,
  columns = 3,
}: GovernanceSectionProps) {
  const gridCols = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 lg:grid-cols-4',
  }[columns] || 'sm:grid-cols-2 lg:grid-cols-3'

  return (
    <section className="bg-gray-50 py-16 sm:py-20">
      <div className="cdcf-section">
        {(title || description) && (
          <div className="text-center">
            {title && (
              <h2 className="cdcf-heading text-3xl sm:text-4xl">{title}</h2>
            )}
            {description && (
              <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                {description}
              </p>
            )}
          </div>
        )}

        <div className={clsx('mt-12 grid gap-8', gridCols)}>
          {members.map((member, i) => (
            <div key={i} className="flex flex-col items-center text-center">
              {member.featuredImage?.node && (
                <div className="h-24 w-24 overflow-hidden rounded-full border-4 border-cdcf-gold/20">
                  <Image
                    src={member.featuredImage.node.sourceUrl}
                    alt={member.featuredImage.node.altText || member.title}
                    width={192}
                    height={192}
                    className="h-full w-full object-cover"
                  />
                </div>
              )}

              <h3 className="mt-4 font-serif text-lg font-bold text-cdcf-navy">
                {member.title}
              </h3>

              {member.teamMemberFields.memberTitle && (
                <p className="text-sm font-medium text-cdcf-gold">
                  {member.teamMemberFields.memberTitle}
                </p>
              )}

              {member.content && (
                <div
                  className="mt-3 text-sm leading-relaxed text-gray-600"
                  dangerouslySetInnerHTML={{ __html: member.content }}
                />
              )}

              <div className="mt-3 flex gap-3">
                {member.teamMemberFields.memberLinkedinUrl && (
                  <a
                    href={member.teamMemberFields.memberLinkedinUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-gray-400 transition-colors hover:text-cdcf-navy"
                    aria-label="LinkedIn"
                  >
                    <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
                    </svg>
                  </a>
                )}
                {member.teamMemberFields.memberGithubUrl && (
                  <a
                    href={member.teamMemberFields.memberGithubUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-gray-400 transition-colors hover:text-cdcf-navy"
                    aria-label="GitHub"
                  >
                    <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z" />
                    </svg>
                  </a>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
