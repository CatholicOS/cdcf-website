import type { NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { fetchReviewAttachment } from '@/lib/review-api'
import {
  attachmentResponse,
  badRequest,
  parseId,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'

export async function GET(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()
  try {
    const download = request.nextUrl.searchParams.get('download') === '1'
    return attachmentResponse(
      await fetchReviewAttachment(session, id, download)
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
