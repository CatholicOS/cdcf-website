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
  decision: ReviewDecisionRecord | null
  decision_email: ReviewDecisionEmail | null
}

export interface ReviewDecisionRecord {
  decision: ReviewVote
  rationale: string
  summary: string
  decided_at: number
  decided_by: { id: number; name: string } | null
  admin_override: boolean
}

export interface ReviewDecisionEmail {
  language: string
  subject: string
  body: string
  outcomes: Record<ReviewVote, string>
}

export interface ReviewDecisionPayload {
  decision: ReviewVote
  rationale: string
  submitter_summary: string
}

export interface ReviewDecisionResult {
  id: number
  stage: 'decided'
  decision: ReviewVote
  post_type: string
  post_status: string
  warnings: string[]
}

export const DECISION_SUMMARY_MAX = 10000

export const ATTACHMENT_LIMITS = {
  markdownBytes: 512 * 1024,
  imageBytes: 5 * 1024 * 1024,
  perEntry: 10,
} as const

export const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'] as const

export type PendingAttachment =
  | { key: string; kind: 'file'; file: File }
  | { key: string; kind: 'paste'; title: string; markdown: string }

export type ReviewEventType =
  | 'submission_received'
  | 'review_posted'
  | 'review_edited'
  | 'vote_changed'
  | 'reply_posted'
  | 'discussion_posted'
  | 'attachment_added'
  | 'question_sent'
  | 'submitter_answered'
  | 'decision_recorded'
  | 'admin_override'

export interface InboxItem {
  event_id: number
  type: ReviewEventType
  project: { id: number; title: string }
  /** null for the submitter's own actions and migrated records */
  actor: { id: number; name: string } | null
  entry_id: number | null
  data: Record<string, unknown>
  /** UTC, as WordPress stores it: 'Y-m-d H:i:s.u' */
  created_at: string
  read: boolean
  /** a reply to the viewer's own review */
  to_you: boolean
}

export interface InboxPage {
  items: InboxItem[]
  page: number
  has_more: boolean
  unread: number
}

export interface InboxCount {
  unread: number
}

export type InboxReadPayload = { event_ids: number[] } | { all: true }

export const INBOX_READ_MAX = 200
