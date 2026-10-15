/**
 * Browser-side calls to /api/review/*. Never throws: every outcome is a
 * ReviewResult the UI can render.
 */
import type { PendingAttachment, ReviewAttachment } from '@/lib/review-types'
import { reviewErrorKey, type ReviewErrorKey } from '@/lib/review-ui'

export type ReviewResult<T> =
  | { ok: true; data: T }
  | { ok: false; status: number; code?: string; errorKey: ReviewErrorKey }

export async function reviewFetch<T>(
  url: string,
  init: RequestInit = {}
): Promise<ReviewResult<T>> {
  let response: Response
  try {
    // Browser fetch to this app's own /api/review/* routes; callers pass
    // fixed paths, never user-supplied hosts, so this is not SSRF.
    // nosemgrep
    response = await fetch(url, { cache: 'no-store', ...init })
  } catch {
    return { ok: false, status: 0, errorKey: 'generic' }
  }
  let body: unknown = null
  try {
    body = await response.json()
  } catch {
    body = null
  }
  if (response.ok) return { ok: true, data: body as T }
  const code =
    body &&
    typeof body === 'object' &&
    typeof (body as { error?: unknown }).error === 'string'
      ? (body as { error: string }).error
      : undefined
  return {
    ok: false,
    status: response.status,
    code,
    errorKey: reviewErrorKey(response.status, code),
  }
}

export function jsonInit(method: string, payload: unknown): RequestInit {
  return {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  }
}

export function uploadAttachment(
  entryId: number,
  pending: PendingAttachment
): Promise<ReviewResult<ReviewAttachment>> {
  const url = `/api/review/entries/${entryId}/attachments`
  if (pending.kind === 'file') {
    const form = new FormData()
    form.append('file', pending.file, pending.file.name)
    return reviewFetch<ReviewAttachment>(url, { method: 'POST', body: form })
  }
  return reviewFetch<ReviewAttachment>(
    url,
    jsonInit('POST', { title: pending.title, markdown: pending.markdown })
  )
}

/**
 * Upload attachments one at a time (parallel uploads would race the
 * per-entry cap and each other's error reporting) and return the ones
 * that failed, so the caller can keep them pending and show why.
 */
export async function uploadAll(
  entryId: number,
  pending: PendingAttachment[]
): Promise<{ pending: PendingAttachment; errorKey: ReviewErrorKey }[]> {
  const failures: { pending: PendingAttachment; errorKey: ReviewErrorKey }[] =
    []
  for (const item of pending) {
    const result = await uploadAttachment(entryId, item)
    if (!result.ok) failures.push({ pending: item, errorKey: result.errorKey })
  }
  return failures
}
