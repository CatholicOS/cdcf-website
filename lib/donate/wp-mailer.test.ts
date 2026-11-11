import { createHmac } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { postToWordPress } from './wp-mailer'

const env = {
  WP_REST_URL: 'https://cms.example.org/wp-json/',
  WP_APP_USERNAME: 'bot',
  WP_APP_PASSWORD: 'app pass',
  CDCF_DONATION_MAIL_SECRET: 's3cret',
}

describe('postToWordPress', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    for (const [k, v] of Object.entries(env)) vi.stubEnv(k, v)
    vi.stubGlobal('fetch', fetchMock)
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.unstubAllEnvs()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    fetchMock.mockReset()
  })

  it('signs the exact body it sends, so WordPress can verify the caller', async () => {
    fetchMock.mockResolvedValue(new Response('{}', { status: 200 }))

    await expect(
      postToWordPress('cdcf/v1/donation-acknowledgment', { a: 1 })
    ).resolves.toBe(true)

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe(
      'https://cms.example.org/wp-json/cdcf/v1/donation-acknowledgment'
    )
    const expected = createHmac('sha256', 's3cret')
      .update(init.body)
      .digest('hex')
    expect(init.headers['X-CDCF-Signature']).toBe(expected)
    expect(init.body).toBe('{"a":1}')
  })

  it('sends nothing without a signing secret, since WordPress would refuse it', async () => {
    vi.stubEnv('CDCF_DONATION_MAIL_SECRET', '')

    await expect(postToWordPress('cdcf/v1/x', {})).resolves.toBe(false)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('reports a non-2xx response as a failure', async () => {
    fetchMock.mockResolvedValue(new Response('', { status: 500 }))

    await expect(postToWordPress('cdcf/v1/x', {})).resolves.toBe(false)
  })

  it('reports a network error as a failure rather than throwing', async () => {
    fetchMock.mockRejectedValue(new Error('ECONNREFUSED'))

    await expect(postToWordPress('cdcf/v1/x', {})).resolves.toBe(false)
  })
})
