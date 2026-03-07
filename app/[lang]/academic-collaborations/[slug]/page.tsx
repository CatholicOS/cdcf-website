import type { Metadata } from 'next'
import Image from 'next/image'
import { notFound } from 'next/navigation'
import { setRequestLocale, getTranslations } from 'next-intl/server'
import { getAcademicCollaboration } from '@/lib/wordpress/api'
import { Link } from '@/src/i18n/navigation'
import GovernanceSection from '@/components/sections/GovernanceSection'
import ProjectGrid from '@/components/sections/ProjectGrid'

interface AcademicCollaborationPageProps {
  params: Promise<{ lang: string; slug: string }>
}

function stripHtml(html: string): string {
  return html.replace(/<[^>]*>/g, '').trim()
}

const SITE_URL =
  process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

export async function generateMetadata({
  params,
}: AcademicCollaborationPageProps): Promise<Metadata> {
  const { lang, slug } = await params
  const collab = await getAcademicCollaboration(slug, lang)

  if (!collab) return {}

  const description = collab.collaborationFields.collabDescription
    ? collab.collaborationFields.collabDescription.slice(0, 160)
    : collab.content
      ? stripHtml(collab.content).slice(0, 160)
      : undefined
  const image = collab.featuredImage?.node

  return {
    title: collab.title,
    description,
    openGraph: {
      title: collab.title,
      description,
      type: 'article',
      ...(image && {
        images: [
          {
            url: image.sourceUrl,
            width: image.mediaDetails?.width,
            height: image.mediaDetails?.height,
            alt: image.altText || collab.title,
          },
        ],
      }),
    },
    twitter: {
      card: image ? 'summary_large_image' : 'summary',
      title: collab.title,
      description,
      ...(image && { images: [image.sourceUrl] }),
    },
    alternates: {
      canonical: `${SITE_URL}/${lang === 'en' ? '' : `${lang}/`}academic-collaborations/${slug}`,
    },
  }
}

export default async function AcademicCollaborationPage({
  params,
}: AcademicCollaborationPageProps) {
  const { lang, slug } = await params
  setRequestLocale(lang)

  const [collab, t] = await Promise.all([
    getAcademicCollaboration(slug, lang),
    getTranslations('academicCollaborations'),
  ])

  if (!collab) {
    notFound()
  }

  const image = collab.featuredImage?.node
  const fields = collab.collaborationFields

  return (
    <article>
      <div className="cdcf-section mx-auto max-w-3xl">
        {/* University logo */}
        {image && (
          <div className="mt-8 flex justify-center">
            <Image
              src={image.sourceUrl}
              alt={image.altText || collab.title}
              width={image.mediaDetails?.width || 200}
              height={image.mediaDetails?.height || 200}
              className="max-h-28 object-contain"
              priority
            />
          </div>
        )}

        {/* Back link */}
        <Link
          href="/community#academic-collaborations"
          className="inline-flex items-center text-sm text-cdcf-gold hover:text-cdcf-gold-600 transition-colors"
        >
          &larr; {t('backToCommunity')}
        </Link>

        {/* Header */}
        <div className="mt-6">
          <h1 className="cdcf-heading text-3xl sm:text-4xl lg:text-5xl">
            {collab.title}
          </h1>

          {fields.collabDepartment && (
            <span className="mt-2 inline-block rounded-full bg-cdcf-navy/10 px-3 py-1 text-sm font-medium text-cdcf-navy">
              {fields.collabDepartment}
            </span>
          )}
        </div>

        <div className="cdcf-divider" />

        {/* Body content */}
        {collab.content && (
          <div
            className="prose prose-lg max-w-none cdcf-content"
            dangerouslySetInnerHTML={{ __html: collab.content }}
          />
        )}

        {/* Website link */}
        {fields.collabWebsiteUrl && (
          <div className="mt-10">
            <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wide">
              {t('website')}
            </h3>
            <a
              href={fields.collabWebsiteUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-1 inline-flex items-center gap-1 text-cdcf-navy font-medium hover:text-cdcf-gold transition-colors"
            >
              {fields.collabWebsiteUrl}
              <svg
                className="h-4 w-4"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={2}
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                />
              </svg>
            </a>
          </div>
        )}
      </div>

      {/* Related projects */}
      {fields.collabProjects?.nodes && fields.collabProjects.nodes.length > 0 && (
        <ProjectGrid
          projects={fields.collabProjects.nodes}
          title={t('relatedProjects')}
        />
      )}

      {/* Governance contacts */}
      {fields.collabGovernance?.nodes && fields.collabGovernance.nodes.length > 0 && (
        <GovernanceSection
          members={fields.collabGovernance.nodes}
          columns={fields.collabGovernance.nodes.length === 1 ? 2 : 3}
        />
      )}
    </article>
  )
}
