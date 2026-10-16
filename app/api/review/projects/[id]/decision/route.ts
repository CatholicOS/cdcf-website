import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { decideProject } from '@/lib/review-api'
import {
  badRequest,
  parseId,
  readLimitedJsonObject,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'
import {
  DECISION_SUMMARY_MAX,
  REVIEW_VOTES,
  type ReviewVote,
} from '@/lib/review-types'

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()
  // Room for both texts at their limits, even at 4 bytes a character.
  const read = await readLimitedJsonObject(request, 256 * 1024)
  if (!read.ok) return badRequest(read.error)
  const { decision, rationale, submitter_summary } = read.value
  if (!REVIEW_VOTES.includes(decision as ReviewVote))
    return badRequest('invalid_field', 'decision')
  if (typeof rationale !== 'string')
    return badRequest('invalid_field', 'rationale')
  if (typeof submitter_summary !== 'string')
    return badRequest('invalid_field', 'submitter_summary')
  if (
    rationale.length > 50000 ||
    submitter_summary.length > DECISION_SUMMARY_MAX
  )
    return badRequest('body_too_long')
  try {
    return NextResponse.json(
      await decideProject(session, id, {
        decision: decision as ReviewVote,
        rationale,
        submitter_summary,
      })
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
