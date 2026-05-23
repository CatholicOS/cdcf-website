import { afterEach, describe, expect, it, vi } from 'vitest'
import { wpQuery } from './client'

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'content-type': 'application/json' },
    ...init,
  })
}

describe('wpQuery', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
    vi.restoreAllMocks()
  })

  it('returns json.data on a successful response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: { pages: { nodes: [{ databaseId: 1 }] } } })
    )
    vi.stubGlobal('fetch', fetchMock)

    const result = await wpQuery<{ pages: { nodes: { databaseId: number }[] } }>(
      'query { pages { nodes { databaseId } } }'
    )

    expect(result.pages.nodes[0].databaseId).toBe(1)
    expect(fetchMock).toHaveBeenCalledOnce()
  })

  it('forwards query and variables in the POST body', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: null }))
    vi.stubGlobal('fetch', fetchMock)

    await wpQuery('Q', { foo: 'bar' })

    const [, init] = fetchMock.mock.calls[0]
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual({ query: 'Q', variables: { foo: 'bar' } })
  })

  it('passes revalidate and tags through to fetch next options', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: null }))
    vi.stubGlobal('fetch', fetchMock)

    await wpQuery('Q', {}, { revalidate: 120, tags: ['pages'] })

    const [, init] = fetchMock.mock.calls[0]
    expect(init.next).toEqual({ revalidate: 120, tags: ['pages'] })
  })

  it('forces revalidate: 0 when draft mode is active', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: null }))
    vi.stubGlobal('fetch', fetchMock)

    await wpQuery('Q', {}, { revalidate: 600, draft: true, token: 't' })

    const [, init] = fetchMock.mock.calls[0]
    expect(init.next.revalidate).toBe(0)
    expect(init.headers.Authorization).toBe('Bearer t')
  })

  it('uses Basic auth from the app-password env when draft and no token', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: null }))
    vi.stubGlobal('fetch', fetchMock)
    vi.stubEnv('WP_APP_USERNAME', 'user')
    vi.stubEnv('WP_APP_PASSWORD', 'pass')

    await wpQuery('Q', {}, { draft: true })

    const [, init] = fetchMock.mock.calls[0]
    const expected = `Basic ${Buffer.from('user:pass').toString('base64')}`
    expect(init.headers.Authorization).toBe(expected)
  })

  it('warns and sends no Authorization when draft has no credentials', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: null }))
    vi.stubGlobal('fetch', fetchMock)
    vi.stubEnv('WP_APP_USERNAME', '')
    vi.stubEnv('WP_APP_PASSWORD', '')
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    await wpQuery('Q', {}, { draft: true })

    const [, init] = fetchMock.mock.calls[0]
    expect(init.headers.Authorization).toBeUndefined()
    expect(warn).toHaveBeenCalled()
  })

  it('throws on a non-ok HTTP response with status text', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response('boom', { status: 500, statusText: 'Internal Server Error' })
    )
    vi.stubGlobal('fetch', fetchMock)

    await expect(wpQuery('Q')).rejects.toThrow(/500 Internal Server Error/)
  })

  it('throws when the response is HTML (WP misconfigured)', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response('<html>oops</html>', {
        status: 200,
        headers: { 'content-type': 'text/html' },
      })
    )
    vi.stubGlobal('fetch', fetchMock)

    await expect(wpQuery('Q')).rejects.toThrow(/non-JSON/)
  })

  it('throws when GraphQL returns errors[]', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        errors: [{ message: 'Field "x" missing' }, { message: 'rate-limited' }],
      })
    )
    vi.stubGlobal('fetch', fetchMock)

    await expect(wpQuery('Q')).rejects.toThrow(/Field "x" missing, rate-limited/)
  })
})
