import clsx from 'clsx'
import type { WPStatItem } from '@/lib/wordpress/types'

interface StatsBarProps {
  stats: WPStatItem[]
  bgColor?: string
}

export default function StatsBar({ stats, bgColor = 'navy' }: StatsBarProps) {
  return (
    <section
      className={clsx(
        'py-16 sm:py-20',
        (!bgColor || bgColor === 'navy') && 'bg-cdcf-navy'
      )}
    >
      <div className="cdcf-section grid grid-cols-2 gap-8 sm:grid-cols-3 lg:grid-cols-4">
        {stats.map((stat, i) => (
          <div key={i} className="flex flex-col items-center">
            {stat.statFields.statIcon && (
              <span className="mb-2 text-3xl">{stat.statFields.statIcon}</span>
            )}
            <span className="text-4xl font-bold text-cdcf-gold sm:text-5xl">
              {stat.statFields.statNumber}
            </span>
            <span className="mt-2 text-sm font-medium uppercase tracking-wider text-gray-300">
              {stat.statFields.statLabel}
            </span>
          </div>
        ))}
      </div>
    </section>
  )
}
