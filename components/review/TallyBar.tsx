import clsx from 'clsx'
import { useTranslations } from 'next-intl'
import type { ReviewTally } from '@/lib/review-types'
import { tallySegments } from '@/lib/review-ui'

const COLORS = {
  cdcf: 'bg-emerald-600',
  community: 'bg-sky-600',
  reject: 'bg-rose-600',
  pending: 'bg-gray-300',
} as const

export function TallyBar({
  tally,
  pending,
}: {
  tally: ReviewTally
  pending: number
}) {
  const t = useTranslations('Review')
  const segments = tallySegments(tally, pending)
  return (
    <div>
      <div
        className="flex h-2 w-full overflow-hidden rounded-full bg-gray-100"
        aria-hidden="true"
      >
        {segments
          .filter((s) => s.percent > 0)
          .map((s) => (
            <div
              key={s.key}
              className={COLORS[s.key]}
              style={{ width: `${s.percent}%` }}
            />
          ))}
      </div>
      <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
        {segments.map((s) => (
          <li key={s.key} className="flex items-center gap-1.5">
            <span
              className={clsx(
                'inline-block h-2 w-2 rounded-full',
                COLORS[s.key]
              )}
            />
            {s.key === 'pending'
              ? t('tally.pending', { count: s.count })
              : `${t(`vote.${s.key}`)}: ${s.count}`}
          </li>
        ))}
      </ul>
    </div>
  )
}
