import clsx from 'clsx'
import { Link } from '@/src/i18n/navigation'
import type { CTAFields } from '@/lib/wordpress/types'

interface CallToActionProps {
  cta: CTAFields
}

export default function CallToAction({ cta }: CallToActionProps) {
  const style = cta.ctaStyle?.[0] || 'banner'

  const wrapperClasses = {
    banner: 'bg-cdcf-navy py-16 text-white',
    card: 'py-16',
    inline: 'py-12',
  }[style]

  const innerClasses = {
    banner: 'cdcf-section text-center',
    card: 'cdcf-section',
    inline: 'cdcf-section flex flex-col items-center gap-6 sm:flex-row sm:justify-between',
  }[style]

  return (
    <section className={wrapperClasses}>
      <div
        className={clsx(
          innerClasses,
          style === 'card' &&
            'rounded-xl bg-gradient-to-r from-cdcf-navy to-cdcf-navy-700 p-10 text-center text-white sm:p-14'
        )}
      >
        <div className={style === 'inline' ? 'flex-1' : ''}>
          {cta.ctaHeading && (
            <h2
              className={clsx(
                'font-serif text-3xl font-bold tracking-tight sm:text-4xl',
                style === 'inline' ? 'text-cdcf-navy' : ''
              )}
            >
              {cta.ctaHeading}
            </h2>
          )}

          {cta.ctaDescription && (
            <div
              className={clsx(
                'mt-4 text-lg',
                style === 'banner' || style === 'card'
                  ? 'text-gray-200'
                  : 'text-gray-600'
              )}
              dangerouslySetInnerHTML={{ __html: cta.ctaDescription }}
            />
          )}
        </div>

        <div
          className={clsx(
            'flex flex-wrap gap-4',
            style !== 'inline' && 'mt-8 justify-center'
          )}
        >
          {cta.ctaPrimaryBtnLabel && (
            <Link href={cta.ctaPrimaryBtnUrl || '#'} className="cdcf-btn-primary text-base">
              {cta.ctaPrimaryBtnLabel}
            </Link>
          )}
          {cta.ctaSecondaryBtnLabel && (
            <Link href={cta.ctaSecondaryBtnUrl || '#'} className="cdcf-btn-secondary text-base">
              {cta.ctaSecondaryBtnLabel}
            </Link>
          )}
        </div>
      </div>
    </section>
  )
}
