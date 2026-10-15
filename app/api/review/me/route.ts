import { NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { fetchReviewMe } from '@/lib/review-api'

/**
 * Fail-soft membership probe for the header menu: anonymous users,
 * non-members and WordPress errors all read as `{ member: false }`.
 */
export async function GET() {
  const session = await auth()
  if (!session?.user) {
    return NextResponse.json({ member: false })
  }
  try {
    return NextResponse.json(await fetchReviewMe(session))
  } catch (err) {
    console.error('review/me failed', err)
    return NextResponse.json({ member: false })
  }
}
