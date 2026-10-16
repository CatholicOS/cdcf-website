import { redirect } from 'next/navigation'
import { getTranslations } from 'next-intl/server'
import clsx from 'clsx'
import type { Session } from 'next-auth'
import { auth } from '@/lib/auth'
import { ReviewApiError, fetchInbox, fetchReviewMe } from '@/lib/review-api'
import { inboxHref } from '@/lib/review-inbox'
import type { InboxPage } from '@/lib/review-types'
import { Link } from '@/src/i18n/navigation'
import { InboxItemSummary } from '@/components/review/InboxItemSummary'
import { InboxMarkAllRead } from '@/components/review/InboxMarkAllRead'
import { ReviewNotice } from '@/components/review/ReviewNotice'
import { SignInAgain } from '@/components/review/SignInAgain'

export default async function ReviewInboxPage({
  searchParams,
}: {
  params: Promise<{ lang: string }>
  searchParams: Promise<{ unread?: string; page?: string }>
}) {
  const session = await auth()
  if (!session?.user) {
    redirect('/api/auth/signin')
  }
  const t = await getTranslations('Review')
  const query = await searchParams
  const unread = query.unread === '1'
  const page = Math.max(1, Number.parseInt(query.page ?? '1', 10) || 1)

  const loaded = await loadInbox(session, unread, page)
  if (loaded.kind === 'notice') {
    return (
      <ReviewNotice
        title={t('inbox.title')}
        message={t(loaded.message)}
        action={
          loaded.message === 'errors.sessionExpired' ? (
            <SignInAgain />
          ) : undefined
        }
      />
    )
  }
  const { inbox } = loaded
  const href = (u: boolean, p = 1) => {
    const params = new URLSearchParams()
    if (u) params.set('unread', '1')
    if (p > 1) params.set('page', String(p))
    const qs = params.toString()
    return `/review/inbox${qs ? `?${qs}` : ''}`
  }
  const now = new Date()

  return (
    <main className="cdcf-section">
      <div className="mx-auto max-w-3xl space-y-6">
        <header className="space-y-2">
          <Link
            href="/review"
            className="text-sm text-cdcf-navy hover:underline"
          >
            ← {t('backToList')}
          </Link>
          <h1 className="cdcf-heading text-3xl">{t('inbox.title')}</h1>
          <p className="text-gray-600">{t('inbox.intro')}</p>
        </header>
        <div className="flex items-center justify-between border-b border-gray-200">
          <nav className="flex gap-2" aria-label={t('inbox.title')}>
            {[false, true].map((u) => (
              <Link
                key={String(u)}
                href={href(u)}
                aria-current={u === unread ? 'page' : undefined}
                className={clsx(
                  '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                  u === unread
                    ? 'border-cdcf-navy text-cdcf-navy'
                    : 'border-transparent text-gray-500 hover:text-gray-800'
                )}
              >
                {u ? t('inbox.unread') : t('inbox.all')}
              </Link>
            ))}
          </nav>
          <InboxMarkAllRead disabled={inbox.unread === 0} />
        </div>
        {inbox.items.length === 0 ? (
          <p className="text-gray-500">{t('inbox.empty')}</p>
        ) : (
          <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
            {inbox.items.map((item) => (
              <li key={item.event_id}>
                <Link
                  href={inboxHref(item)}
                  className="flex items-start gap-3 px-4 py-3 text-sm hover:bg-gray-50"
                >
                  <span
                    className={
                      item.read
                        ? 'mt-1.5 h-2 w-2 shrink-0'
                        : 'mt-1.5 h-2 w-2 shrink-0 rounded-full bg-cdcf-navy'
                    }
                  >
                    {!item.read && (
                      <span className="sr-only">{t('inbox.unreadMarker')}</span>
                    )}
                  </span>
                  <InboxItemSummary item={item} now={now} />
                </Link>
              </li>
            ))}
          </ul>
        )}
        {(page > 1 || inbox.has_more) && (
          <nav className="flex justify-between text-sm">
            {page > 1 ? (
              <Link
                href={href(unread, page - 1)}
                className="text-cdcf-navy hover:underline"
              >
                ← {t('newer')}
              </Link>
            ) : (
              <span />
            )}
            {inbox.has_more && (
              <Link
                href={href(unread, page + 1)}
                className="text-cdcf-navy hover:underline"
              >
                {t('older')} →
              </Link>
            )}
          </nav>
        )}
      </div>
    </main>
  )
}

type InboxLoad =
  | { kind: 'ok'; inbox: InboxPage }
  | { kind: 'notice'; message: 'notMember' | 'errors.sessionExpired' }

// Data loading stays outside the component body so no JSX is built inside
// try/catch (react-hooks/error-boundaries).
async function loadInbox(
  session: Session,
  unread: boolean,
  page: number
): Promise<InboxLoad> {
  try {
    const me = await fetchReviewMe(session)
    if (!me.member) return { kind: 'notice', message: 'notMember' }
    return { kind: 'ok', inbox: await fetchInbox(session, { unread, page }) }
  } catch (err) {
    if (
      err instanceof ReviewApiError &&
      (err.status === 401 || err.status === 403)
    ) {
      return {
        kind: 'notice',
        message: err.status === 401 ? 'errors.sessionExpired' : 'notMember',
      }
    }
    throw err
  }
}
