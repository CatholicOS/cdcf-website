import type { WPCommunityChannel } from '@/lib/wordpress/types'

interface CommunitySectionProps {
  channels: WPCommunityChannel[]
  heading?: string
  intro?: string
}

export default function CommunitySection({
  channels,
  heading,
  intro,
}: CommunitySectionProps) {
  return (
    <section className="py-16 sm:py-20">
      <div className="cdcf-section">
        {(heading || intro) && (
          <div className="text-center">
            {heading && (
              <h2 className="cdcf-heading text-3xl sm:text-4xl">{heading}</h2>
            )}
            {intro && (
              <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                {intro}
              </p>
            )}
          </div>
        )}

        <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {channels.map((channel, i) => (
            <a
              key={i}
              href={channel.channelFields.channelUrl || '#'}
              target="_blank"
              rel="noopener noreferrer"
              className="cdcf-card group flex items-start gap-4"
            >
              {channel.channelFields.channelIcon && (
                <span className="text-3xl">{channel.channelFields.channelIcon}</span>
              )}
              <div>
                <h3 className="font-serif text-lg font-bold text-cdcf-navy transition-colors group-hover:text-cdcf-gold">
                  {channel.title}
                </h3>
                {channel.channelFields.channelDescription && (
                  <p className="mt-1 text-sm text-gray-600">
                    {channel.channelFields.channelDescription}
                  </p>
                )}
              </div>
            </a>
          ))}
        </div>
      </div>
    </section>
  )
}
