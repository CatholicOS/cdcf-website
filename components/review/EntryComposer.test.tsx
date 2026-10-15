// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act } from 'react'
import type { Editor } from '@tiptap/core'

const reviewFetch = vi.fn()
vi.mock('@/lib/review-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/review-client')>()),
  reviewFetch: (...args: unknown[]) => reviewFetch(...args),
  uploadAll: async () => [],
}))
vi.mock('@/src/i18n/navigation', () => ({
  useRouter: () => ({ refresh: vi.fn() }),
}))

import { EntryComposer } from './EntryComposer'
import { buttonByText, renderDom } from '@/components/testing/renderDom'

let unmount: () => void = () => {}
beforeEach(() => {
  window.localStorage.clear()
  reviewFetch.mockResolvedValue({ ok: true, data: { id: 9 } })
})
afterEach(() => unmount())

describe('EntryComposer with the visual editor', () => {
  it('posts what was written as body_md Markdown, then clears the editor', async () => {
    const r = renderDom(<EntryComposer projectId={833} kind="discussion" />)
    unmount = r.unmount
    const editor = (
      r.container.querySelector('.ProseMirror') as HTMLElement & {
        editor: Editor
      }
    ).editor
    act(() => {
      editor.commands.setContent('Point **one**', { contentType: 'markdown' })
    })

    await act(async () => {
      buttonByText(r.container, 'Post')?.click()
    })

    expect(reviewFetch).toHaveBeenCalledTimes(1)
    const [url, init] = reviewFetch.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/review/projects/833/entries')
    expect(JSON.parse(init.body as string)).toEqual({
      kind: 'discussion',
      body_md: 'Point **one**',
    })
    expect(editor.getText()).toBe('')
  })
})
