import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))
const auth = vi.hoisted(() => vi.fn())
vi.mock('@/lib/auth', () => ({ auth }))
const askSubmitter = vi.hoisted(() => vi.fn())
vi.mock('@/lib/review-api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/review-api')>()),
  askSubmitter,
}))

import { POST } from './route'

const session = { user: { email: 'member@example.org' } }

const post = (body: unknown, headers: Record<string, string> = {}) =>
  POST(
    new NextRequest('http://localhost/api/review/projects/40/question', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: JSON.stringify(body),
    }),
    { params: Promise.resolve({ id: '40' }) }
  )

beforeEach(() => {
  auth.mockReset()
  auth.mockResolvedValue(session)
  askSubmitter.mockReset()
})

describe('POST /api/review/projects/[id]/question', () => {
  it('asks the submitter as the signed-in member', async () => {
    askSubmitter.mockResolvedValue({ id: 5, kind: 'question', warnings: [] })

    const res = await post({ body: 'Which licence?' })

    expect(res.status).toBe(201)
    expect(askSubmitter).toHaveBeenCalledWith(session, 40, {
      body: 'Which licence?',
    })
  })

  it('needs a session', async () => {
    auth.mockResolvedValue(null)
    expect((await post({ body: 'Q?' })).status).toBe(401)
    expect(askSubmitter).not.toHaveBeenCalled()
  })

  it('refuses over-long questions before calling WordPress', async () => {
    const tooManyChars = await post({ body: 'x'.repeat(50001) })
    expect(tooManyChars.status).toBe(400)
    expect(await tooManyChars.json()).toEqual({ error: 'body_too_long' })

    const tooManyBytes = await post(
      { body: 'Q?' },
      { 'content-length': String(257 * 1024) }
    )
    expect(tooManyBytes.status).toBe(400)
    expect(await tooManyBytes.json()).toEqual({ error: 'body_too_long' })

    expect(askSubmitter).not.toHaveBeenCalled()
  })
})
