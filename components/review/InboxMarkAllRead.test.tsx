// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const refresh = vi.fn()
vi.mock('@/src/i18n/navigation', () => ({ useRouter: () => ({ refresh }) }))

import { InboxMarkAllRead } from './InboxMarkAllRead'
import { buttonByText, click, renderDom } from '@/components/testing/renderDom'
import { INBOX_CHANGED_EVENT } from '@/lib/review-inbox'

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

function mount(disabled: boolean) {
  const r = renderDom(<InboxMarkAllRead disabled={disabled} />)
  unmount = r.unmount
  return r.container
}

describe('InboxMarkAllRead', () => {
  it('posts {all: true} and refreshes on success', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ unread: 0 }), { status: 200 })
    )
    const changed = vi.fn()
    window.addEventListener(INBOX_CHANGED_EVENT, changed)
    const c = mount(false)
    await act(async () => {
      click(buttonByText(c, 'Mark all read'))
    })
    window.removeEventListener(INBOX_CHANGED_EVENT, changed)
    expect(changed).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/review/inbox/read')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual({ all: true })
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('does not refresh when the request fails', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ error: 'x' }), { status: 500 })
    )
    const c = mount(false)
    await act(async () => {
      click(buttonByText(c, 'Mark all read'))
    })
    expect(refresh).not.toHaveBeenCalled()
  })

  it('is disabled when told to be', () => {
    const c = mount(true)
    expect(buttonByText(c, 'Mark all read')?.disabled).toBe(true)
  })
})
