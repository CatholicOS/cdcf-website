import clsx from 'clsx'
import type { WPLocalGroup } from '@/lib/wordpress/types'
import ReferLocalGroupModal from './ReferLocalGroupModal'

interface LocalGroupsSectionProps {
  groups: WPLocalGroup[]
  heading?: string
  intro?: string
  referButtonLabel?: string
  id?: string
}

export default function LocalGroupsSection({
  groups,
  heading,
  intro,
  referButtonLabel,
  id,
}: LocalGroupsSectionProps) {
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
          {groups.map((group, i) => (
            <a
              key={i}
              href={group.localGroupFields.groupUrl || '#'}
              target="_blank"
              rel="noopener noreferrer"
              className="cdcf-card group flex items-start gap-4"
            >
              <span className="text-cdcf-navy">
                <svg
                  className="h-8 w-8"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth={1.5}
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                  />
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"
                  />
                </svg>
              </span>
              <div>
                <h3 className="font-serif text-lg font-bold text-cdcf-navy transition-colors group-hover:text-cdcf-gold">
                  {group.title}
                </h3>
                {group.localGroupFields.groupLocation && (
                  <span className="mt-1 inline-block rounded-full bg-cdcf-navy/10 px-2.5 py-0.5 text-xs font-medium text-cdcf-navy">
                    {group.localGroupFields.groupLocation}
                  </span>
                )}
                {group.localGroupFields.groupDescription && (
                  <p className="mt-2 text-sm text-gray-600">
                    {group.localGroupFields.groupDescription}
                  </p>
                )}
              </div>
            </a>
          ))}
        </div>

        {referButtonLabel && (
          <div className="mt-10 text-center">
            <ReferLocalGroupModal buttonLabel={referButtonLabel} />
          </div>
        )}
      </div>
    </section>
  )
}
