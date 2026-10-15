import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { askSubmitter } from '@/lib/review-api'
import {
  badRequest,
  parseId,
  readLimitedJsonObject,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()
  // Room for the longest allowed text in JSON, even at 4 bytes a character.
  const read = await readLimitedJsonObject(request, 256 * 1024)
  if (!read.ok) return badRequest(read.error)
  const body = read.value
  if (typeof body.body !== 'string') return badRequest('invalid_field', 'body')
  if (body.body.length > 50000) return badRequest('body_too_long')
  try {
    return NextResponse.json(
      await askSubmitter(session, id, { body: body.body }),
      {
        status: 201,
      }
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
