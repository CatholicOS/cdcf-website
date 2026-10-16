import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from 'vitest'
import type { Session } from 'next-auth'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))

import { getAccessToken } from '@/lib/auth-utils'
import {
  ReviewApiError,
  askSubmitter,
  decideProject,
  fetchInbox,
  fetchInboxCount,
  fetchReviewAttachment,
  fetchReviewMe,
  fetchReviewProject,
  fetchReviewProjects,
  markInboxRead,
  patchReviewEntry,
  postReviewEntry,
  saveMyReview,
  uploadReviewAttachment,
} from './review-api'

const mockedGetAccessToken = getAccessToken as unknown as Mock
const session = { user: { email: 'a@example.org' } } as unknown as Session
let fetchMock: Mock

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

beforeEach(() => {
  vi.resetAllMocks()
  process.env.WP_REST_URL = 'https://wp.example.org/wp-json/'
  delete process.env.WP_GRAPHQL_URL
  mockedGetAccessToken.mockReturnValue('token-abc')
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('review-api', () => {
  it('fetches membership with the bearer token and no caching', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { member: true }))

    await expect(fetchReviewMe(session)).resolves.toEqual({ member: true })

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('https://wp.example.org/wp-json/cdcf/v1/review/me')
    expect(init.headers).toEqual({ Authorization: 'Bearer token-abc' })
    expect(init.cache).toBe('no-store')
    expect(init.method).toBe('GET')
  })

  it('builds the project list query string', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, { projects: [], page: 2, has_more: false })
    )

    await fetchReviewProjects(session, { stage: 'decided', page: 2 })
    await fetchReviewProjects(session, { page: 1 })

    expect(fetchMock.mock.calls[0][0]).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/projects?stage=decided&page=2'
    )
    expect(fetchMock.mock.calls[1][0]).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/projects'
    )
  })

  it('rejects non-positive ids without calling WordPress', async () => {
    await expect(fetchReviewProject(session, 0)).rejects.toMatchObject({
      status: 400,
      code: 'invalid_id',
    })
    await expect(
      patchReviewEntry(session, 1.5, { body_md: 'x', version: 'v' })
    ).rejects.toBeInstanceOf(ReviewApiError)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('throws no_token before fetching when the session has no token', async () => {
    mockedGetAccessToken.mockReturnValue(undefined)

    await expect(fetchReviewMe(session)).rejects.toMatchObject({
      status: 401,
      code: 'no_token',
    })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('falls back to WP_GRAPHQL_URL and reports missing config', async () => {
    delete process.env.WP_REST_URL
    process.env.WP_GRAPHQL_URL = 'https://cms.example.org/graphql'
    fetchMock.mockResolvedValue(jsonResponse(200, {}))
    await fetchReviewMe(session)
    expect(fetchMock.mock.calls[0][0]).toBe(
      'https://cms.example.org/wp-json/cdcf/v1/review/me'
    )

    delete process.env.WP_GRAPHQL_URL
    await expect(fetchReviewMe(session)).rejects.toMatchObject({
      status: 500,
      code: 'config_missing',
    })
  })

  it('sends my review as JSON with PUT', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { id: 5 }))

    await saveMyReview(session, 40, {
      vote: 'cdcf',
      body_md: 'Fits.',
      version: 'v1',
    })

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/projects/40/my-review'
    )
    expect(init.method).toBe('PUT')
    expect(init.headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(init.body)).toEqual({
      vote: 'cdcf',
      body_md: 'Fits.',
      version: 'v1',
    })
  })

  it('posts entries and patches them', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { id: 9 }))

    await postReviewEntry(session, 40, {
      kind: 'reply',
      parent_id: 1,
      body_md: 'Agreed',
    })
    await patchReviewEntry(session, 9, { body_md: 'Edited', version: 'v2' })

    expect(fetchMock.mock.calls[0][0]).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/projects/40/entries'
    )
    expect(fetchMock.mock.calls[0][1].method).toBe('POST')
    expect(fetchMock.mock.calls[1][0]).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/entries/9'
    )
    expect(fetchMock.mock.calls[1][1].method).toBe('PATCH')
  })

  it('maps WordPress error bodies to ReviewApiError', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(409, { code: 'stale_entry', message: 'Changed.' })
    )

    await expect(
      patchReviewEntry(session, 9, { body_md: 'x', version: 'v' })
    ).rejects.toMatchObject({
      name: 'ReviewApiError',
      status: 409,
      code: 'stale_entry',
      message: 'Changed.',
    })
  })

  it('maps a non-JSON 413 from a proxy to file_too_large', async () => {
    fetchMock.mockResolvedValue(
      new Response('<html>413 Request Entity Too Large</html>', {
        status: 413,
      })
    )

    await expect(
      uploadReviewAttachment(session, 9, new FormData())
    ).rejects.toMatchObject({ status: 413, code: 'file_too_large' })
  })

  it('uploads FormData untouched and pasted Markdown as JSON', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { id: 4 }))
    const form = new FormData()
    form.append('file', new Blob(['x']), 'a.md')

    await uploadReviewAttachment(session, 9, form)
    await uploadReviewAttachment(session, 9, {
      title: 'License',
      markdown: '# L',
    })

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/entries/9/attachments'
    )
    expect(init.body).toBe(form)
    expect(init.headers['Content-Type']).toBeUndefined()
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
      title: 'License',
      markdown: '# L',
    })
  })

  it('returns the raw attachment response and asks for downloads', async () => {
    const wp = new Response('bytes', {
      status: 200,
      headers: { 'Content-Type': 'image/png' },
    })
    fetchMock.mockResolvedValue(wp)

    await expect(fetchReviewAttachment(session, 4, true)).resolves.toBe(wp)
    expect(fetchMock.mock.calls[0][0]).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/attachments/4?download=1'
    )
  })

  it('throws on a failed attachment fetch', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(404, { code: 'file_missing', message: 'Missing.' })
    )

    await expect(fetchReviewAttachment(session, 4)).rejects.toMatchObject({
      status: 404,
      code: 'file_missing',
    })
  })
})

