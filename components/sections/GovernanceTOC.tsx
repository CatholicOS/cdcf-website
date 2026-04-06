import { Link } from '@/src/i18n/navigation'
import type { WPChildPage } from '@/lib/wordpress/api'
import { stripHtml } from '@/lib/strip-html'

interface GovernanceTOCProps {
  pages: WPChildPage[]
  heading?: string
  description?: string
}

export default function GovernanceTOC({
  pages,
  heading,
  description,
}: GovernanceTOCProps) {
  if (pages.length === 0) return null

  return (
    <section className="py-16 sm:py-20">
      <div className="cdcf-section">
        {(heading || description) && (
          <div className="mb-12 text-center">
            {heading && (
              <h2 className="cdcf-heading text-3xl sm:text-4xl">{heading}</h2>
            )}
            {description && (
              <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                {description}
              </p>
            )}
          </div>
        )}

        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {pages.map((page) => (
            <Link
              key={page.slug}
              href={page.uri}
              className="group rounded-lg border border-gray-200 p-6 transition-all hover:border-cdcf-gold hover:shadow-md"
            >
              <h3 className="font-serif text-lg font-bold text-cdcf-navy transition-colors group-hover:text-cdcf-gold">
                {page.title}
              </h3>
              {page.excerpt && (
                <p className="mt-2 line-clamp-3 text-sm text-gray-600">
                  {stripHtml(page.excerpt)}
                </p>
              )}
            </Link>
          ))}
        </div>
      </div>
    </section>
  )
}
