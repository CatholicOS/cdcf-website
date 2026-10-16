import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { fetchInbox } from '@/lib/review-api'
import { reviewErrorResponse, unauthorized } from '@/lib/review-route'

export async function GET(request: NextRequest) {
  const session = await auth()
  if (!session?.user) return unauthorized()
  const { searchParams } = request.nextUrl
  const page = Math.max(
    1,
    Number.parseInt(searchParams.get('page') ?? '1', 10) || 1
  )
  try {
    return NextResponse.json(
      await fetchInbox(session, {
        unread: searchParams.get('unread') === '1',
        page,
      })
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
