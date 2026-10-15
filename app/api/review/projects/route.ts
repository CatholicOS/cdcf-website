import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { fetchReviewProjects } from '@/lib/review-api'
import { REVIEW_STAGES, type ReviewStage } from '@/lib/review-types'
import {
  badRequest,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'

export async function GET(request: NextRequest) {
  const session = await auth()
  if (!session?.user) return unauthorized()
  const stageParam = request.nextUrl.searchParams.get('stage') ?? ''
  if (
    stageParam !== '' &&
    !(REVIEW_STAGES as readonly string[]).includes(stageParam)
  ) {
    return badRequest('invalid_stage')
  }
  const page = Number.parseInt(
    request.nextUrl.searchParams.get('page') ?? '1',
    10
  )
  try {
    return NextResponse.json(
      await fetchReviewProjects(session, {
        stage: stageParam === '' ? undefined : (stageParam as ReviewStage),
        page: Number.isFinite(page) && page > 0 ? page : 1,
      })
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
