import { notFound, redirect } from 'next/navigation'
import { getTranslations } from 'next-intl/server'
import type { Session } from 'next-auth'
import { auth } from '@/lib/auth'
import {
  ReviewApiError,
  fetchReviewMe,
  fetchReviewProject,
} from '@/lib/review-api'
import type { ReviewMe, ReviewProject } from '@/lib/review-types'
import { buildAttachmentIndex } from '@/lib/review-ui'
import { Link } from '@/src/i18n/navigation'
import { AskSubmitter } from '@/components/review/AskSubmitter'
import { AttachmentPanel } from '@/components/review/AttachmentPanel'
import { EntryCard } from '@/components/review/EntryCard'
import { EntryComposer } from '@/components/review/EntryComposer'
import { MyReviewComposer } from '@/components/review/MyReviewComposer'
import { QuestionsList } from '@/components/review/QuestionsList'
import { ReviewCard } from '@/components/review/ReviewCard'
import { ReviewNotice } from '@/components/review/ReviewNotice'
import { SignInAgain } from '@/components/review/SignInAgain'
import { SubmissionCard } from '@/components/review/SubmissionCard'
import { TallyBar } from '@/components/review/TallyBar'

export default async function ReviewProjectPage({
  params,
}: {
  params: Promise<{ lang: string; id: string }>
}) {
  const { id: rawId } = await params
  if (!/^[1-9]\d{0,15}$/.test(rawId)) notFound()
  const session = await auth()
  if (!session?.user) {
    redirect('/api/auth/signin')
  }
  const t = await getTranslations('Review')

  const loaded = await loadWorkspace(session, Number(rawId))
  if (loaded.kind === 'notFound') notFound()
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
  const { me, project } = loaded

  const locked = project.stage === 'decided'
  const myReview =
    project.reviews.find((r) => r.id === project.me.review_id) ?? null

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-4xl space-y-8">
        <Link href="/review" className="text-sm text-cdcf-navy hover:underline">
          ← {t('backToList')}
        </Link>
        <SubmissionCard project={project} />

        <section className="cdcf-card space-y-2">
          <h2 className="cdcf-heading text-xl">{t('tally.heading')}</h2>
          <TallyBar tally={project.tally} pending={project.voters_pending} />
          {project.plurality && (
            <p className="text-sm text-gray-700">
              {t('plurality', { vote: t(`vote.${project.plurality}`) })}
            </p>
          )}
        </section>

        {locked && (
          <p className="rounded-md bg-gray-100 p-3 text-sm text-gray-700">
            {t('locked')}
          </p>
        )}

        {project.me.can_vote && !locked && (
          <MyReviewComposer
            projectId={project.id}
            existing={
              myReview?.vote
                ? {
                    vote: myReview.vote,
                    body_md: myReview.body_md,
                    version: myReview.version,
                  }
                : null
            }
          />
        )}

        <section className="space-y-4">
          <h2 className="cdcf-heading text-2xl">{t('reviews.heading')}</h2>
          {project.reviews.length === 0 ? (
            <p className="text-sm text-gray-500">{t('reviews.none')}</p>
          ) : (
            <ul className="space-y-4">
              {project.reviews.map((review) => (
                <ReviewCard
                  key={review.id}
                  review={review}
                  projectId={project.id}
                  currentUserId={me.user_id}
                  locked={locked}
                />
              ))}
            </ul>
          )}
        </section>

        <section className="space-y-4">
          <h2 className="cdcf-heading text-2xl">{t('discussion.heading')}</h2>
          {project.discussion.length === 0 ? (
            <p className="text-sm text-gray-500">{t('discussion.none')}</p>
          ) : (
            <ul className="space-y-4">
              {project.discussion.map((entry) => (
                <li key={entry.id} className="cdcf-card">
                  <EntryCard
                    entry={entry}
                    currentUserId={me.user_id}
                    locked={locked}
                    allowEdit
                  />
                </li>
              ))}
            </ul>
          )}
          {!locked && (
            <EntryComposer projectId={project.id} kind="discussion" />
          )}
        </section>

        <section className="space-y-4">
          <h2 className="cdcf-heading text-2xl">{t('questions.heading')}</h2>
          <QuestionsList questions={project.questions} />
          {me.can.discuss && !locked && <AskSubmitter projectId={project.id} />}
        </section>
      </div>
      <AttachmentPanel index={buildAttachmentIndex(project)} />
    </main>
  )
}

type WorkspaceLoad =
  | { kind: 'ok'; me: ReviewMe; project: ReviewProject }
  | { kind: 'notice'; message: 'notMember' | 'errors.sessionExpired' }
  | { kind: 'notFound' }

// Data loading stays outside the component body so no JSX is built inside
// try/catch (react-hooks/error-boundaries).
async function loadWorkspace(
  session: Session,
  id: number
): Promise<WorkspaceLoad> {
  try {
    const me = await fetchReviewMe(session)
    if (!me.member) return { kind: 'notice', message: 'notMember' }
    return { kind: 'ok', me, project: await fetchReviewProject(session, id) }
  } catch (err) {
    if (err instanceof ReviewApiError && err.status === 404)
      return { kind: 'notFound' }
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
