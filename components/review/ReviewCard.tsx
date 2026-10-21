import { useTranslations } from 'next-intl'
import type { ReviewKind, ReviewWithReplies } from '@/lib/review-types'
import { voteLabelKey } from '@/lib/review-ui'
import { EntryCard } from './EntryCard'
import { EntryComposer } from './EntryComposer'

export function ReviewCard({
  review,
  kind,
  projectId,
  currentUserId,
  locked,
}: {
  review: ReviewWithReplies
  kind: ReviewKind
  projectId: number
  currentUserId: number
  locked: boolean
}) {
  const t = useTranslations('Review')
  return (
    <li className="cdcf-card space-y-4">
      <EntryCard
        entry={review}
        currentUserId={currentUserId}
        locked={locked}
        allowEdit={false}
        badge={
          review.vote && (
            <span className="rounded bg-cdcf-navy px-1.5 py-0.5 text-xs font-medium text-white">
              {t(voteLabelKey(kind, review.vote) as 'vote.cdcf')}
            </span>
          )
        }
      />
      {review.replies.length > 0 && (
        <ul className="space-y-4 border-l-2 border-gray-100 pl-4">
          {review.replies.map((reply) => (
            <li key={reply.id}>
              <EntryCard
                entry={reply}
                currentUserId={currentUserId}
                locked={locked}
                allowEdit
              />
            </li>
          ))}
        </ul>
      )}
      {!locked && (
        <div className="pl-4">
          <EntryComposer
            projectId={projectId}
            kind="reply"
            parentId={review.id}
          />
        </div>
      )}
    </li>
  )
}
