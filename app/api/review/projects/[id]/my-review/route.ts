import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { saveMyReview } from '@/lib/review-api'
import { REVIEW_VOTES, type ReviewVote } from '@/lib/review-types'
import {
  badRequest,
  parseId,
  readJsonObject,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'

export async function PUT(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()
  const body = await readJsonObject(request)
  if (!body) return badRequest('invalid_body')
  const { vote, body_md, version } = body
  if (
    typeof vote !== 'string' ||
    !(REVIEW_VOTES as readonly string[]).includes(vote)
  ) {
    return badRequest('invalid_vote')
  }
  if (typeof body_md !== 'string') return badRequest('invalid_field', 'body_md')
  if (version !== undefined && typeof version !== 'string')
    return badRequest('invalid_field', 'version')
  try {
    return NextResponse.json(
      await saveMyReview(session, id, {
        vote: vote as ReviewVote,
        body_md,
        ...(version ? { version } : {}),
      })
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