describe('askSubmitter', () => {
  it('posts the question with the bearer token', async () => {
    mockedGetAccessToken.mockReturnValue('tok')
    fetchMock.mockResolvedValue(
      jsonResponse(201, { id: 5, kind: 'question', warnings: [] })
    )

    const out = await askSubmitter(session, 40, { body: 'Which licence?' })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/projects/40/question'
    )
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual({ body: 'Which licence?' })
    expect(out.warnings).toEqual([])
  })
})

describe('decideProject', () => {
  it('posts the decision with the bearer token', async () => {
    mockedGetAccessToken.mockReturnValue('tok')
    fetchMock.mockResolvedValue(
      jsonResponse(200, {
        id: 40,
        stage: 'decided',
        decision: 'cdcf',
        post_type: 'project',
        post_status: 'publish',
        warnings: [],
      })
    )
    const payload = {
      decision: 'cdcf' as const,
      rationale: 'Fits.',
      submitter_summary: 'Accepted.',
    }

    const out = await decideProject(session, 40, payload)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/projects/40/decision'
    )
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual(payload)
    expect(new Headers(init.headers).get('Authorization')).toBe('Bearer tok')
    expect(out.stage).toBe('decided')
  })
})

describe('inbox', () => {
  it('fetches a page with the unread filter', async () => {
    const page = { items: [], page: 2, has_more: false, unread: 0 }
    fetchMock.mockResolvedValue(jsonResponse(200, page))

    expect(await fetchInbox(session, { unread: true, page: 2 })).toEqual(page)
    expect(fetchMock).toHaveBeenCalledWith(
      'https://wp.example.org/wp-json/cdcf/v1/review/inbox?unread=1&page=2',
      expect.objectContaining({
        method: 'GET',
        headers: { Authorization: 'Bearer token-abc' },
      })
    )
  })

  it('omits default query parameters', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, { items: [], page: 1, has_more: false, unread: 0 })
    )
    await fetchInbox(session)
    expect(fetchMock.mock.calls[0][0]).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/inbox'
    )
  })

  it('fetches the unread count', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { unread: 4 }))
    expect(await fetchInboxCount(session)).toEqual({ unread: 4 })
    expect(fetchMock.mock.calls[0][0]).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/review/inbox/count'
    )
  })

  it('posts what to mark read', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { unread: 0 }))
    expect(await markInboxRead(session, { all: true })).toEqual({ unread: 0 })
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('https://wp.example.org/wp-json/cdcf/v1/review/inbox/read')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual({ all: true })
  })
})
