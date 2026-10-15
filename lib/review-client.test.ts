import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
  type Mock,
} from 'vitest'
import {
  jsonInit,
  reviewFetch,
  uploadAll,
  uploadAttachment,
} from './review-client'

let fetchMock: Mock

beforeEach(() => {
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })

describe('reviewFetch', () => {
  it('returns data on success without caching', async () => {
    fetchMock.mockResolvedValue(json(200, { id: 1 }))

    await expect(reviewFetch('/api/review/me')).resolves.toEqual({
      ok: true,
      data: { id: 1 },
    })
    expect(fetchMock.mock.calls[0][1].cache).toBe('no-store')
  })

  it('maps error bodies to an error key', async () => {
    fetchMock.mockResolvedValue(
      json(403, { error: 'review_locked', message: 'Locked.' })
    )

    await expect(reviewFetch('/x')).resolves.toEqual({
      ok: false,
      status: 403,
      code: 'review_locked',
      errorKey: 'locked',
    })
  })

  it('handles non-JSON errors and network failures', async () => {
    fetchMock.mockResolvedValueOnce(new Response('<html>', { status: 413 }))
    await expect(reviewFetch('/x')).resolves.toMatchObject({
      ok: false,
      status: 413,
      errorKey: 'tooLarge',
    })

    fetchMock.mockRejectedValueOnce(new TypeError('offline'))
    await expect(reviewFetch('/x')).resolves.toEqual({
      ok: false,
      status: 0,
      errorKey: 'generic',
    })
  })
})

describe('jsonInit', () => {
  it('serialises the payload', () => {
    expect(jsonInit('PUT', { a: 1 })).toEqual({
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: '{"a":1}',
    })
  })
})

describe('uploadAttachment', () => {
  it('sends files as multipart and pastes as JSON', async () => {
    fetchMock.mockResolvedValue(json(201, { id: 4 }))
    const file = new File(['x'], 'a.png', { type: 'image/png' })

    await uploadAttachment(9, { key: 'k', kind: 'file', file })
    await uploadAttachment(9, {
      key: 'p',
      kind: 'paste',
      title: 'T',
      markdown: '# T',
    })

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/review/entries/9/attachments')
    expect(init.body).toBeInstanceOf(FormData)
    expect((init.body as FormData).get('file')).toBeInstanceOf(File)
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
      title: 'T',
      markdown: '# T',
    })
  })
})

describe('uploadAll', () => {
  it('uploads one at a time and returns the failures with their items', async () => {
    const order: string[] = []
    let inFlight = 0
    fetchMock.mockImplementation(async (_url: string, init: RequestInit) => {
      inFlight++
      expect(inFlight).toBe(1)
      const title = JSON.parse(String(init.body)).title as string
      order.push(title)
      await new Promise((r) => setTimeout(r, 1))
      inFlight--
      return title === 'bad'
        ? json(413, { error: 'file_too_large' })
        : json(201, { id: 1 })
    })
    const items = ['a', 'bad', 'c'].map((title) => ({
      key: title,
      kind: 'paste' as const,
      title,
      markdown: '#',
    }))

    const failures = await uploadAll(9, items)

    expect(order).toEqual(['a', 'bad', 'c'])
    expect(failures).toEqual([{ pending: items[1], errorKey: 'tooLarge' }])
  })
})
