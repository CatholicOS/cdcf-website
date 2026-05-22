import type { Metadata } from 'next'
import Image from 'next/image'
import { notFound } from 'next/navigation'
import { setRequestLocale, getTranslations } from 'next-intl/server'
import { getAuthorProfile, getPostBySlug, getPostPreview } from '@/lib/wordpress/api'
import { getPreviewTarget, previewMatchesSlug } from '@/lib/wordpress/preview'
import { stripHtml } from '@/lib/strip-html'
import { Link } from '@/src/i18n/navigation'
import ShareButtons from '@/components/blog/ShareButtons'
import DisqusComments from '@/components/blog/DisqusComments'
import AuthorBio from '@/components/blog/AuthorBio'
import JsonLd from '@/components/JsonLd'

/** Locale-aware absolute URL (default locale has no prefix). */
function absoluteUrl(lang: string, path: string): string {
  return `${SITE_URL}/${lang === 'en' ? '' : `${lang}/`}${path}`
}

interface BlogPostPageProps {
  params: Promise<{ lang: string; slug: string }>
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

  // In a preview session, render the draft post by id (it may have no usable
  // slug yet); otherwise fall through to the normal published lookup.
  const preview = await getPreviewTarget()
  const usePreview =
    preview?.type === 'post' && previewMatchesSlug(preview, slug)

  const [post, t] = await Promise.all([
    usePreview ? getPostPreview(preview.id) : getPostBySlug(slug, lang),
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

  // Resolve the (locale-aware) author profile for the byline link + bio card.
  // The nicename is used only to fetch; links use the derived, login-free slug.
  const authorNicename = post.author?.node?.slug
  const authorProfile = authorNicename
    ? await getAuthorProfile(authorNicename, lang)
    : null
  const authorName = authorProfile?.name ?? post.author?.node?.name

  // BlogPosting structured data (https://schema.org/BlogPosting), per Google's
  // Article structured-data technical guidelines.
  const articleUrl = absoluteUrl(lang, `blog/${slug}`)
  const articleSchema: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    mainEntityOfPage: { '@type': 'WebPage', '@id': articleUrl },
    headline: post.title.slice(0, 110),
    datePublished: post.date,
    dateModified: post.modified || post.date,
    ...(image && { image: [image.sourceUrl] }),
    ...(post.excerpt && { description: stripHtml(post.excerpt).slice(0, 250) }),
    ...(authorName && {
      author: {
        '@type': 'Person',
        name: authorName,
        ...(authorProfile && {
          url: absoluteUrl(lang, `blog/authors/${authorProfile.slug}`),
        }),
      },
    }),
    publisher: {
      '@type': 'Organization',
      name: 'Catholic Digital Commons Foundation',
      logo: { '@type': 'ImageObject', url: `${SITE_URL}/icon-512.png` },
    },
  }

  return (
    <article>
      <JsonLd data={articleSchema} />
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
          {authorName && (
            <>
              <span>&middot;</span>
              {authorProfile ? (
                <Link
                  href={`/blog/authors/${authorProfile.slug}`}
                  className="font-medium text-cdcf-navy transition-colors hover:text-cdcf-gold"
                >
                  {authorName}
                </Link>
              ) : (
                <span>{authorName}</span>
              )}
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

        {/* About the author */}
        {authorProfile && <AuthorBio profile={authorProfile} />}

        {/* Share buttons */}
        <ShareButtons title={post.title} />

        {/* Disqus comments */}
        <DisqusComments slug={slug} title={post.title} />
      </div>
    </article>
  )
}
