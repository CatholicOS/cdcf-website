import { useFormatter, useTranslations } from 'next-intl'
import type { ReviewDecisionRecord, ReviewKind } from '@/lib/review-types'
import { voteLabelKey } from '@/lib/review-ui'
import { MarkdownView } from './MarkdownView'

export function DecisionCard({
  decision,
  kind,
}: {
  decision: ReviewDecisionRecord
  kind: ReviewKind
}) {
  const t = useTranslations('Review')
  const format = useFormatter()
  const date = format.dateTime(new Date(decision.decided_at * 1000), {
    dateStyle: 'medium',
  })
  return (
    <section className="cdcf-card space-y-3">
      <h2 className="cdcf-heading text-xl">{t('decision.heading')}</h2>
      <p className="text-lg font-semibold">
        {t(voteLabelKey(kind, decision.decision) as 'vote.cdcf')}
      </p>
      <p className="text-sm text-gray-600">
        {decision.admin_override || !decision.decided_by
          ? t('decision.adminOverride', { date })
          : t('decision.decidedBy', { name: decision.decided_by.name, date })}
      </p>
      {decision.rationale && (
        <div className="space-y-1">
          <h3 className="text-sm font-semibold">
            {t('decision.rationaleHeading')}
          </h3>
          <MarkdownView source={decision.rationale} />
        </div>
      )}
      {decision.summary && (
        <div className="space-y-1">
          <h3 className="text-sm font-semibold">
            {t('decision.summaryHeading')}
          </h3>
          <p className="whitespace-pre-wrap text-sm">{decision.summary}</p>
        </div>
      )}
    </section>
  )
}
