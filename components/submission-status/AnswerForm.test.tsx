// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const refresh = vi.fn()
vi.mock('@/src/i18n/navigation', () => ({ useRouter: () => ({ refresh }) }))

import { AnswerForm } from './AnswerForm'
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

async function submit(container: HTMLElement, text: string) {
  typeInto(container.querySelector('textarea'), text)
  await act(async () => {
    buttonByText(container, 'Send answer')?.click()
  })
}

describe('AnswerForm', () => {
  it('posts the answer and refreshes the page', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ stage: 'in_review' }), { status: 201 })
    )
    const r = renderDom(<AnswerForm projectId={40} questionId={1} />)
    unmount = r.unmount

    await submit(r.container, 'MIT licence')

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/submission-status/40/answer')
    expect(JSON.parse(init.body as string)).toEqual({
      question_id: 1,
      body: 'MIT licence',
    })
    expect(refresh).toHaveBeenCalled()
  })

  it('shows why an answer was refused and keeps the text', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: 'answer_rejected' }), {
        status: 400,
      })
    )
    const r = renderDom(<AnswerForm projectId={40} questionId={1} />)
    unmount = r.unmount

    await submit(r.container, 'buy now')

    expect(r.container.textContent).toContain('could not be accepted')
    expect(r.container.querySelector('textarea')?.value).toBe('buy now')
    expect(refresh).not.toHaveBeenCalled()
  })
})
