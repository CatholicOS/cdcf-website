import type { Metadata } from 'next'
import Image from 'next/image'
import { notFound } from 'next/navigation'
import { setRequestLocale, getTranslations } from 'next-intl/server'
import { getProject } from '@/lib/wordpress/api'
import { Link } from '@/src/i18n/navigation'
import RepoLanguages from '@/components/projects/RepoLanguages'
import ShareButtons from '@/components/blog/ShareButtons'
import GovernanceSection from '@/components/sections/GovernanceSection'
import striptags from 'striptags'

interface ProjectPageProps {
  params: Promise<{ lang: string; slug: string }>
}

function stripHtml(html: string): string {
  return striptags(html).trim()
}

const SITE_URL =
  process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

type ProjectStatus = 'incubating' | 'active' | 'archived'

const statusStyles: Record<ProjectStatus, { bg: string; text: string }> = {
  incubating: { bg: 'bg-amber-100', text: 'text-amber-800' },
  active: { bg: 'bg-green-100', text: 'text-green-800' },
  archived: { bg: 'bg-gray-100', text: 'text-gray-500' },
}

export async function generateMetadata({
  params,
}: ProjectPageProps): Promise<Metadata> {
  const { lang, slug } = await params
  const project = await getProject(slug, lang)

  if (!project) return {}

  const description = project.excerpt
    ? stripHtml(project.excerpt).slice(0, 160)
    : undefined
  const image = project.featuredImage?.node

  return {
    title: project.title,
    description,
    openGraph: {
      title: project.title,
      description,
      type: 'article',
      ...(image && {
        images: [
          {
            url: image.sourceUrl,
            width: image.mediaDetails?.width,
            height: image.mediaDetails?.height,
            alt: image.altText || project.title,
          },
        ],
      }),
    },
    twitter: {
      card: image ? 'summary_large_image' : 'summary',
      title: project.title,
      description,
      ...(image && { images: [image.sourceUrl] }),
    },
    alternates: {
      canonical: `${SITE_URL}/${lang === 'en' ? '' : `${lang}/`}projects/${slug}`,
    },
  }
}

export default async function ProjectPage({ params }: ProjectPageProps) {
  const { lang, slug } = await params
  setRequestLocale(lang)

  const [project, t] = await Promise.all([
    getProject(slug, lang),
    getTranslations('projects'),
  ])

  if (!project) {
    notFound()
  }

  const image = project.featuredImage?.node
  const status = (project.projectFields.projectStatus?.[0] ||
    'incubating') as ProjectStatus
  const style = statusStyles[status] || statusStyles.incubating

  // Collect all repo URLs: prefer projectRepoUrls, fall back to single URL
  const repoUrls: string[] =
    project.projectRepoUrls ??
    (project.projectFields.projectRepoUrl
      ? [project.projectFields.projectRepoUrl]
      : [])

  return (
    <article>
      {/* Featured image hero */}
      {image && (
        <div className="relative h-64 w-full sm:h-80 lg:h-96">
          <Image
            src={image.sourceUrl}
            alt={image.altText || project.title}
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
          href="/projects"
          className="inline-flex items-center text-sm text-cdcf-gold hover:text-cdcf-gold-600 transition-colors"
        >
          &larr; {t('backToProjects')}
        </Link>

        {/* Project header */}
        <div className="mt-6 flex flex-wrap items-center gap-3">
          <h1 className="cdcf-heading text-3xl sm:text-4xl lg:text-5xl">
            {project.title}
          </h1>
          <span
            className={`rounded-full px-3 py-1 text-xs font-medium ${style.bg} ${style.text}`}
          >
            {t(`status.${status}`)}
          </span>
        </div>

        {project.projectFields.projectCategory && (
          <span className="mt-2 inline-block text-sm font-medium text-cdcf-gold">
            {project.projectFields.projectCategory}
          </span>
        )}

        {project.projectTags?.nodes?.length > 0 && (
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold text-gray-500 uppercase tracking-wide">
              {t('tags')}
            </span>
            {project.projectTags.nodes.map((tag) => (
              <span
                key={tag.name}
                className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600"
              >
                {tag.name}
              </span>
            ))}
          </div>
        )}

        <div className="cdcf-divider" />

        {/* Project content */}
        {project.content && (
          <div
            className="prose prose-lg max-w-none cdcf-content"
            dangerouslySetInnerHTML={{ __html: project.content }}
          />
        )}

        {/* Project details */}
        <div className="mt-10 space-y-6">
          {/* Website */}
          {project.projectFields.projectUrl && (
            <div>
              <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wide">
                {t('website')}
              </h3>
              <a
                href={project.projectFields.projectUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="mt-1 inline-flex items-center gap-1 text-cdcf-navy font-medium hover:text-cdcf-gold transition-colors"
              >
                {project.projectFields.projectUrl}
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

          {/* License */}
          {project.projectFields.projectLicense && (
            <div>
              <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wide">
                {t('license')}
              </h3>
              <p className="mt-1 text-gray-700">
                {project.projectFields.projectLicense}
              </p>
            </div>
          )}

          {/* Repositories */}
          {repoUrls.length > 0 && (
            <div>
              <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wide">
                {t('repositories')}
              </h3>
              <ul className="mt-2 space-y-2">
                {repoUrls.map((url) => (
                  <li key={url}>
                    <a
                      href={url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-2 text-cdcf-navy font-medium hover:text-cdcf-gold transition-colors"
                    >
                      {/* GitHub icon */}
                      <svg
                        className="h-5 w-5"
                        fill="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z" />
                      </svg>
                      {url.replace(/^https?:\/\//, '')}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Languages */}
          {repoUrls.length > 0 && (
            <RepoLanguages repos={repoUrls} label={t('languages')} />
          )}
        </div>

        {/* Project leads */}
        {project.projectFields.projectLeads?.nodes &&
          project.projectFields.projectLeads.nodes.length > 0 && (
            <GovernanceSection
              members={project.projectFields.projectLeads.nodes}
              title={t('projectLeads')}
              columns={project.projectFields.projectLeads.nodes.length === 1 ? 2 : 3}
            />
          )}

        {/* Share buttons */}
        <ShareButtons title={project.title} namespace="projects" />
      </div>
    </article>
  )
}
