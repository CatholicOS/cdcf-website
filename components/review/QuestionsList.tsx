import { useTranslations } from 'next-intl'
import type { QuestionWithAnswers } from '@/lib/review-types'

/** Questions and answers are plain text. */
export function QuestionsList({
  questions,
}: {
  questions: QuestionWithAnswers[]
}) {
  const t = useTranslations('Review')
  if (questions.length === 0)
    return <p className="text-sm text-gray-500">{t('questions.none')}</p>
  return (
    <ul className="space-y-4">
      {questions.map((q) => (
        <li key={q.id} className="cdcf-card space-y-3">
          <p className="whitespace-pre-line text-sm text-gray-800">
            {q.body_md}
          </p>
          {q.answers.length === 0 ? (
            <p className="text-sm italic text-gray-500">
              {t('questions.awaiting')}
            </p>
          ) : (
            q.answers.map((a) => (
              <div key={a.id} className="border-l-2 border-cdcf-navy pl-3">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                  {t('questions.submitter')}
                </p>
                <p className="whitespace-pre-line text-sm text-gray-800">
                  {a.body_md}
                </p>
              </div>
            ))
          )}
        </li>
      ))}
    </ul>
  )
}
