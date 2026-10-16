import type { Metadata } from 'next'
import { cookies, headers } from 'next/headers'
import { notFound } from 'next/navigation'
import { getFormatter, getTranslations } from 'next-intl/server'
import { clientIpFromHeaders } from '@/lib/ip-rate-limit'
import { parseWpGmt } from '@/lib/review-ui'
import { loadSubmissionStatus } from '@/lib/submission-status-load'
import type { SubmissionStatus } from '@/lib/submission-status-types'
import { timelineSteps } from '@/lib/submission-status-ui'
import { statusCookieName } from '@/lib/submission-token'
import { AnswerForm } from '@/components/submission-status/AnswerForm'
import { ResendLinkForm } from '@/components/submission-status/ResendLinkForm'
import { StatusTimeline } from '@/components/submission-status/StatusTimeline'

/** Private to the submitter: never indexed, and no Referer to linked pages. */
export const metadata: Metadata = {
  robots: { index: false, follow: false },
  referrer: 'no-referrer',
}

export default async function SubmissionStatusPage({
  params,
}: {
  params: Promise<{ lang: string; id: string }>
}) {
  const { id: rawId } = await params
  if (!/^[1-9]\d{0,15}$/.test(rawId)) notFound()
  const id = Number(rawId)
  const t = await getTranslations('SubmissionStatus')
  const token = (await cookies()).get(statusCookieName(id))?.value ?? ''
  const status = await loadSubmissionStatus(
    id,
    token,
    clientIpFromHeaders(await headers())
  )

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-2xl space-y-8">
        <h1 className="cdcf-heading text-3xl">{t('title')}</h1>
        {status ? (
          <StatusBody status={status} />
        ) : (
          <section className="cdcf-card space-y-4">
            <p className="text-sm text-gray-700">{t('linkInvalid')}</p>
            <ResendLinkForm projectId={id} />
          </section>
        )}
      </div>
    </main>
  )
}

async function StatusBody({ status }: { status: SubmissionStatus }) {
  const t = await getTranslations('SubmissionStatus')
  const format = await getFormatter()
  const open = status.stage !== 'decided'
  return (
    <>
      <section className="cdcf-card space-y-4">
        <h2 className="cdcf-heading text-2xl">{status.title}</h2>
        <p className="text-sm text-gray-600">
          {t('submittedOn', {
            date: format.dateTime(parseWpGmt(status.submitted_at), {
              dateStyle: 'long',
            }),
          })}
        </p>
        <StatusTimeline steps={timelineSteps(status)} />
      </section>

      <section className="space-y-4">
        <h2 className="cdcf-heading text-xl">{t('questionsHeading')}</h2>
        {status.questions.length === 0 ? (
          <p className="text-sm text-gray-500">{t('noQuestions')}</p>
        ) : (
          <ul className="space-y-4">
            {status.questions.map((q) => (
              <li key={q.id} className="cdcf-card space-y-3">
                <p className="whitespace-pre-line text-sm text-gray-800">
                  {q.body}
                </p>
                {q.answer ? (
                  <div className="border-l-2 border-cdcf-navy pl-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                      {t('yourAnswer')}
                    </p>
                    <p className="whitespace-pre-line text-sm text-gray-800">
                      {q.answer.body}
                    </p>
                  </div>
                ) : open ? (
                  <AnswerForm projectId={status.id} questionId={q.id} />
                ) : (
                  <p className="text-sm italic text-gray-500">
                    {t('answersClosed')}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      {status.decision && (
        <section className="cdcf-card space-y-3">
          <h2 className="cdcf-heading text-xl">{t('decisionHeading')}</h2>
          <p className="font-semibold text-gray-900">
            {t(`outcome.${status.decision.outcome}`)}
          </p>
          {status.decision.summary && (
            <p className="whitespace-pre-line text-sm text-gray-800">
              {status.decision.summary}
            </p>
          )}
          {status.public_url && (
            <a
              href={status.public_url}
              className="text-sm font-medium text-cdcf-navy hover:underline"
            >
              {status.decision?.outcome === 'community'
                ? t('viewListing')
                : t('viewPublic')}
            </a>
          )}
        </section>
      )}
    </>
  )
}
