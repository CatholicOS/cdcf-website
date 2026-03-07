import { getTranslations } from 'next-intl/server'
import {
  RocketLaunchIcon,
  UserGroupIcon,
  GlobeAltIcon,
} from '@heroicons/react/24/outline'
import type { SiteStats } from '@/lib/stats'
import type { ComponentType, SVGProps } from 'react'

interface StatsBarProps {
  stats: SiteStats
}

export default async function StatsBar({ stats }: StatsBarProps) {
  const t = await getTranslations('stats')

  const items: {
    Icon: ComponentType<SVGProps<SVGSVGElement>>
    value: number
    label: string
  }[] = [
    { Icon: RocketLaunchIcon, value: stats.projects, label: t('openSourceProjects') },
    ...(stats.contributors !== null
      ? [{ Icon: UserGroupIcon, value: stats.contributors, label: t('contributors') }]
      : []),
    { Icon: GlobeAltIcon, value: stats.languages, label: t('languages') },
  ]

  return (
    <section className="bg-cdcf-navy py-16 sm:py-20">
      <div className="cdcf-section grid grid-cols-2 gap-8 sm:grid-cols-3 lg:grid-cols-4">
        {items.map((item, i) => (
          <div key={i} className="flex flex-col items-center">
            <item.Icon className="mb-2 size-8 text-white" />
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
