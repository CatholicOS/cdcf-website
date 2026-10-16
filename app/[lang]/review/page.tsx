import { redirect } from 'next/navigation'
import { getTranslations } from 'next-intl/server'
import clsx from 'clsx'
import type { Session } from 'next-auth'
import { auth } from '@/lib/auth'
import {
  ReviewApiError,
  fetchReviewMe,
  fetchReviewProjects,
} from '@/lib/review-api'
import {
  REVIEW_STAGES,
  type ReviewProjectList,
  type ReviewStage,
} from '@/lib/review-types'
import { Link } from '@/src/i18n/navigation'
import { ProjectCard } from '@/components/review/ProjectCard'
import { ReviewNotice } from '@/components/review/ReviewNotice'
import { SignInAgain } from '@/components/review/SignInAgain'

export default async function ReviewListPage({
  searchParams,
}: {
  params: Promise<{ lang: string }>
  searchParams: Promise<{ stage?: string; page?: string }>
}) {
  const session = await auth()
  if (!session?.user) {
    redirect('/api/auth/signin')
  }
  const t = await getTranslations('Review')
  const query = await searchParams
  const stage: ReviewStage = (REVIEW_STAGES as readonly string[]).includes(
    query.stage ?? ''
  )
    ? (query.stage as ReviewStage)
    : 'in_review'
  const page = Math.max(1, Number.parseInt(query.page ?? '1', 10) || 1)

  const loaded = await loadList(session, stage, page)
  if (loaded.kind === 'notice') {
    return (
      <ReviewNotice
        title={t('title')}
        message={t(loaded.message)}
        action={
          loaded.message === 'errors.sessionExpired' ? (
            <SignInAgain />
          ) : undefined
        }
      />
    )
  }
  const { list, canVote } = loaded

  const href = (s: ReviewStage, p = 1) =>
    `/review?stage=${s}${p > 1 ? `&page=${p}` : ''}`

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-4xl space-y-6">
        <header className="space-y-2">
          <h1 className="cdcf-heading text-3xl">{t('title')}</h1>
          <p className="text-gray-600">{t('intro')}</p>
          <Link
            href="/review/inbox"
            className="text-sm text-cdcf-navy hover:underline"
          >
            {t('inbox.title')}
          </Link>
        </header>
        <nav
          className="flex gap-2 border-b border-gray-200"
          aria-label={t('title')}
        >
          {REVIEW_STAGES.map((s) => (
            <Link
              key={s}
              href={href(s)}
              aria-current={s === stage ? 'page' : undefined}
              className={clsx(
                '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                s === stage
                  ? 'border-cdcf-navy text-cdcf-navy'
                  : 'border-transparent text-gray-500 hover:text-gray-800'
              )}
            >
              {t(`stage.${s}`)}
            </Link>
          ))}
        </nav>
        {list.projects.length === 0 ? (
          <p className="text-gray-500">{t('empty')}</p>
        ) : (
          <ul className="space-y-4">
            {list.projects.map((project) => (
              <ProjectCard
                key={project.id}
                project={project}
                canVote={canVote}
              />
            ))}
          </ul>
        )}
        {(page > 1 || list.has_more) && (
          <nav className="flex justify-between text-sm">
            {page > 1 ? (
              <Link
                href={href(stage, page - 1)}
                className="text-cdcf-navy hover:underline"
              >
                ← {t('newer')}
              </Link>
            ) : (
              <span />
            )}
            {list.has_more && (
              <Link
                href={href(stage, page + 1)}
                className="text-cdcf-navy hover:underline"
              >
                {t('older')} →
              </Link>
            )}
          </nav>
        )}
      </div>
    </main>
  )
}

type ListLoad =
  | { kind: 'ok'; list: ReviewProjectList; canVote: boolean }
  | { kind: 'notice'; message: 'notMember' | 'errors.sessionExpired' }

// Data loading stays outside the component body so no JSX is built inside
// try/catch (react-hooks/error-boundaries).
async function loadList(
  session: Session,
  stage: ReviewStage,
  page: number
): Promise<ListLoad> {
  try {
    const me = await fetchReviewMe(session)
    if (!me.member) return { kind: 'notice', message: 'notMember' }
    return {
      kind: 'ok',
      canVote: me.can.vote,
      list: await fetchReviewProjects(session, { stage, page }),
    }
  } catch (err) {
    if (
      err instanceof ReviewApiError &&
      (err.status === 401 || err.status === 403)
    ) {
      return {
        kind: 'notice',
        message: err.status === 401 ? 'errors.sessionExpired' : 'notMember',
      }
    }
    throw err
  }
}
