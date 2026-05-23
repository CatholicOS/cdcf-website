import Image from 'next/image'
import { Link } from '@/src/i18n/navigation'
import SocialLinks from './SocialLinks'
import { authorHref, bioPlainText, type AuthorProfile } from '@/lib/author-profile'

/** Author entry on the authors index grid. */
export default function AuthorCard({ profile }: { profile: AuthorProfile }) {
  const href = authorHref(profile.slug)
  const bio = bioPlainText(profile.bioHtml)
  const excerpt = bio.length > 160 ? `${bio.slice(0, 160).trimEnd()}…` : bio

  return (
    <article className="cdcf-card flex flex-col items-center p-6 text-center">
      {profile.image ? (
        <Image
          src={profile.image.url}
          alt={profile.image.alt || profile.name}
          width={96}
          height={96}
          className="h-24 w-24 rounded-full object-cover"
        />
      ) : (
        <div className="flex h-24 w-24 items-center justify-center rounded-full bg-cdcf-navy/5 font-serif text-2xl font-bold text-cdcf-navy/40">
          {profile.name.charAt(0)}
        </div>
      )}

      <h2 className="mt-4 font-serif text-lg font-bold text-cdcf-navy">
        <Link href={href} className="transition-colors hover:text-cdcf-gold">
          {profile.name}
        </Link>
      </h2>
      {profile.title && <p className="text-sm text-gray-500">{profile.title}</p>}

      {excerpt && (
        <p className="mt-3 flex-1 text-sm leading-relaxed text-gray-600">{excerpt}</p>
      )}

      <div className="mt-4">
        <SocialLinks links={profile.links} className="flex items-center justify-center gap-3" />
      </div>
    </article>
  )
}
