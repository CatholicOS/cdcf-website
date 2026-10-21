// @vitest-environment happy-dom
import { act } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('next-auth/react', () => ({ signIn: vi.fn() }))
vi.mock('@/src/i18n/navigation', () => ({
  useRouter: () => ({ refresh: vi.fn() }),
}))

import { MyReviewComposer } from './MyReviewComposer'
import { buttonByText, renderDom } from '@/components/testing/renderDom'
import type { ReviewKind } from '@/lib/review-types'

let unmount = () => {}
afterEach(() => unmount())

function mount(kind: ReviewKind) {
  const r = renderDom(
    <MyReviewComposer projectId={7} kind={kind} existing={null} />
  )
  unmount = r.unmount
  return r.container
}

function choose(c: HTMLElement, value: string) {
  act(() => {
    ;(
      c.querySelector(`input[type="radio"][value="${value}"]`) as HTMLElement
    ).click()
  })
}

describe('MyReviewComposer', () => {
  it('lets a referral vote be saved without a review body', () => {
    const c = mount('referral')
    expect(c.querySelectorAll('input[type="radio"]')).toHaveLength(2)
    expect(c.textContent).toContain('Accept')
    expect(c.querySelector('input[value="cdcf"]')).toBeNull()
    choose(c, 'community')
    expect(buttonByText(c, 'Post review')?.disabled).toBe(false)
  })

  it('asks for a vote when a referral is saved without one', () => {
    const c = mount('referral')
    act(() => {
      buttonByText(c, 'Post review')?.click()
    })
    expect(c.textContent).toContain('Choose a vote first.')
  })

  it('keeps saving disabled for a submission with an empty body', () => {
    const c = mount('submission')
    choose(c, 'cdcf')
    expect(buttonByText(c, 'Post review')?.disabled).toBe(true)
  })
})
