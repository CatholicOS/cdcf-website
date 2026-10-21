// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest'
import { SubmissionCard } from './SubmissionCard'
import { renderDom } from '@/components/testing/renderDom'
import type { ReviewKind, ReviewProject } from '@/lib/review-types'

let unmount = () => {}
afterEach(() => unmount())

function project(kind: ReviewKind): ReviewProject {
  return {
    id: 7,
    title: 'Foo',
    content: 'Body',
    post_type: 'community_project',
    post_status: 'pending',
    stage: 'in_review',
    kind,
    language: 'en',
    submitted_at: '2026-03-04 10:00:00',
    fields: { url: '', category: '', repo_urls: [], tags: [] },
    submitter: { name: 'Ann', email: null },
  } as unknown as ReviewProject
}

function text(kind: ReviewKind) {
  const r = renderDom(<SubmissionCard project={project(kind)} />)
  unmount = r.unmount
  return r.container.textContent ?? ''
}

describe('SubmissionCard date line', () => {
  it('says Referred for a referral', () => {
    const t = text('referral')
    expect(t).toContain('Referred March 4, 2026')
    expect(t).not.toContain('Submitted')
  })

  it('says Submitted for a submission', () => {
    expect(text('submission')).toContain('Submitted March 4, 2026')
  })
})
