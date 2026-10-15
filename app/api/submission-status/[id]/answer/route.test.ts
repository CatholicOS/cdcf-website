import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
const cookieValue = vi.hoisted(() => ({
  current: undefined as string | undefined,
}))
vi.mock('next/headers', () => ({
  cookies: vi.fn(async () => ({
    get: (name: string) =>
      name === 'cdcf_status_40' && cookieValue.current !== undefined
        ? { value: cookieValue.current }
        : undefined,
  })),
}))
const postSubmissionAnswer = vi.hoisted(() => vi.fn())
vi.mock('@/lib/submission-status-api', () => ({ postSubmissionAnswer }))

import { ReviewApiError } from '@/lib/review-api'
import { POST } from './route'

const TOKEN = 'a1b2c3d4'.repeat(8)

const post = (body: unknown, headers: Record<string, string> = {}) =>
  POST(
    new NextRequest('http://localhost/api/submission-status/40/answer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: typeof body === 'string' ? body : JSON.stringify(body),
    }),
    { params: Promise.resolve({ id: '40' }) }
  )

beforeEach(() => {
  cookieValue.current = TOKEN
  postSubmissionAnswer.mockReset()
})

describe('POST /api/submission-status/[id]/answer', () => {
  it('forwards a valid answer with the cookie token', async () => {
    postSubmissionAnswer.mockResolvedValue({
      id: 9,
      question_id: 1,
      stage: 'in_review',
    })

    const res = await post({ question_id: 1, body: 'MIT' })

    expect(res.status).toBe(201)
    expect(await res.json()).toEqual({
      id: 9,
      question_id: 1,
      stage: 'in_review',
    })
    expect(postSubmissionAnswer).toHaveBeenCalledWith(40, TOKEN, {
      question_id: 1,
      body: 'MIT',
    })
  })

  it('answers 404 without a well-formed token cookie, before reading the body', async () => {
    for (const value of [undefined, 'abc', TOKEN.toUpperCase()]) {
      cookieValue.current = value
      const res = await post({ question_id: 1, body: 'MIT' })
      expect(res.status).toBe(404)
      expect(await res.json()).toEqual({ error: 'not_found' })
    }
    expect(postSubmissionAnswer).not.toHaveBeenCalled()
  })

  it('refuses over-long answers before calling WordPress', async () => {
    const tooManyChars = await post({ question_id: 1, body: 'x'.repeat(10001) })
    expect(tooManyChars.status).toBe(400)
    expect(await tooManyChars.json()).toEqual({ error: 'body_too_long' })

    const tooManyBytes = await post(
      { question_id: 1, body: 'MIT' },
      { 'content-length': String(65 * 1024) }
    )
    expect(tooManyBytes.status).toBe(400)
    expect(await tooManyBytes.json()).toEqual({ error: 'body_too_long' })

    expect(postSubmissionAnswer).not.toHaveBeenCalled()
  })

  it('validates the fields', async () => {
    expect(await (await post('{oops')).json()).toEqual({
      error: 'invalid_body',
    })
    expect(await (await post({ question_id: 0, body: 'x' })).json()).toEqual({
      error: 'invalid_field',
      field: 'question_id',
    })
    expect(await (await post({ question_id: 1, body: 5 })).json()).toEqual({
      error: 'invalid_field',
      field: 'body',
    })
    expect(postSubmissionAnswer).not.toHaveBeenCalled()
  })

  it('passes WordPress refusals through with their code and status', async () => {
    postSubmissionAnswer.mockRejectedValue(
      new ReviewApiError('Already answered.', 409, 'already_answered')
    )

    const res = await post({ question_id: 1, body: 'again' })

    expect(res.status).toBe(409)
    expect((await res.json()).error).toBe('already_answered')
  })
})
