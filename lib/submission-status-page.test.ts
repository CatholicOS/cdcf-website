import { describe, expect, it, vi } from 'vitest'

vi.mock('next/headers', () => ({ cookies: vi.fn() }))
// review-api pulls in next-auth, which cannot load under the node test env.
vi.mock('@/lib/review-api', () => ({ ReviewApiError: class extends Error {} }))
vi.mock('next-intl/server', () => ({
  getTranslations: vi.fn(),
  getFormatter: vi.fn(),
}))
vi.mock('@/components/submission-status/AnswerForm', () => ({}))
vi.mock('@/components/submission-status/ResendLinkForm', () => ({}))
vi.mock('@/components/submission-status/StatusTimeline', () => ({}))
vi.mock('@/lib/submission-status-api', () => ({
  fetchSubmissionStatus: vi.fn(),
}))

import { metadata } from '@/app/[lang]/submission-status/[id]/page'

describe('submission status page metadata', () => {
  it('is never indexed and sends no referrer', () => {
    expect(metadata.robots).toEqual({ index: false, follow: false })
    expect(metadata.referrer).toBe('no-referrer')
  })
})
