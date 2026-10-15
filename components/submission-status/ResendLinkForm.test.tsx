// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ResendLinkForm } from './ResendLinkForm'
import {
  buttonByText,
  renderDom,
  typeInto,
} from '@/components/testing/renderDom'

afterEach(() => vi.unstubAllGlobals())

describe('ResendLinkForm', () => {
  it('always shows the same neutral confirmation', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response('{}', { status: 202 }))
    vi.stubGlobal('fetch', fetchMock)
    const r = renderDom(<ResendLinkForm projectId={40} />)

    const status = r.container.querySelector('[role="status"]')
    expect(status).toBeTruthy()
    expect(status?.textContent).toBe('')

    typeInto(
      r.container.querySelector('input[type="email"]'),
      'sam@example.org'
    )
    await act(async () => {
      buttonByText(r.container, 'Email me a new link')?.click()
    })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/submission-status/resend-link')
    expect(JSON.parse(init.body as string)).toEqual({
      project_id: 40,
      email: 'sam@example.org',
    })
    expect(r.container.querySelector('[role="status"]')?.textContent).toContain(
      'If that address matches the submission'
    )
    r.unmount()
  })
})
