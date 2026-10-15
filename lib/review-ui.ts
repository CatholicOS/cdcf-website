/**
 * Pure helpers for the review UI. No React, no fetch — unit tested.
 */
import {
  ATTACHMENT_LIMITS,
  IMAGE_EXTENSIONS,
  type PendingAttachment,
  type ReviewAttachment,
  type ReviewEntry,
  type ReviewProject,
  type ReviewTally,
  type ReviewVote,
} from '@/lib/review-types'

export type ReviewErrorKey =
  | 'sessionExpired'
  | 'forbidden'
  | 'locked'
  | 'stale'
  | 'reviewExists'
  | 'tooLarge'
  | 'unsupportedType'
  | 'tooMany'
  | 'invalidInput'
  | 'notFound'
  | 'generic'

const CODE_KEYS: Record<string, ReviewErrorKey> = {
  review_locked: 'locked',
  stale_entry: 'stale',
  review_exists: 'reviewExists',
  file_too_large: 'tooLarge',
  unsupported_type: 'unsupportedType',
  type_mismatch: 'unsupportedType',
  too_many_attachments: 'tooMany',
  no_token: 'sessionExpired',
  rest_not_logged_in: 'sessionExpired',
  unauthorized: 'sessionExpired',
}

export function reviewErrorKey(status: number, code?: string): ReviewErrorKey {
  if (code && CODE_KEYS[code]) return CODE_KEYS[code]
  if (status === 401) return 'sessionExpired'
  if (status === 403) return 'forbidden'
  if (status === 404) return 'notFound'
  if (status === 413) return 'tooLarge'
  if (status === 400) return 'invalidInput'
  return 'generic'
}

export function withAttachmentParam(search: string, id: number | null): string {
  const params = new URLSearchParams(search)
  if (id === null) params.delete('att')
  else params.set('att', String(id))
  const qs = params.toString()
  return qs ? `?${qs}` : ''
}

export function parseAttachmentParam(value: string | null): number | null {
  return value && /^[1-9]\d*$/.test(value) ? Number(value) : null
}

export interface TallySegment {
  key: ReviewVote | 'pending'
  count: number
  percent: number
}

export function tallySegments(
  tally: ReviewTally,
  pending: number
): TallySegment[] {
  const counts: Array<[TallySegment['key'], number]> = [
    ['cdcf', tally.cdcf],
    ['community', tally.community],
    ['reject', tally.reject],
    ['pending', pending],
  ]
  const total = counts.reduce((sum, [, n]) => sum + n, 0)
  return counts.map(([key, count]) => ({
    key,
    count,
    percent: total ? Math.round((count * 1000) / total) / 10 : 0,
  }))
}

export function parseWpGmt(value: string): Date {
  return new Date(`${value.replace(' ', 'T')}Z`)
}

export function formatBytes(bytes: number, locale = 'en'): string {
  if (bytes < 1024) return `${bytes} B`
  const oneDecimal = new Intl.NumberFormat(locale, {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  })
  if (bytes < 1024 * 1024) return `${oneDecimal.format(bytes / 1024)} KB`
  return `${oneDecimal.format(bytes / (1024 * 1024))} MB`
}

export function imageNeighbours(
  attachments: ReviewAttachment[],
  currentId: number
): { prev: number | null; next: number | null } {
  const images = attachments.filter((a) => a.kind === 'image')
  const i = images.findIndex((a) => a.id === currentId)
  if (i < 0) return { prev: null, next: null }
  return {
    prev: i > 0 ? images[i - 1].id : null,
    next: i < images.length - 1 ? images[i + 1].id : null,
  }
}

export type AttachmentIndex = Record<
  string,
  { attachment: ReviewAttachment; siblings: ReviewAttachment[] }
>

export function buildAttachmentIndex(project: ReviewProject): AttachmentIndex {
  const entries: ReviewEntry[] = [
    ...project.reviews.flatMap((r) => [r, ...r.replies]),
    ...project.discussion,
    ...project.questions.flatMap((q) => [q, ...q.answers]),
  ]
  const index: AttachmentIndex = {}
  for (const entry of entries) {
    for (const attachment of entry.attachments) {
      index[String(attachment.id)] = {
        attachment,
        siblings: entry.attachments,
      }
    }
  }
  return index
}

export function checkAttachmentFile(
  name: string,
  size: number
): ReviewErrorKey | null {
  const ext = name.includes('.')
    ? (name.split('.').pop() ?? '').toLowerCase()
    : ''
  if (ext === 'md')
    return size > ATTACHMENT_LIMITS.markdownBytes ? 'tooLarge' : null
  if ((IMAGE_EXTENSIONS as readonly string[]).includes(ext)) {
    return size > ATTACHMENT_LIMITS.imageBytes ? 'tooLarge' : null
  }
  return 'unsupportedType'
}

export function pendingName(p: PendingAttachment): string {
  if (p.kind === 'file') return p.file.name
  const title = p.title.trim()
  return `${title !== '' ? title : 'notes'}.md`
}

/**
 * The URL as an http(s) href, or null. Submission URLs come from the
 * public form; WordPress already strips unsafe schemes, but the review
 * page renders them for Board members, so check again before linking.
 */
export function safeHttpUrl(value: string): string | null {
  try {
    const url = new URL(value.trim())
    return url.protocol === 'http:' || url.protocol === 'https:'
      ? url.href
      : null
  } catch {
    return null
  }
}
