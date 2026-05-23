import type { Metadata } from 'next'
import Image from 'next/image'
import { notFound } from 'next/navigation'
import { setRequestLocale, getTranslations } from 'next-intl/server'
import {
  getAuthorByDerivedSlug,
  getPostsByAuthor,
  getTeamMemberProfile,
} from '@/lib/wordpress/api'
import {
  bioParagraphs,
  linkedTeamMemberId,
  resolveAuthorProfile,
} from '@/lib/author-profile'
import { Link } from '@/src/i18n/navigation'
import BlogFeed from '@/components/sections/BlogFeed'
import SocialLinks from '@/components/blog/SocialLinks'
import JsonLd from '@/components/JsonLd'

interface AuthorPageProps {
  params: Promise<{ lang: string; slug: string }>
}

const SITE_URL =
  process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

function absoluteUrl(lang: string, path: string): string {
  return `${SITE_URL}/${lang === 'en' ? '' : `${lang}/`}${path}`
}

export async function generateMetadata({
  params,
}: AuthorPageProps): Promise<Metadata> {
  const { lang, slug } = await params
  const author = await getAuthorByDerivedSlug(slug)
  if (!author) return {}

  const profile = resolveAuthorProfile(author, null)
  const description = bioParagraphs(profile.bioHtml).join(' ').slice(0, 160)

  return {
    title: profile.name,
    description: description || undefined,
    alternates: { canonical: absoluteUrl(lang, `blog/authors/${profile.slug}`) },
  }
}

export default async function AuthorPage({ params }: AuthorPageProps) {
  const { lang, slug } = await params
  setRequestLocale(lang)

  const [author, t] = await Promise.all([
    getAuthorByDerivedSlug(slug),
    getTranslations('authors'),
  ])

  if (!author) {
    notFound()
  }

  const teamMemberId = linkedTeamMemberId(author)
  // author.slug is the WP nicename — the key the posts query filters on.
  const [teamMember, posts] = await Promise.all([
    teamMemberId
      ? getTeamMemberProfile(teamMemberId, lang)
      : Promise.resolve(null),
    getPostsByAuthor(author.slug, lang, 50),
  ])

  const profile = resolveAuthorProfile(author, teamMember)
  const paragraphs = bioParagraphs(profile.bioHtml)

  const authorUrl = absoluteUrl(lang, `blog/authors/${profile.slug}`)
  const sameAs = [
    profile.links.website,
    profile.links.linkedin,
    profile.links.github,
  ].filter(Boolean)

  const personSchema: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'ProfilePage',
    mainEntity: {
      '@type': 'Person',
      name: profile.name,
      url: authorUrl,
      ...(profile.image && { image: profile.image.url }),
      ...(profile.title && { jobTitle: profile.title }),
      ...(paragraphs.length && {
        description: paragraphs.join(' ').slice(0, 250),
      }),
      ...(sameAs.length && { sameAs }),
    },
  }

  return (
    <div className="cdcf-section mx-auto max-w-4xl">
      <JsonLd data={personSchema} />

      <Link
        href="/blog/authors"
        className="inline-flex items-center text-sm text-cdcf-gold transition-colors hover:text-cdcf-gold-600"
      >
        &larr; {t('moreAuthors')}
      </Link>

      <header className="mt-6 flex flex-col items-center gap-6 text-center sm:flex-row sm:text-left">
        {profile.image ? (
          <Image
            src={profile.image.url}
            alt={profile.image.alt}
            width={128}
            height={128}
            className="h-32 w-32 flex-shrink-0 rounded-full object-cover"
            priority
          />
        ) : (
          <div className="flex h-32 w-32 flex-shrink-0 items-center justify-center rounded-full bg-cdcf-navy/5 font-serif text-4xl font-bold text-cdcf-navy/40">
            {profile.name.charAt(0)}
          </div>
        )}

        <div>
          <h1 className="cdcf-heading text-3xl sm:text-4xl">{profile.name}</h1>
          {profile.title && (
            <p className="mt-1 text-lg text-gray-500">{profile.title}</p>
          )}
          <div className="mt-3">
            <SocialLinks
              links={profile.links}
              className="flex items-center justify-center gap-3 sm:justify-start"
            />
          </div>
        </div>
      </header>

      {paragraphs.length > 0 && (
        <div className="mt-8 space-y-4 text-lg leading-relaxed text-gray-700">
          {paragraphs.map((paragraph, i) => (
            <p key={i}>{paragraph}</p>
          ))}
        </div>
      )}

      <div className="cdcf-divider" />

      <h2 className="cdcf-heading text-2xl">
        {t('articlesBy', { name: profile.name })}
      </h2>

      {posts.length > 0 ? (
        <BlogFeed posts={posts} />
      ) : (
        <p className="mt-6 text-gray-500">{t('noArticles')}</p>
      )}
    </div>
  )
}
