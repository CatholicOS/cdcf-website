import Image from 'next/image'
import { getTranslations } from 'next-intl/server'
import { Link } from '@/src/i18n/navigation'
import SocialLinks from './SocialLinks'
import { bioPlainText, type AuthorProfile } from '@/lib/author-profile'

/** Compact "About the author" card shown beneath an article. The full,
 *  formatted bio lives on the author page linked from here. */
export default async function AuthorBio({ profile }: { profile: AuthorProfile }) {
  const t = await getTranslations('authors')
  const href = `/blog/authors/${profile.slug}`
  const bio = bioPlainText(profile.bioHtml)
  const excerpt = bio.length > 280 ? `${bio.slice(0, 280).trimEnd()}…` : bio

  return (
    <aside className="mt-12 rounded-lg border border-cdcf-navy/10 bg-cdcf-navy/[0.02] p-6">
      <h2 className="text-xs font-semibold uppercase tracking-wide text-cdcf-navy/60">
        {t('aboutTheAuthor')}
      </h2>

      <div className="mt-4 flex flex-col gap-4 sm:flex-row">
        {profile.image && (
          <Image
            src={profile.image.url}
            alt={profile.image.alt}
            width={80}
            height={80}
            className="h-20 w-20 flex-shrink-0 rounded-full object-cover"
          />
        )}

        <div className="flex-1">
          <Link
            href={href}
            className="font-serif text-lg font-bold text-cdcf-navy transition-colors hover:text-cdcf-gold"
          >
            {profile.name}
          </Link>
          {profile.role && <p className="text-sm text-gray-500">{profile.role}</p>}
          {excerpt && (
            <p className="mt-2 text-sm leading-relaxed text-gray-600">{excerpt}</p>
          )}

          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <SocialLinks links={profile.links} />
            <Link
              href={href}
              className="text-sm font-medium text-cdcf-gold transition-colors hover:text-cdcf-gold-600"
            >
              {t('viewProfile')} &rarr;
            </Link>
          </div>
        </div>
      </div>
    </aside>
  )
}
