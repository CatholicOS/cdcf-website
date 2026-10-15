import 'server-only'
import { ReviewApiError } from '@/lib/review-api'
import { fetchSubmissionStatus } from '@/lib/submission-status-api'
import type { SubmissionStatus } from '@/lib/submission-status-types'
import { STATUS_TOKEN_PATTERN } from '@/lib/submission-token'

/**
 * Load a submission's status for the status page. Returns null whenever the
 * link cannot be used: a malformed token, a token WordPress rejects (404), or
 * a spent failed-token budget (429, shared per project). In each case the page
 * shows the "email me a new link" form rather than an error page.
 */
export async function loadSubmissionStatus(
  id: number,
  token: string
): Promise<SubmissionStatus | null> {
  if (!STATUS_TOKEN_PATTERN.test(token)) return null
  try {
    return await fetchSubmissionStatus(id, token)
  } catch (err) {
    if (
      err instanceof ReviewApiError &&
      (err.status === 404 || err.status === 429)
    )
      return null
    throw err
  }
}
