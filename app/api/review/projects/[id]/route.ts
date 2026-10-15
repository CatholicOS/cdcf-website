import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { fetchReviewProject } from '@/lib/review-api'
import {
  badRequest,
  parseId,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'

export async function GET(
  _request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()
  try {
    return NextResponse.json(await fetchReviewProject(session, id))
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
