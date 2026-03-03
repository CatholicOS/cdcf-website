import type { Metadata } from 'next'
import Image from 'next/image'
import { notFound } from 'next/navigation'
import { setRequestLocale, getTranslations } from 'next-intl/server'
import { getPostBySlug } from '@/lib/wordpress/api'
import { Link } from '@/src/i18n/navigation'
import ShareButtons from '@/components/blog/ShareButtons'
import DisqusComments from '@/components/blog/DisqusComments'

interface BlogPostPageProps {
  params: Promise<{ lang: string; slug: string }>
}

function stripHtml(html: string): string {
  return html.replace(/<[^>]*>/g, '').trim()
}

const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

export async function generateMetadata({ params }: BlogPostPageProps): Promise<Metadata> {
  const { lang, slug } = await params
  const post = await getPostBySlug(slug, lang)

  if (!post) return {}

  const description = post.excerpt ? stripHtml(post.excerpt).slice(0, 160) : undefined
  const image = post.featuredImage?.node

  return {
    title: post.title,
    description,
    openGraph: {
      title: post.title,
      description,
      type: 'article',
      ...(image && {
        images: [
          {
            url: image.sourceUrl,
            width: image.mediaDetails?.width,
            height: image.mediaDetails?.height,
            alt: image.altText || post.title,
          },
        ],
      }),
    },
    twitter: {
      card: image ? 'summary_large_image' : 'summary',
      title: post.title,
      description,
      ...(image && { images: [image.sourceUrl] }),
    },
    alternates: {
      canonical: `${SITE_URL}/${lang === 'en' ? '' : `${lang}/`}blog/${slug}`,
    },
  }
}

export default async function BlogPostPage({ params }: BlogPostPageProps) {
  const { lang, slug } = await params
  setRequestLocale(lang)

  const [post, t] = await Promise.all([
    getPostBySlug(slug, lang),
    getTranslations('blog'),
  ])

  if (!post) {
    notFound()
  }

  const dateStr = post.date
    ? new Date(post.date).toLocaleDateString(lang, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
      })
    : null

  const image = post.featuredImage?.node

  return (
    <article>
      {/* Featured image hero */}
      {image && (
        <div className="relative h-64 w-full sm:h-80 lg:h-96">
          <Image
            src={image.sourceUrl}
            alt={image.altText || post.title}
            fill
            className="object-cover"
            priority
          />
          <div className="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent" />
        </div>
      )}

      <div className="cdcf-section mx-auto max-w-3xl">
        {/* Back link */}
        <Link
          href="/blog"
          className="inline-flex items-center text-sm text-cdcf-gold hover:text-cdcf-gold-600 transition-colors"
        >
          &larr; {t('backToBlog')}
        </Link>

        {/* Post header */}
        <h1 className="cdcf-heading mt-6 text-3xl sm:text-4xl lg:text-5xl">
          {post.title}
        </h1>

        <div className="mt-4 flex flex-wrap items-center gap-3 text-sm text-gray-500">
          {dateStr && <time dateTime={post.date}>{dateStr}</time>}
          {post.author?.node?.name && (
            <>
              <span>&middot;</span>
              <span>{post.author.node.name}</span>
            </>
          )}
        </div>

        {post.tags?.nodes?.length > 0 && (
          <div className="mt-4 flex flex-wrap gap-2">
            {post.tags.nodes.map((tag) => (
              <span
                key={tag.name}
                className="rounded-full bg-cdcf-navy/5 px-3 py-1 text-xs font-medium text-cdcf-navy"
              >
                {tag.name}
              </span>
            ))}
          </div>
        )}

        <div className="cdcf-divider" />

        {/* Post content */}
        {post.content && (
          <div
            className="prose prose-lg max-w-none cdcf-content"
            dangerouslySetInnerHTML={{ __html: post.content }}
          />
        )}

        {/* Share buttons */}
        <ShareButtons title={post.title} />

        {/* Disqus comments */}
        <DisqusComments slug={slug} title={post.title} />
      </div>
    </article>
  )
}
