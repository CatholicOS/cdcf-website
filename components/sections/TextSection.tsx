import clsx from 'clsx'

interface TextSectionProps {
  heading: string
  body: string
  bgColor?: string
  width?: 'narrow' | 'medium' | 'full'
  showDivider?: boolean
  pullQuote?: string
}

export default function TextSection({
  heading,
  body,
  bgColor,
  width = 'medium',
  showDivider = true,
  pullQuote,
}: TextSectionProps) {
  const widthClass = {
    narrow: 'max-w-2xl',
    medium: 'max-w-4xl',
    full: 'max-w-7xl',
  }[width]

  return (
    <section
      className="py-16 sm:py-20"
      style={bgColor && bgColor !== 'white' ? { backgroundColor: bgColor } : undefined}
    >
      <div className={clsx('mx-auto px-4 sm:px-6 lg:px-8', widthClass)}>
        {heading && (
          <h2 className="cdcf-heading text-3xl sm:text-4xl">{heading}</h2>
        )}

        {showDivider && <div className="cdcf-divider" />}

        {body && (
          <div
            className="prose mt-6 text-lg leading-relaxed text-gray-600"
            dangerouslySetInnerHTML={{ __html: body }}
          />
        )}

        {pullQuote && (
          <blockquote className="mt-8 border-l-4 border-cdcf-gold pl-6 font-serif text-xl italic text-cdcf-navy">
            {pullQuote}
          </blockquote>
        )}
      </div>
    </section>
  )
}
