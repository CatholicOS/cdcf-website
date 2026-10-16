import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
vi.mock('./submission-status-api', () => ({ fetchSubmissionStatus: vi.fn() }))

import { ReviewApiError } from './review-api'
import { fetchSubmissionStatus } from './submission-status-api'
import { loadSubmissionStatus } from './submission-status-load'

const IP = '203.0.113.9'
const TOKEN = 'a'.repeat(64)
let fetchMock: Mock

beforeEach(() => {
  fetchMock = fetchSubmissionStatus as unknown as Mock
  fetchMock.mockReset()
})

describe('loadSubmissionStatus', () => {
  it('returns null for a malformed token without calling WordPress', async () => {
    expect(await loadSubmissionStatus(40, 'nope', IP)).toBeNull()
    expect(await loadSubmissionStatus(40, '', IP)).toBeNull()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('returns null on 404', async () => {
    fetchMock.mockRejectedValue(new ReviewApiError('nf', 404, 'not_found'))
    expect(await loadSubmissionStatus(40, TOKEN, IP)).toBeNull()
  })

  it('returns null on 429 so the resend form still renders', async () => {
    fetchMock.mockRejectedValue(new ReviewApiError('slow', 429, 'rate_limited'))
    expect(await loadSubmissionStatus(40, TOKEN, IP)).toBeNull()
  })

  it('rethrows other errors', async () => {
    fetchMock.mockRejectedValue(new ReviewApiError('boom', 500, 'x'))
    await expect(loadSubmissionStatus(40, TOKEN, IP)).rejects.toThrow('boom')
  })

  it('returns the status on success', async () => {
    const status = { id: 40 }
    fetchMock.mockResolvedValue(status)
    expect(await loadSubmissionStatus(40, TOKEN, '203.0.113.9')).toBe(status)
    expect(fetchMock).toHaveBeenCalledWith(40, TOKEN, '203.0.113.9')
  })
})
