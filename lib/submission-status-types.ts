export type SubmissionStage = 'in_review' | 'awaiting_submitter' | 'decided'

export type SubmissionQuestion = {
  id: number
  body: string
  asked_at: string
  answer: { body: string; answered_at: string } | null
}

export type SubmissionStatus = {
  id: number
  title: string
  submitted_at: string
  language: string
  stage: SubmissionStage
  kind: 'referral' | 'submission'
  questions: SubmissionQuestion[]
  decision: {
    outcome: 'cdcf' | 'community' | 'reject'
    summary: string
    decided_at: number
  } | null
  public_url: string | null
}

export type AnswerResult = {
  id: number
  question_id: number
  stage: SubmissionStage
}
