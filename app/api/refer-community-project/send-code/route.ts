import { NextRequest, NextResponse } from 'next/server'
import { forwardedClientIpHeaders } from '@/lib/forwarded-client-ip'
import { clientIp, createIpRateLimiter } from '@/lib/ip-rate-limit'

// In-memory rate limiting: 5 code requests per hour per IP
const isRateLimited = createIpRateLimiter(5, 60 * 60 * 1000)

export async function POST(request: NextRequest) {
  const ip = clientIp(request)

  if (isRateLimited(ip)) {
    return NextResponse.json(
      { error: 'Too many requests. Please try again later.' },
      { status: 429 }
    )
  }

  let body: Record<string, unknown>
  try {
    body = await request.json()
  } catch {
    return NextResponse.json(
      { error: 'Invalid request body.' },
      { status: 400 }
    )
  }

  // Honeypot check — silently "succeed" if filled (don't alert bots)
  if (body.website) {
    return NextResponse.json({ success: true })
  }

  // Timing check — bots that POST directly without opening the modal fill too fast
  const elapsed = typeof body.elapsed_ms === 'number' ? body.elapsed_ms : 0
  if (elapsed > 0 && elapsed < 3000) {
    return NextResponse.json({ success: true })
  }

  // Validate required field
  if (!body.submitter_email) {
    return NextResponse.json(
      { error: 'Missing required fields.' },
      { status: 400 }
    )
  }

  // Proxy to WordPress
  const wpUrl = process.env.WP_GRAPHQL_URL
  if (!wpUrl) {
    return NextResponse.json(
      { error: 'Server configuration error.' },
      { status: 500 }
    )
  }

  const wpBase = wpUrl.replace(/\/graphql$/, '')

  try {
    const wpRes = await fetch(
      `${wpBase}/wp-json/cdcf/v1/refer-community-project/send-code`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...forwardedClientIpHeaders(ip),
        },
        body: JSON.stringify({
          project_name: (body.project_name as string) || '',
          category: (body.category as string) || '',
          description: (body.description as string) || '',
          project_url: (body.project_url as string) || '',
          github_url: (body.github_url as string) || '',
          tags: Array.isArray(body.tags) ? body.tags : [],
          submitter_name: (body.submitter_name as string) || '',
          submitter_email: body.submitter_email as string,
          honeypot: (body.website as string) || '',
          elapsed_ms: body.elapsed_ms || 0,
        }),
      }
    )

    if (!wpRes.ok) {
      const err = await wpRes.json().catch(() => ({}))
      return NextResponse.json(
        { error: err.message || 'Failed to send verification code.' },
        { status: wpRes.status }
      )
    }

    const data = await wpRes.json()
    return NextResponse.json(data)
  } catch {
    return NextResponse.json(
      { error: 'Could not reach the server. Please try again later.' },
      { status: 502 }
    )
  }
}
