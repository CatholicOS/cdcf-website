import { NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { fetchInboxCount } from '@/lib/review-api'
import { reviewErrorResponse, unauthorized } from '@/lib/review-route'

export async function GET() {
  const session = await auth()
  if (!session?.user) return unauthorized()
  try {
    return NextResponse.json(await fetchInboxCount(session))
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
