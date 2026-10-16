import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from 'vitest'
vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))

import { ReviewApiError } from './review-api'
import {
  fetchSubmissionStatus,
  postSubmissionAnswer,
  requestStatusLink,
} from './submission-status-api'

const TOKEN = 'a'.repeat(64)
let fetchMock: Mock

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })

beforeEach(() => {
  process.env.WP_REST_URL = 'https://wp.example.org/wp-json/'
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => vi.unstubAllGlobals())

describe('submission status client', () => {
  it('forwards the signed visitor address on every call', async () => {
    vi.stubEnv('WP_PREVIEW_SECRET', 'shared')
    fetchMock.mockImplementation(async () => json(200, { id: 40 }))

    await fetchSubmissionStatus(40, TOKEN, '203.0.113.9')
    await postSubmissionAnswer(
      40,
      TOKEN,
      { question_id: 1, body: 'MIT' },
      '203.0.113.9'
    )
    await requestStatusLink({ project_id: 40, email: 'a@b.c' }, '203.0.113.9')
    vi.unstubAllEnvs()

    expect(fetchMock).toHaveBeenCalledTimes(3)
    for (const [, init] of fetchMock.mock.calls as [string, RequestInit][]) {
      const headers = init.headers as Record<string, string>
      expect(headers['X-CDCF-Client-IP']).toBe('203.0.113.9')
      expect(headers['X-CDCF-Client-IP-Signature']).toMatch(/^[0-9a-f]{64}$/)
    }
  })

  it('rejects a 200 response with a non-JSON body as bad_response', async () => {
    fetchMock.mockImplementation(
      async () => new Response('<html>oops</html>', { status: 200 })
    )

    await expect(fetchSubmissionStatus(40, TOKEN)).rejects.toMatchObject({
      status: 502,
      code: 'bad_response',
    })
    await expect(fetchSubmissionStatus(40, TOKEN)).rejects.toBeInstanceOf(
      ReviewApiError
    )
  })

  it('sends the token in a header, never in the URL', async () => {
    fetchMock.mockResolvedValue(json(200, { id: 40 }))

    await fetchSubmissionStatus(40, TOKEN)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/submission-status/40'
    )
    expect(url).not.toContain(TOKEN)
    expect(
      (init.headers as Record<string, string>)['X-CDCF-Submission-Token']
    ).toBe(TOKEN)
    expect(init.cache).toBe('no-store')
  })

  it('posts answers as JSON with the token', async () => {
    fetchMock.mockResolvedValue(
      json(201, { id: 9, question_id: 1, stage: 'in_review' })
    )

    const out = await postSubmissionAnswer(40, TOKEN, {
      question_id: 1,
      body: 'MIT',
    })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/submission-status/40/answer'
    )
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual({
      question_id: 1,
      body: 'MIT',
    })
    expect(out.stage).toBe('in_review')
  })

  it('requests a new link without any token', async () => {
    fetchMock.mockResolvedValue(json(202, { accepted: true }))

    await requestStatusLink({ project_id: 40, email: 'sam@example.org' })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/submission-status/resend-link'
    )
    expect(init.headers as Record<string, string>).not.toHaveProperty(
      'X-CDCF-Submission-Token'
    )
  })

  it('turns WordPress errors into ReviewApiError with code and status', async () => {
    fetchMock.mockResolvedValue(
      json(404, {
        code: 'not_found',
        message: 'No submission matches this link.',
      })
    )

    const err = await fetchSubmissionStatus(40, TOKEN).catch((e) => e)

    expect(err).toBeInstanceOf(ReviewApiError)
    expect(err.status).toBe(404)
    expect(err.code).toBe('not_found')
  })
})
