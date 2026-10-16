// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('next-auth/react', () => ({ signIn: vi.fn() }))
const refresh = vi.fn()
vi.mock('@/src/i18n/navigation', () => ({ useRouter: () => ({ refresh }) }))

import { DecisionPanel } from './DecisionPanel'
import {
  buttonByText,
  renderDom,
  typeInto,
} from '@/components/testing/renderDom'
import type { ReviewDecisionEmail, ReviewVote } from '@/lib/review-types'

const email: ReviewDecisionEmail = {
  language: 'it',
  subject: 'Decision on Foo',
  body: 'Outcome: {outcome}\n\n{summary}\n\nSee {link}',
  outcomes: {
    cdcf: 'Adopted by CDCF',
    community: 'Listed as community project',
    reject: 'Not accepted',
  },
}

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

function mount(plurality: ReviewVote | null = 'cdcf') {
  const r = renderDom(
    <DecisionPanel projectId={40} plurality={plurality} email={email} />
  )
  unmount = r.unmount
  return r.container
}

function submit(c: HTMLElement) {
  return c.querySelector('button[type="button"], button[type="submit"]')
}

function textareas(c: HTMLElement) {
  return c.querySelectorAll('textarea')
}

function radio(c: HTMLElement, value: string) {
  return c.querySelector(`input[type="radio"][value="${value}"]`)
}

describe('DecisionPanel', () => {
  it('preselects the plurality and enables submit once a summary is typed', () => {
    const c = mount('cdcf')
    expect((radio(c, 'cdcf') as HTMLInputElement).checked).toBe(true)
    expect(buttonByText(c, 'Record decision')?.disabled).toBe(true)
    typeInto(textareas(c)[1], 'Thanks for the proposal.')
    expect(buttonByText(c, 'Record decision')?.disabled).toBe(false)
  })

  it('requires a rationale when the outcome differs from the plurality', () => {
    const c = mount('cdcf')
    typeInto(textareas(c)[1], 'Summary')
    act(() => {
      ;(radio(c, 'reject') as HTMLInputElement).click()
    })
    expect(c.textContent).toContain('required, because the decision differs')
    expect(buttonByText(c, 'Record decision')?.disabled).toBe(true)
    typeInto(textareas(c)[0], 'Out of scope')
    expect(buttonByText(c, 'Record decision')?.disabled).toBe(false)
  })

  it('requires a rationale and selection when there is no plurality', () => {
    const c = mount(null)
    expect(c.textContent).toContain('No outcome has the most council votes')
    expect(c.textContent).toContain('required, because no outcome has the most')
    typeInto(textareas(c)[1], 'Summary')
    expect(buttonByText(c, 'Record decision')?.disabled).toBe(true)
  })

  it('previews the email with the summary and outcome substituted', () => {
    const c = mount('cdcf')
    expect(c.querySelector('pre')?.textContent).toContain('[your summary]')
    expect(c.querySelector('pre')?.textContent).toContain('Adopted by CDCF')
    typeInto(textareas(c)[1], 'Thanks for the proposal.')
    const pre = c.querySelector('pre')?.textContent ?? ''
    expect(pre.startsWith('Decision on Foo\n\n')).toBe(true)
    expect(pre).toContain('Thanks for the proposal.')
    expect(pre).toContain('[link to the status page]')
    expect(pre).not.toContain('{')
  })

  it('keeps placeholders typed in the summary verbatim in the preview', () => {
    const c = mount('cdcf')
    typeInto(textareas(c)[1], 'Reply to {link} or {outcome}')
    const pre = c.querySelector('pre')?.textContent ?? ''
    expect(pre).toContain('Reply to {link} or {outcome}')
    expect(pre).toContain('See [link to the status page]')
  })

  it('needs a second click to confirm, then posts the decision', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 40, warnings: [] }), { status: 200 })
    )
    const c = mount('cdcf')
    typeInto(textareas(c)[1], 'Thanks.')
    await act(async () => {
      buttonByText(c, 'Record decision')?.click()
    })
    expect(fetchMock).not.toHaveBeenCalled()
    await act(async () => {
      buttonByText(c, 'Click again to confirm — this cannot be undone')?.click()
    })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/review/projects/40/decision')
    expect(JSON.parse(init.body as string)).toEqual({
      decision: 'cdcf',
      rationale: '',
      submitter_summary: 'Thanks.',
    })
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('tells the Board member when the email could not be sent', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 40, warnings: ['email_failed'] }), {
        status: 200,
      })
    )
    const c = mount('cdcf')
    typeInto(textareas(c)[1], 'Thanks.')
    await act(async () => {
      buttonByText(c, 'Record decision')?.click()
    })
    await act(async () => {
      buttonByText(c, 'Click again to confirm — this cannot be undone')?.click()
    })
    expect(c.textContent).toContain('email to the submitter could not be sent')
    expect(refresh).not.toHaveBeenCalled()
    expect(c.querySelector('textarea')).toBeNull()
    await act(async () => {
      buttonByText(c, 'Reload')?.click()
    })
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('shows the error and does not refresh when the server refuses', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: 'summary_required' }), {
        status: 400,
      })
    )
    const c = mount('cdcf')
    typeInto(textareas(c)[1], 'Thanks.')
    await act(async () => {
      buttonByText(c, 'Record decision')?.click()
    })
    await act(async () => {
      buttonByText(c, 'Click again to confirm — this cannot be undone')?.click()
    })
    expect(c.querySelector('[role="alert"]')).toBeTruthy()
    expect(refresh).not.toHaveBeenCalled()
  })

  it('names the email language with Intl.DisplayNames and caps the summary', () => {
    const c = mount('cdcf')
    expect(c.textContent).toContain('Summary for the submitter, in Italian')
    expect(textareas(c)[1].getAttribute('maxlength')).toBe('10000')
    expect(submit(c)).toBeTruthy()
  })
})
