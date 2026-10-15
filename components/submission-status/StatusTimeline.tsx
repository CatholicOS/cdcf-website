import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import type { TimelineStep } from '@/lib/submission-status-ui'

export function StatusTimeline({ steps }: { steps: TimelineStep[] }) {
  const t = useTranslations('SubmissionStatus')
  return (
    <ol
      aria-label={t('progress')}
      className="flex flex-wrap gap-x-6 gap-y-2 text-sm"
    >
      {steps.map((s) => (
        <li
          key={s.key}
          aria-current={s.state === 'current' ? 'step' : undefined}
          className={clsx(
            'flex items-center gap-2',
            s.state === 'upcoming' ? 'text-gray-400' : 'text-gray-800',
            s.state === 'current' && 'font-semibold text-cdcf-navy'
          )}
        >
          <span
            aria-hidden="true"
            className={clsx(
              'inline-block h-2.5 w-2.5 rounded-full',
              s.state === 'done' && 'bg-cdcf-navy',
              s.state === 'current' && 'bg-cdcf-gold ring-2 ring-cdcf-navy',
              s.state === 'upcoming' && 'bg-gray-300'
            )}
          />
          {t(`steps.${s.key}`)}
        </li>
      ))}
    </ol>
  )
}
