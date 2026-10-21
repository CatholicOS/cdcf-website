import { useFormatter, useTranslations } from 'next-intl'
import { Link } from '@/src/i18n/navigation'
import type { ReviewProjectSummary } from '@/lib/review-types'
import { parseWpGmt, voteLabelKey } from '@/lib/review-ui'
import { TallyBar } from './TallyBar'

export function ProjectCard({
  project,
  canVote,
}: {
  project: ReviewProjectSummary
  canVote: boolean
}) {
  const t = useTranslations('Review')
  const format = useFormatter()
  const meta = [
    project.category,
    project.language.toUpperCase(),
    t('submittedOn', {
      date: format.dateTime(parseWpGmt(project.submitted_at), {
        dateStyle: 'medium',
      }),
    }),
  ].filter(Boolean)
  return (
    <li>
      <Link
        href={`/review/${project.id}`}
        className="cdcf-card block space-y-3"
      >
        <div className="flex items-start justify-between gap-3">
          <div className="space-y-1">
            {project.kind === 'referral' && (
              <span className="inline-block rounded bg-sky-100 px-1.5 py-0.5 text-xs font-medium text-sky-800">
                {t('referralBadge')}
              </span>
            )}
            <h2 className="cdcf-heading text-lg">{project.title}</h2>
          </div>
          {project.unread > 0 && (
            <span className="shrink-0 rounded-full bg-cdcf-navy px-2 py-0.5 text-xs font-medium text-white">
              {t('unread', { count: project.unread })}
            </span>
          )}
        </div>
        <p className="text-xs text-gray-500">{meta.join(' · ')}</p>
        <TallyBar
          tally={project.tally}
          pending={project.voters_pending}
          kind={project.kind}
        />
        {project.my_vote ? (
          <p className="text-xs text-gray-700">
            {t('yourVote', {
              vote: t(voteLabelKey(project.kind, project.my_vote)),
            })}
          </p>
        ) : canVote && project.stage !== 'decided' ? (
          <p className="text-xs font-medium text-amber-700">
            {t('awaitingYourVote')}
          </p>
        ) : null}
      </Link>
    </li>
  )
}
