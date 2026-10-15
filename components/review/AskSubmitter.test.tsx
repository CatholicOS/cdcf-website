// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('next-auth/react', () => ({ signIn: vi.fn() }))
const refresh = vi.fn()
vi.mock('@/src/i18n/navigation', () => ({ useRouter: () => ({ refresh }) }))

import { AskSubmitter } from './AskSubmitter'
import {
  buttonByText,
  renderDom,
  typeInto,
} from '@/components/testing/renderDom'

let unmount = () => {}
let fetchMock: ReturnType<typeof vi.fn>
beforeEach(() => {
  refresh.mockReset()
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => {
  unmount()
  vi.unstubAllGlobals()
})

async function ask(text: string) {
  const r = renderDom(<AskSubmitter projectId={40} />)
  unmount = r.unmount
  typeInto(r.container.querySelector('textarea'), text)
  await act(async () => {
    buttonByText(r.container, 'Send question')?.click()
  })
  return r.container
}

describe('AskSubmitter', () => {
  it('warns that the text is emailed, posts it, clears and refreshes', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 5, warnings: [] }), { status: 201 })
    )

    const c = await ask('Which licence?')

    expect(c.textContent).toContain('as plain text')
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/review/projects/40/question')
    expect(JSON.parse(init.body as string)).toEqual({ body: 'Which licence?' })
    expect(c.querySelector('textarea')?.value).toBe('')
    expect(refresh).toHaveBeenCalled()
  })

  it('tells the reviewer when the email could not be sent', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 5, warnings: ['email_failed'] }), {
        status: 201,
      })
    )

    const c = await ask('Which licence?')

    expect(c.textContent).toContain('could not be sent')
  })

  it('shows the forbidden message and keeps the text on a 403', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: 'rest_forbidden' }), {
        status: 403,
      })
    )

    const c = await ask('Which licence?')

    expect(c.textContent).toContain("You don't have permission to do that.")
    expect(c.querySelector('textarea')?.value).toBe('Which licence?')
    expect(refresh).not.toHaveBeenCalled()
  })

  it('accepts a 201 without a warnings field', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 5 }), { status: 201 })
    )

    const c = await ask('Which licence?')

    expect(c.textContent).not.toContain('could not be sent')
    expect(refresh).toHaveBeenCalled()
  })
})
