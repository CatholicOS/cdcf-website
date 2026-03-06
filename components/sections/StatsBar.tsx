import { getTranslations } from 'next-intl/server'
import type { SiteStats } from '@/lib/stats'

interface StatsBarProps {
  stats: SiteStats
}

export default async function StatsBar({ stats }: StatsBarProps) {
  const t = await getTranslations('stats')

  const items = [
    { icon: '🚀', value: stats.projects, label: t('openSourceProjects') },
    ...(stats.contributors !== null
      ? [{ icon: '👥', value: stats.contributors, label: t('contributors') }]
      : []),
    { icon: '🌍', value: stats.languages, label: t('languages') },
  ]

  return (
    <section className="bg-cdcf-navy py-16 sm:py-20">
      <div className="cdcf-section grid grid-cols-2 gap-8 sm:grid-cols-3 lg:grid-cols-4">
        {items.map((item, i) => (
          <div key={i} className="flex flex-col items-center">
            <span className="mb-2 text-3xl">{item.icon}</span>
            <span className="text-4xl font-bold text-cdcf-gold sm:text-5xl">
              {item.value}+
            </span>
            <span className="mt-2 text-sm font-medium uppercase tracking-wider text-gray-300">
              {item.label}
            </span>
          </div>
        ))}
      </div>
    </section>
  )
}
