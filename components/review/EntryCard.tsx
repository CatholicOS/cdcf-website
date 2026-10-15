import type { ReactNode } from 'react'
import { useFormatter, useTranslations } from 'next-intl'
import type { ReviewEntry } from '@/lib/review-types'
import { parseWpGmt } from '@/lib/review-ui'
import { AttachmentChips } from './AttachmentChips'
import { EntryActions } from './EntryActions'
import { MarkdownView } from './MarkdownView'

export function EntryCard({
  entry,
  currentUserId,
  locked,
  allowEdit,
  badge,
}: {
  entry: ReviewEntry
  currentUserId: number
  locked: boolean
  allowEdit: boolean
  badge?: ReactNode
}) {
  const t = useTranslations('Review')
  const format = useFormatter()
  const isMine = entry.author?.id === currentUserId
  return (
    <article id={`entry-${entry.id}`} className="space-y-2">
      <header className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span className="font-semibold text-gray-900">
          {entry.author ? entry.author.name : t('questions.submitter')}
        </span>
        {entry.author?.councils.map((c) => (
          <span
            key={c}
            className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600"
          >
            {t(`council.${c}`)}
          </span>
        ))}
        {badge}
        <span className="text-xs text-gray-500">
          {format.dateTime(parseWpGmt(entry.created_at), {
            dateStyle: 'medium',
            timeStyle: 'short',
          })}
          {entry.edited_at && ` · ${t('edited')}`}
        </span>
      </header>
      <MarkdownView source={entry.body_md} />
      <AttachmentChips attachments={entry.attachments} />
      {isMine && !locked && (
        <EntryActions
          entryId={entry.id}
          body={entry.body_md}
          version={entry.version}
          allowEdit={allowEdit}
        />
      )}
    </article>
  )
}
