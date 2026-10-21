import type { SubmissionStatus } from '@/lib/submission-status-types'

export type TimelineStep = {
  key: 'received' | 'review' | 'question' | 'decision'
  state: 'done' | 'current' | 'upcoming'
}

export function timelineSteps(
  status: Pick<SubmissionStatus, 'stage'>
): TimelineStep[] {
  const decided = status.stage === 'decided'
  const awaiting = status.stage === 'awaiting_submitter'
  const steps: TimelineStep[] = [
    { key: 'received', state: 'done' },
    { key: 'review', state: decided || awaiting ? 'done' : 'current' },
  ]
  if (awaiting) steps.push({ key: 'question', state: 'current' })
  steps.push({ key: 'decision', state: decided ? 'done' : 'upcoming' })
  return steps
}

export type AnswerErrorKey =
  | 'alreadyAnswered'
  | 'closed'
  | 'rejected'
  | 'tooLong'
  | 'empty'
  | 'expired'
  | 'rateLimited'
  | 'generic'

const ANSWER_ERRORS: Record<string, AnswerErrorKey> = {
  already_answered: 'alreadyAnswered',
  review_closed: 'closed',
  answer_rejected: 'rejected',
  body_too_long: 'tooLong',
  empty_body: 'empty',
  not_found: 'expired',
  rate_limited: 'rateLimited',
}

export function answerErrorKey(code: string | undefined): AnswerErrorKey {
  return (code && ANSWER_ERRORS[code]) || 'generic'
}

export function statusHeadingKeys(kind: SubmissionStatus['kind'] | undefined): {
  title: 'title' | 'referralTitle' | 'reviewTitle'
  submittedOn: 'submittedOn' | 'referredOn'
} {
  if (kind === 'referral') {
    return { title: 'referralTitle', submittedOn: 'referredOn' }
  }
  // No status loaded (an invalid link): the visitor may be a referrer.
  return { title: kind ? 'title' : 'reviewTitle', submittedOn: 'submittedOn' }
}
