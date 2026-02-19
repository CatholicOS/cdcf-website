import Image from 'next/image'
import type { WPPost } from '@/lib/wordpress/types'

interface BlogFeedProps {
  posts: WPPost[]
  title?: string
}

export default function BlogFeed({ posts, title }: BlogFeedProps) {
  return (
    <section className="py-16 sm:py-20">
      <div className="cdcf-section">
        {title && (
          <div className="text-center">
            <h2 className="cdcf-heading text-3xl sm:text-4xl">{title}</h2>
          </div>
        )}

        <div className="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
          {posts.map((post) => {
            const dateStr = post.date
              ? new Date(post.date).toLocaleDateString('en-US', {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
                })
              : null

            return (
              <article key={post.slug} className="cdcf-card group flex flex-col overflow-hidden p-0">
                {post.featuredImage?.node && (
                  <div className="aspect-video overflow-hidden">
                    <Image
                      src={post.featuredImage.node.sourceUrl}
                      alt={post.featuredImage.node.altText || post.title}
                      width={800}
                      height={450}
                      className="h-full w-full object-cover transition-transform group-hover:scale-105"
                    />
                  </div>
                )}

                <div className="flex flex-1 flex-col p-6">
                  <div className="flex items-center gap-3 text-xs text-gray-500">
                    {dateStr && <time>{dateStr}</time>}
                    {post.author?.node?.name && (
                      <>
                        <span>&middot;</span>
                        <span>{post.author.node.name}</span>
                      </>
                    )}
                  </div>

                  <h3 className="mt-2 font-serif text-lg font-bold text-cdcf-navy transition-colors group-hover:text-cdcf-gold">
                    {post.title}
                  </h3>

                  {post.excerpt && (
                    <div
                      className="cdcf-content mt-2 flex-1 text-sm leading-relaxed text-gray-600"
                      dangerouslySetInnerHTML={{ __html: post.excerpt }}
                    />
                  )}

                  {post.tags?.nodes?.length > 0 && (
                    <div className="mt-4 flex flex-wrap gap-2">
                      {post.tags.nodes.map((tag) => (
                        <span
                          key={tag.name}
                          className="rounded-full bg-cdcf-navy/5 px-2.5 py-0.5 text-xs font-medium text-cdcf-navy"
                        >
                          {tag.name}
                        </span>
                      ))}
                    </div>
                  )}
                </div>
              </article>
            )
          })}
        </div>
      </div>
    </section>
  )
}
