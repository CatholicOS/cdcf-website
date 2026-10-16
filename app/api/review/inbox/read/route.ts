import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { markInboxRead } from '@/lib/review-api'
import {
  badRequest,
  readLimitedJsonObject,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'
import { INBOX_READ_MAX, type InboxReadPayload } from '@/lib/review-types'

function toPayload(body: Record<string, unknown>): InboxReadPayload | null {
  if (body.all === true) return { all: true }
  const ids = body.event_ids
  if (
    Array.isArray(ids) &&
    ids.length > 0 &&
    ids.length <= INBOX_READ_MAX &&
    ids.every((id) => Number.isInteger(id) && (id as number) > 0)
  ) {
    return { event_ids: ids as number[] }
  }
  return null
}

export async function POST(request: NextRequest) {
  const session = await auth()
  if (!session?.user) return unauthorized()
  const read = await readLimitedJsonObject(request, 16 * 1024)
  if (!read.ok) return badRequest(read.error)
  const payload = toPayload(read.value)
  if (!payload)
    return badRequest(
      'invalid_field',
      'all' in read.value ? 'all' : 'event_ids'
    )
  try {
    return NextResponse.json(await markInboxRead(session, payload))
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
