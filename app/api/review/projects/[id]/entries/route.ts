import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { postReviewEntry } from '@/lib/review-api'
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
  const { kind, parent_id, body_md } = body
  if (kind !== 'reply' && kind !== 'discussion')
    return badRequest('invalid_kind')
  if (typeof body_md !== 'string') return badRequest('invalid_field', 'body_md')
  if (
    kind === 'reply' &&
    (typeof parent_id !== 'number' ||
      !Number.isInteger(parent_id) ||
      parent_id <= 0)
  ) {
    return badRequest('invalid_field', 'parent_id')
  }
  try {
    return NextResponse.json(
      await postReviewEntry(session, id, {
        kind,
        body_md,
        ...(kind === 'reply' ? { parent_id: parent_id as number } : {}),
      })
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
