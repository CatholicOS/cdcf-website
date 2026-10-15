import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { patchReviewEntry } from '@/lib/review-api'
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'

export async function PATCH(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()
  const body = await readJsonObject(request)
  if (!body) return badRequest('invalid_body')
  const { body_md, version } = body
  if (typeof body_md !== 'string') return badRequest('invalid_field', 'body_md')
  if (typeof version !== 'string' || version === '')
    return badRequest('invalid_field', 'version')
  try {
    return NextResponse.json(
      await patchReviewEntry(session, id, { body_md, version })
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
