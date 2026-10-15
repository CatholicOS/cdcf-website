import { cookies } from 'next/headers'
import { redirect } from 'next/navigation'
import type { NextRequest } from 'next/server'
import { parseId } from '@/lib/review-route'
import {
  STATUS_TOKEN_PATTERN,
  statusCookieName,
  statusCookieOptions,
  statusPagePath,
} from '@/lib/submission-token'

/**
 * The emailed link. Moves the token into an httpOnly cookie and redirects
 * to the clean page, so the token leaves the address bar and history.
 */
export async function GET(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return new Response(null, { status: 404 })
  const { searchParams } = request.nextUrl
  const token = searchParams.get('t') ?? ''
  if (STATUS_TOKEN_PATTERN.test(token)) {
    ;(await cookies()).set(
      statusCookieName(id),
      token,
      statusCookieOptions(process.env.NODE_ENV === 'production')
    )
  }
  redirect(statusPagePath(searchParams.get('lang') ?? '', id))
}
