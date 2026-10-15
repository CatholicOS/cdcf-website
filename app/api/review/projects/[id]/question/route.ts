import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { askSubmitter } from '@/lib/review-api'
import {
  badRequest,
  parseId,
  readJsonObject,
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
  const body = await readJsonObject(request)
  if (!body) return badRequest('invalid_body')
  if (typeof body.body !== 'string') return badRequest('invalid_field', 'body')
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
