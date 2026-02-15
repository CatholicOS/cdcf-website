import Image from 'next/image'
import clsx from 'clsx'

interface RichContentProps {
  heading: string
  body: string
  imageUrl?: string
  imageAlt?: string
  imagePosition?: 'left' | 'right'
  bgColor?: string
  showGoldBorder?: boolean
}

export default function RichContent({
  heading,
  body,
  imageUrl,
  imageAlt = '',
  imagePosition = 'right',
  bgColor,
  showGoldBorder = false,
}: RichContentProps) {
  return (
    <section
      className="py-16 sm:py-20"
      style={bgColor && bgColor !== 'white' ? { backgroundColor: bgColor } : undefined}
    >
      <div className="cdcf-section grid items-center gap-12 lg:grid-cols-2">
        <div className={clsx(imagePosition === 'left' && 'lg:order-2')}>
          {heading && (
            <h2 className="cdcf-heading text-3xl sm:text-4xl">{heading}</h2>
          )}

          {body && (
            <div
              className="prose mt-6 text-lg leading-relaxed text-gray-600"
              dangerouslySetInnerHTML={{ __html: body }}
            />
          )}
        </div>

        {imageUrl && (
          <div className={clsx(imagePosition === 'left' && 'lg:order-1')}>
            <div
              className={clsx(
                'overflow-hidden rounded-lg',
                showGoldBorder && 'border-4 border-cdcf-gold'
              )}
            >
              <Image
                src={imageUrl}
                alt={imageAlt}
                width={800}
                height={600}
                className="h-auto w-full object-cover"
              />
            </div>
          </div>
        )}
      </div>
    </section>
  )
}
