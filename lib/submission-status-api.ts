import 'server-only'
import { ReviewApiError, getWpRestUrl } from '@/lib/review-api'
import type {
  AnswerResult,
  SubmissionStatus,
} from '@/lib/submission-status-types'

/**
 * Calls to the public submission-status endpoints. The token goes in a
 * header (never the URL, so it stays out of WordPress access logs).
 */
async function statusRequest<T>(
  path: string,
  init: { method?: string; token?: string; json?: unknown } = {}
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (init.token) headers['X-CDCF-Submission-Token'] = init.token
  if (init.json !== undefined) headers['Content-Type'] = 'application/json'
  // The URL is the configured WordPress base plus a fixed endpoint path
  // and an integer id; no part of it comes from user input.
  // nosemgrep
  const response = await fetch(`${getWpRestUrl()}${path}`, {
    method: init.method ?? 'GET',
    headers,
    body: init.json === undefined ? undefined : JSON.stringify(init.json),
    cache: 'no-store',
  })
  const body = (await response.json().catch(() => null)) as {
    code?: string
    message?: string
  } | null
  if (!response.ok) {
    throw new ReviewApiError(
      body?.message ?? `HTTP ${response.status}`,
      response.status,
      body?.code
    )
  }
  return body as T
}

export function fetchSubmissionStatus(
  projectId: number,
  token: string
): Promise<SubmissionStatus> {
  return statusRequest<SubmissionStatus>(
    `/cdcf/v1/submission-status/${projectId}`,
    { token }
  )
}

export function postSubmissionAnswer(
  projectId: number,
  token: string,
  payload: { question_id: number; body: string }
): Promise<AnswerResult> {
  return statusRequest<AnswerResult>(
    `/cdcf/v1/submission-status/${projectId}/answer`,
    { method: 'POST', token, json: payload }
  )
}

export async function requestStatusLink(payload: {
  project_id: number
  email: string
}): Promise<void> {
  await statusRequest<unknown>('/cdcf/v1/submission-status/resend-link', {
    method: 'POST',
    json: payload,
  })
}
