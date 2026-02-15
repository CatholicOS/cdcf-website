import Image from 'next/image'
import type { WPSponsor } from '@/lib/wordpress/types'

interface SponsorGridProps {
  sponsors: WPSponsor[]
  title?: string
  description?: string
}

export default function SponsorGrid({
  sponsors,
  title,
  description,
}: SponsorGridProps) {
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

        <div className="mt-12 grid grid-cols-2 gap-8 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {sponsors.map((sponsor, i) => {
            const className = "flex flex-col items-center gap-3 rounded-lg p-6 transition-shadow hover:shadow-md"
            const content = (
              <>
                {sponsor.featuredImage?.node && (
                  <div className="h-16 w-32">
                    <Image
                      src={sponsor.featuredImage.node.sourceUrl}
                      alt={sponsor.featuredImage.node.altText || sponsor.title}
                      width={256}
                      height={128}
                      className="h-full w-full object-contain"
                    />
                  </div>
                )}
                <span className="text-sm font-medium text-gray-700">
                  {sponsor.title}
                </span>
              </>
            )

            return sponsor.sponsorFields.sponsorUrl ? (
              <a
                key={i}
                href={sponsor.sponsorFields.sponsorUrl}
                target="_blank"
                rel="noopener noreferrer"
                className={className}
              >
                {content}
              </a>
            ) : (
              <div key={i} className={className}>
                {content}
              </div>
            )
          })}
        </div>
      </div>
    </section>
  )
}
