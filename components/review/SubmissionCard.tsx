import { useFormatter, useTranslations } from 'next-intl'
import type { ReviewProject } from '@/lib/review-types'
import { parseWpGmt, safeHttpUrl } from '@/lib/review-ui'

/** Links only http(s) URLs; anything else is shown as plain text. */
function SubmittedLink({ url, className }: { url: string; className: string }) {
  const href = safeHttpUrl(url)
  if (href === null) return <span className={className}>{url}</span>
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer nofollow"
      className={`${className} text-cdcf-navy hover:underline`}
    >
      {url}
    </a>
  )
}

export function SubmissionCard({ project }: { project: ReviewProject }) {
  const t = useTranslations('Review')
  const format = useFormatter()
  const { fields, submitter } = project
  return (
    <section className="cdcf-card space-y-4">
      <div className="space-y-1">
        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
          {project.kind === 'referral'
            ? t('referralBadge')
            : t('submission.heading')}
        </p>
        <h1 className="cdcf-heading text-3xl">{project.title}</h1>
        <p className="text-xs text-gray-500">
          {t(project.kind === 'referral' ? 'referredOn' : 'submittedOn', {
            date: format.dateTime(parseWpGmt(project.submitted_at), {
              dateStyle: 'long',
            }),
          })}{' '}
          · {t(`stage.${project.stage}`)}
        </p>
      </div>
      {/* Public submission text: shown as plain text, never as HTML. */}
      <p className="whitespace-pre-line text-gray-800">{project.content}</p>
      <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        {fields.category && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t('submission.category')}
            </dt>
            <dd>{fields.category}</dd>
          </div>
        )}
        <div>
          <dt className="text-xs uppercase tracking-wide text-gray-500">
            {t('submission.language')}
          </dt>
          <dd>{project.language.toUpperCase()}</dd>
        </div>
        {fields.url && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t('submission.website')}
            </dt>
            <dd>
              <SubmittedLink url={fields.url} className="break-all" />
            </dd>
          </div>
        )}
        {fields.repo_urls.length > 0 && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t('submission.repositories')}
            </dt>
            <dd className="space-y-1">
              {fields.repo_urls.map((url) => (
                <SubmittedLink
                  key={url}
                  url={url}
                  className="block break-all"
                />
              ))}
            </dd>
          </div>
        )}
        {fields.tags.length > 0 && (
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">
              {t('submission.tags')}
            </dt>
            <dd>{fields.tags.join(', ')}</dd>
          </div>
        )}
        <div>
          <dt className="text-xs uppercase tracking-wide text-gray-500">
            {t(
              project.kind === 'referral'
                ? 'submission.referrer'
                : 'submission.submitter'
            )}
          </dt>
          <dd>
            {submitter.name}
            {submitter.email && (
              <span className="text-gray-500"> &lt;{submitter.email}&gt;</span>
            )}
          </dd>
        </div>
      </dl>
    </section>
  )
}
