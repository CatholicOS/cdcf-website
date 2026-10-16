import { NextResponse, type NextRequest } from 'next/server'
import { clientIp, createIpRateLimiter } from '@/lib/ip-rate-limit'
import { readLimitedJsonObject } from '@/lib/review-route'
import { requestStatusLink } from '@/lib/submission-status-api'

const limited = createIpRateLimiter(5, 60 * 60 * 1000)

/** Always the same answer, as in WordPress, whatever happened. */
export async function POST(request: NextRequest) {
  const accepted = NextResponse.json({ accepted: true }, { status: 202 })
  // A project id and an address fit easily in 4 KiB.
  const read = await readLimitedJsonObject(request, 4 * 1024)
  const { project_id, email } = read.ok ? read.value : {}
  const ip = clientIp(request)
  if (
    limited(ip) ||
    typeof project_id !== 'number' ||
    !Number.isInteger(project_id) ||
    project_id <= 0 ||
    typeof email !== 'string' ||
    email.length > 254
  ) {
    return accepted
  }
  try {
    await requestStatusLink({ project_id, email }, ip)
  } catch (err) {
    console.error(
      '[submission-status] resend-link failed',
      err instanceof Error ? err.message : err
    )
  }
  return accepted
}
