import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
const auth = vi.hoisted(() => vi.fn())
vi.mock('@/lib/auth', () => ({ auth }))
const decideProject = vi.hoisted(() => vi.fn())
vi.mock('@/lib/review-api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/review-api')>()),
  decideProject,
}))

import { POST } from './route'
import { ReviewApiError } from '@/lib/review-api'

const session = { user: { email: 'board@example.org' } }

const post = (body: unknown, id = '40') =>
  POST(
    new NextRequest('http://localhost/api/review/projects/40/decision', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }),
    { params: Promise.resolve({ id }) }
  )

const valid = {
  decision: 'cdcf',
  rationale: 'Fits the mission.',
  submitter_summary: 'Accepted.',
}

beforeEach(() => {
  auth.mockReset()
  auth.mockResolvedValue(session)
  decideProject.mockReset()
})

describe('POST /api/review/projects/[id]/decision', () => {
  it('forwards the decision and returns the result', async () => {
    const result = {
      id: 40,
      stage: 'decided',
      decision: 'cdcf',
      post_type: 'project',
      post_status: 'publish',
      warnings: [],
    }
    decideProject.mockResolvedValue(result)

    const res = await post(valid)

    expect(res.status).toBe(200)
    expect(await res.json()).toEqual(result)
    expect(decideProject).toHaveBeenCalledWith(session, 40, valid)
  })

  it('needs a session', async () => {
    auth.mockResolvedValue(null)
    expect((await post(valid)).status).toBe(401)
    expect(decideProject).not.toHaveBeenCalled()
  })

  it('rejects an invalid id', async () => {
    const res = await post(valid, 'abc')
    expect(res.status).toBe(400)
    expect(await res.json()).toEqual({ error: 'invalid_id' })
  })

  it('rejects a decision outside the vote set', async () => {
    const res = await post({ ...valid, decision: 'maybe' })
    expect(res.status).toBe(400)
    expect(await res.json()).toEqual({
      error: 'invalid_field',
      field: 'decision',
    })
    expect(decideProject).not.toHaveBeenCalled()
  })

  it('rejects non-string texts', async () => {
    const r = await post({ ...valid, rationale: 5 })
    expect(await r.json()).toEqual({
      error: 'invalid_field',
      field: 'rationale',
    })
    const s = await post({ ...valid, submitter_summary: null })
    expect(await s.json()).toEqual({
      error: 'invalid_field',
      field: 'submitter_summary',
    })
    expect(decideProject).not.toHaveBeenCalled()
  })

  it('refuses over-long texts', async () => {
    const summary = await post({
      ...valid,
      submitter_summary: 'x'.repeat(10001),
    })
    expect(summary.status).toBe(400)
    expect(await summary.json()).toEqual({ error: 'body_too_long' })
    const rationale = await post({ ...valid, rationale: 'x'.repeat(50001) })
    expect(await rationale.json()).toEqual({ error: 'body_too_long' })
    expect(decideProject).not.toHaveBeenCalled()
  })

  it('maps WordPress errors through reviewErrorResponse', async () => {
    decideProject.mockRejectedValue(
      new ReviewApiError('busy', 409, 'decision_in_progress')
    )
    const res = await post(valid)
    expect(res.status).toBe(409)
    expect((await res.json()).error).toBe('decision_in_progress')
  })
})
