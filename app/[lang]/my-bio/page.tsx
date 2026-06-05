import { redirect } from 'next/navigation'
import { getTranslations } from 'next-intl/server'
import { auth } from '@/lib/auth'
import {
  BioApiError,
  fetchMyTeamMember,
  fetchTeamMemberPost,
  type BioPostContent,
} from '@/lib/bio-api'
import BioEditor from '@/components/BioEditor'

// Server component for /[lang]/my-bio. Resolves the caller's linked
// team_member post, picks an initial language (Zitadel locale → URL
// locale → first available), fetches that language's current content,
// and hands everything to the client-side editor.
//
// Anonymous → redirect to sign-in.
// Authenticated but no team_member link → friendly "contact ops" message
// (no internal error surfaced).
export default async function MyBioPage({
  params,
}: {
  params: Promise<{ lang: string }>
}) {
  const { lang: urlLocale } = await params
  const session = await auth()
  if (!session?.user) {
    redirect('/api/auth/signin')
  }
  const t = await getTranslations('MyBio')

  let discovery
  try {
    discovery = await fetchMyTeamMember(session)
  } catch (err) {
    if (err instanceof BioApiError && (err.status === 401 || err.status === 403)) {
      return (
        <main className="cdcf-section">
          <div className="prose max-w-prose">
            <h1>{t('title')}</h1>
            <p>{t('notLinked')}</p>
          </div>
        </main>
      )
    }
    throw err
  }

  const available = discovery.available_languages
  if (available.length === 0) {
    return (
      <main className="cdcf-section">
        <div className="prose max-w-prose">
          <h1>{t('title')}</h1>
          <p>{t('noLanguages')}</p>
        </div>
      </main>
    )
  }

  // Prefer Zitadel locale claim → URL locale → first available.
  const preferred =
    pickAvailable(session.user.locale, available) ??
    pickAvailable(urlLocale, available) ??
    available[0]

  let initialPost: BioPostContent
  try {
    initialPost = await fetchTeamMemberPost(session, preferred.post_id)
  } catch (err) {
    // Stale group entry — should be caught by the WP-side post-type
    // check too, but fall through gracefully here as well.
    if (err instanceof BioApiError && err.status === 404) {
      return (
        <main className="cdcf-section">
          <div className="prose max-w-prose">
            <h1>{t('title')}</h1>
            <p>{t('loadError')}</p>
          </div>
        </main>
      )
    }
    throw err
  }

  return (
    <main className="cdcf-section">
      <BioEditor
        availableLanguages={available}
        initialLang={preferred.slug}
        initialPost={initialPost}
      />
    </main>
  )
}

function pickAvailable<L extends { slug: string }>(
  slug: string | undefined,
  list: L[]
): L | undefined {
  if (!slug) return undefined
  return list.find((entry) => entry.slug === slug)
}
