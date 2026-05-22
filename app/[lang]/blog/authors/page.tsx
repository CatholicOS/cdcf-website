import type { Metadata } from 'next'
import { setRequestLocale, getTranslations } from 'next-intl/server'
import { getAuthors, getTeamMemberProfile } from '@/lib/wordpress/api'
import { resolveAuthorProfile } from '@/lib/author-profile'
import AuthorCard from '@/components/blog/AuthorCard'

interface AuthorsPageProps {
  params: Promise<{ lang: string }>
}

export async function generateMetadata({
  params,
}: AuthorsPageProps): Promise<Metadata> {
  const { lang } = await params
  const t = await getTranslations({ locale: lang, namespace: 'authors' })
  return { title: t('title'), description: t('intro') }
}

export default async function AuthorsPage({ params }: AuthorsPageProps) {
  const { lang } = await params
  setRequestLocale(lang)

  const [authors, t] = await Promise.all([
    getAuthors(),
    getTranslations('authors'),
  ])

  // Resolve each author's translated team_member profile (if linked).
  const profiles = await Promise.all(
    authors.map(async (author) => {
      const teamMemberId =
        author.authorProfile?.authorTeamMember?.nodes?.[0]?.databaseId ?? null
      const teamMember = teamMemberId
        ? await getTeamMemberProfile(teamMemberId, lang)
        : null
      return resolveAuthorProfile(author, teamMember)
    })
  )

  return (
    <div className="cdcf-section">
      <div className="text-center">
        <h1 className="cdcf-heading text-3xl sm:text-4xl">{t('title')}</h1>
        <p className="mx-auto mt-3 max-w-2xl text-gray-600">{t('intro')}</p>
      </div>

      {profiles.length > 0 ? (
        <div className="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
          {profiles.map((profile) => (
            <AuthorCard key={profile.slug} profile={profile} />
          ))}
        </div>
      ) : (
        <p className="mt-12 text-center text-gray-500">{t('noAuthors')}</p>
      )}
    </div>
  )
}
