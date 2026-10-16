import { describe, expect, it, vi } from 'vitest'

vi.mock('next/headers', () => ({
  cookies: vi.fn(async () => ({ get: () => ({ value: 'a'.repeat(64) }) })),
  headers: vi.fn(
    async () => new Headers({ 'x-forwarded-for': '6.6.6.6, 203.0.113.9' })
  ),
}))
vi.mock('@/lib/submission-status-load', () => ({
  loadSubmissionStatus: vi.fn(async () => null),
}))
// review-api pulls in next-auth, which cannot load under the node test env.
vi.mock('@/lib/review-api', () => ({ ReviewApiError: class extends Error {} }))
vi.mock('next-intl/server', () => ({
  getTranslations: vi.fn(async () => (key: string) => key),
  getFormatter: vi.fn(),
}))
vi.mock('@/components/submission-status/AnswerForm', () => ({
  AnswerForm: () => null,
}))
vi.mock('@/components/submission-status/ResendLinkForm', () => ({
  ResendLinkForm: () => null,
}))
vi.mock('@/components/submission-status/StatusTimeline', () => ({
  StatusTimeline: () => null,
}))
vi.mock('@/lib/submission-status-api', () => ({
  fetchSubmissionStatus: vi.fn(),
}))

import SubmissionStatusPage, {
  metadata,
} from '@/app/[lang]/submission-status/[id]/page'
import { loadSubmissionStatus } from '@/lib/submission-status-load'

describe('submission status page metadata', () => {
  it('is never indexed and sends no referrer', () => {
    expect(metadata.robots).toEqual({ index: false, follow: false })
    expect(metadata.referrer).toBe('no-referrer')
  })
})

describe('submission status page', () => {
  it('loads the status for the visitor the proxy saw', async () => {
    await SubmissionStatusPage({
      params: Promise.resolve({ lang: 'en', id: '40' }),
    })
    expect(loadSubmissionStatus).toHaveBeenCalledWith(
      40,
      'a'.repeat(64),
      '203.0.113.9'
    )
  })
})
