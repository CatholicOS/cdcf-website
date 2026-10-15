import { cookies } from 'next/headers'
import { NextResponse, type NextRequest } from 'next/server'
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
} from '@/lib/review-route'
import { postSubmissionAnswer } from '@/lib/submission-status-api'
import { STATUS_TOKEN_PATTERN, statusCookieName } from '@/lib/submission-token'

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const token = (await cookies()).get(statusCookieName(id))?.value ?? ''
  if (!STATUS_TOKEN_PATTERN.test(token)) {
    return NextResponse.json({ error: 'not_found' }, { status: 404 })
  }
  const body = await readJsonObject(request)
  if (!body) return badRequest('invalid_body')
  const { question_id, body: text } = body
  if (
    typeof question_id !== 'number' ||
    !Number.isInteger(question_id) ||
    question_id <= 0
  ) {
    return badRequest('invalid_field', 'question_id')
  }
  if (typeof text !== 'string') return badRequest('invalid_field', 'body')
  try {
    return NextResponse.json(
      await postSubmissionAnswer(id, token, { question_id, body: text }),
      { status: 201 }
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
