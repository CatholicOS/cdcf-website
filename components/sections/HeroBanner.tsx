import Image from 'next/image'
import clsx from 'clsx'
import { Link } from '@/src/i18n/navigation'
import type { HeroFields } from '@/lib/wordpress/types'

interface HeroBannerProps {
  hero: HeroFields
}

export default function HeroBanner({ hero }: HeroBannerProps) {
  const bgStyle = hero.heroBgStyle?.[0] || 'gradient'
  const alignment = hero.heroAlignment?.[0] || 'center'

  const alignClass = {
    left: 'text-left items-start',
    center: 'text-center items-center',
    right: 'text-right items-end',
  }[alignment]

  const bgClasses = {
    gradient: 'bg-gradient-to-br from-cdcf-navy via-cdcf-navy-700 to-cdcf-navy-900',
    solid: '',
    image: 'relative',
  }[bgStyle]

  const textColor =
    bgStyle === 'solid' && hero.heroBgColor === '#ffffff'
      ? 'text-cdcf-navy'
      : 'text-white'

  return (
    <section
      className={clsx('relative overflow-hidden py-20 sm:py-28 lg:py-36', bgClasses)}
      style={bgStyle === 'solid' ? { backgroundColor: hero.heroBgColor || '#213463' } : undefined}
    >
      {bgStyle === 'image' && hero.heroBackgroundImage && (
        <div className="absolute inset-0">
          <Image
            src={hero.heroBackgroundImage.node.sourceUrl}
            alt={hero.heroBackgroundImage.node.altText || ''}
            fill
            className="object-cover"
            priority
          />
          <div className="absolute inset-0 bg-cdcf-navy/70" />
        </div>
      )}

      <div className={clsx('cdcf-section relative flex flex-col', alignClass)}>
        {hero.heroShowLogo && (
          <img
            src="/logo.svg"
            alt=""
            className="mb-8 h-20 w-20 sm:h-24 sm:w-24"
          />
        )}

        {hero.heroTagline && (
          <h1
            className={clsx(
              'font-display text-4xl font-bold tracking-tight sm:text-5xl lg:text-6xl',
              textColor
            )}
          >
            {hero.heroTagline}
          </h1>
        )}

        {hero.heroSubtitle && (
          <div
            className={clsx(
              'mt-6 max-w-2xl text-lg leading-relaxed sm:text-xl',
              textColor === 'text-white' ? 'text-gray-200' : 'text-gray-600'
            )}
            dangerouslySetInnerHTML={{ __html: hero.heroSubtitle }}
          />
        )}

        <div className="mt-10 flex flex-wrap gap-4">
          {hero.heroPrimaryBtnLabel && (
            <Link href={hero.heroPrimaryBtnUrl || '#'} className="cdcf-btn-primary text-base">
              {hero.heroPrimaryBtnLabel}
            </Link>
          )}
          {hero.heroSecondaryBtnLabel && (
            <Link href={hero.heroSecondaryBtnUrl || '#'} className="cdcf-btn-secondary text-base">
              {hero.heroSecondaryBtnLabel}
            </Link>
          )}
        </div>
      </div>
    </section>
  )
}
