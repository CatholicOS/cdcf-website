// @vitest-environment happy-dom
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/src/i18n/navigation', () => ({
  Link: ({ href, children }: { href: string; children: ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}))

import { ProjectCard } from './ProjectCard'
import { renderDom } from '@/components/testing/renderDom'
import type { ReviewKind, ReviewProjectSummary } from '@/lib/review-types'

let unmount = () => {}
afterEach(() => unmount())

function project(kind: ReviewKind): ReviewProjectSummary {
  return {
    id: 7,
    title: 'Foo',
    category: '',
    language: 'en',
    submitted_at: '2026-03-04 10:00:00',
    stage: 'in_review',
    kind,
    tally: { cdcf: 0, community: 0, reject: 0 },
    voters_pending: 0,
    my_vote: null,
    unread: 0,
  }
}

function text(kind: ReviewKind) {
  const r = renderDom(<ProjectCard project={project(kind)} canVote={false} />)
  unmount = r.unmount
  return r.container.textContent ?? ''
}

describe('ProjectCard date line', () => {
  it('says Referred for a referral', () => {
    const t = text('referral')
    expect(t).toContain('Referred Mar 4, 2026')
    expect(t).not.toContain('Submitted')
  })

  it('says Submitted for a submission', () => {
    expect(text('submission')).toContain('Submitted Mar 4, 2026')
  })
})
