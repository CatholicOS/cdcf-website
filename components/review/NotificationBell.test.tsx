// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const push = vi.fn()
vi.mock('@/src/i18n/navigation', () => ({
  useRouter: () => ({ push }),
  Link: ({
    href,
    children,
    ...rest
  }: {
    href: string
    children: React.ReactNode
  }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}))

import { BELL_POLL_MS, NotificationBell } from './NotificationBell'
import { buttonByText, click, renderDom } from '@/components/testing/renderDom'
import type { InboxItem } from '@/lib/review-types'

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })

const item = (over: Partial<InboxItem> = {}): InboxItem => ({
  event_id: 12,
  type: 'reply_posted',
  project: { id: 40, title: 'Liturgy Helper' },
  actor: { id: 8, name: 'Fr. A' },
  entry_id: 5,
  data: {},
  created_at: '2026-10-16 09:00:00.000000',
  read: false,
  to_you: true,
  ...over,
})

let unmount = () => {}
let fetchMock: ReturnType<typeof vi.fn>
beforeEach(() => {
  push.mockReset()
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => {
  unmount()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

async function flush() {
  await act(async () => {
    await Promise.resolve()
    await Promise.resolve()
  })
}

async function mount() {
  const r = renderDom(<NotificationBell />)
  unmount = r.unmount
  await flush()
  return r.container
}

const bell = (c: HTMLElement) =>
  c.querySelector('button[aria-haspopup]') as HTMLButtonElement

// The item button also holds the age, so match the sentence by substring.
const itemButton = (c: HTMLElement, sentence: string) =>
  [...c.querySelectorAll('button')].find((b) =>
    b.textContent?.includes(sentence)
  )

describe('NotificationBell', () => {
  it('shows the unread count from the count route', async () => {
    fetchMock.mockResolvedValue(json({ unread: 3 }))
    const c = await mount()
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/review/inbox/count',
      expect.anything()
    )
    expect(bell(c).getAttribute('aria-label')).toBe('Notifications, 3 unread')
    expect(c.textContent).toContain('3')
  })

  it('keeps the last count when the count route fails', async () => {
    vi.useFakeTimers()
    fetchMock.mockResolvedValueOnce(json({ unread: 2 }))
    fetchMock.mockResolvedValueOnce(json({ error: 'unauthorized' }, 401))
    const c = await mount()
    await act(async () => {
      vi.advanceTimersByTime(BELL_POLL_MS)
    })
    await flush()
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(bell(c).getAttribute('aria-label')).toBe('Notifications, 2 unread')
  })

  it('opens the latest items and follows one, marking it read', async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }))
    const c = await mount()
    fetchMock.mockResolvedValueOnce(
      json({ items: [item()], page: 1, has_more: false, unread: 1 })
    )
    await act(async () => click(bell(c)))
    await flush()
    expect(c.textContent).toContain(
      'Fr. A replied to your review · Liturgy Helper'
    )

    fetchMock.mockResolvedValueOnce(json({ unread: 0 }))
    await act(async () =>
      click(itemButton(c, 'Fr. A replied to your review · Liturgy Helper'))
    )
    await flush()
    const [url, init] = fetchMock.mock.calls.at(-1)!
    expect(url).toBe('/api/review/inbox/read')
    expect(JSON.parse(init.body)).toEqual({ event_ids: [12] })
    expect(push).toHaveBeenCalledWith('/review/40#entry-5')
  })

  it('marks all read', async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 1 }))
    const c = await mount()
    fetchMock.mockResolvedValueOnce(
      json({ items: [item()], page: 1, has_more: false, unread: 1 })
    )
    await act(async () => click(bell(c)))
    await flush()
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }))
    await act(async () => click(buttonByText(c, 'Mark all read')))
    await flush()
    expect(JSON.parse(fetchMock.mock.calls.at(-1)![1].body)).toEqual({
      all: true,
    })
    expect(bell(c).getAttribute('aria-label')).toBe('Notifications')
  })

  it('says so when the list cannot load', async () => {
    fetchMock.mockResolvedValueOnce(json({ unread: 0 }))
    const c = await mount()
    fetchMock.mockResolvedValueOnce(json({ error: 'x' }, 500))
    await act(async () => click(bell(c)))
    await flush()
    expect(c.textContent).toContain('Could not load notifications.')
  })

  it('does not poll while the tab is hidden', async () => {
    vi.useFakeTimers()
    fetchMock.mockResolvedValue(json({ unread: 0 }))
    await mount()
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => 'hidden',
    })
    await act(async () => {
      document.dispatchEvent(new Event('visibilitychange'))
      vi.advanceTimersByTime(BELL_POLL_MS * 3)
    })
    expect(fetchMock).toHaveBeenCalledTimes(1)
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => 'visible',
    })
  })
})
