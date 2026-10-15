/**
 * Shapes of the council review API (`/cdcf/v1/review/*`), shared by the
 * server-only client, route handlers and client components. No runtime
 * dependencies, so client components can import it.
 */

export type ReviewVote = 'cdcf' | 'community' | 'reject'
export const REVIEW_VOTES: readonly ReviewVote[] = [
  'cdcf',
  'community',
  'reject',
]

export type ReviewStage = 'in_review' | 'awaiting_submitter' | 'decided'
export const REVIEW_STAGES: readonly ReviewStage[] = [
  'in_review',
  'awaiting_submitter',
  'decided',
]

export type Council = 'technical' | 'ecclesial' | 'board'

export interface ReviewTally {
  cdcf: number
  community: number
  reject: number
}

export interface ReviewMe {
  user_id: number
  member: boolean
  councils: Council[]
  can: { view: boolean; discuss: boolean; vote: boolean; decide: boolean }
}

export interface ReviewProjectSummary {
  id: number
  title: string
  category: string
  language: string
  submitted_at: string
  stage: ReviewStage
  tally: ReviewTally
  voters_pending: number
  my_vote: ReviewVote | null
  unread: number
}

export interface ReviewProjectList {
  projects: ReviewProjectSummary[]
  page: number
  has_more: boolean
}

export interface ReviewAttachment {
  id: number
  kind: 'markdown' | 'image'
  mime: string
  name: string
  size: number
}

export interface ReviewAuthor {
  id: number
  name: string
  councils: Council[]
}

export interface ReviewEntry {
  id: number
  kind: 'review' | 'reply' | 'discussion' | 'question' | 'answer'
  parent_id: number | null
  body_md: string
  vote: ReviewVote | null
  author: ReviewAuthor | null
  created_at: string
  edited_at: string | null
  version: string
  attachments: ReviewAttachment[]
}

export interface ReviewWithReplies extends ReviewEntry {
  replies: ReviewEntry[]
}

export interface QuestionWithAnswers extends ReviewEntry {
  answers: ReviewEntry[]
}

export interface ReviewProject {
  id: number
  title: string
  content: string
  post_type: string
  post_status: string
  stage: ReviewStage
  language: string
  submitted_at: string
  fields: {
    url: string
    category: string
    repo_urls: string[]
    tags: string[]
  }
  submitter: { name: string; email: string | null }
  tally: ReviewTally
  plurality: ReviewVote | null
  voters_pending: number
  reviews: ReviewWithReplies[]
  discussion: ReviewEntry[]
  questions: QuestionWithAnswers[]
  me: { can_vote: boolean; can_decide: boolean; review_id: number | null }
}

export const ATTACHMENT_LIMITS = {
  markdownBytes: 512 * 1024,
  imageBytes: 5 * 1024 * 1024,
  perEntry: 10,
} as const

export const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'] as const

export type PendingAttachment =
  | { key: string; kind: 'file'; file: File }
  | { key: string; kind: 'paste'; title: string; markdown: string }
