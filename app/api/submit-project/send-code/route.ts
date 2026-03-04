import { NextRequest, NextResponse } from 'next/server'

// In-memory rate limiting: 5 code requests per hour per IP
const codeRateMap = new Map<string, number[]>()
const CODE_RATE_LIMIT = 5
const RATE_WINDOW = 60 * 60 * 1000 // 1 hour in ms

function isRateLimited(ip: string): boolean {
  const now = Date.now()
  const timestamps = codeRateMap.get(ip) ?? []
  const recent = timestamps.filter((t) => now - t < RATE_WINDOW)
  codeRateMap.set(ip, recent)
  if (recent.length >= CODE_RATE_LIMIT) return true
  recent.push(now)
  codeRateMap.set(ip, recent)
  return false
}

export async function POST(request: NextRequest) {
  const ip =
    request.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ||
    request.headers.get('x-real-ip') ||
    'unknown'

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
    return NextResponse.json({ error: 'Invalid request body.' }, { status: 400 })
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
    return NextResponse.json({ error: 'Missing required fields.' }, { status: 400 })
  }

  // Proxy to WordPress
  const wpUrl = process.env.WP_GRAPHQL_URL
  if (!wpUrl) {
    return NextResponse.json({ error: 'Server configuration error.' }, { status: 500 })
  }

  const wpBase = wpUrl.replace(/\/graphql$/, '')

  try {
    const wpRes = await fetch(`${wpBase}/wp-json/cdcf/v1/submit-project/send-code`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        project_name: (body.project_name as string) || '',
        category: (body.category as string) || '',
        description: (body.description as string) || '',
        url: (body.url as string) || '',
        repo_urls: Array.isArray(body.repo_urls) ? body.repo_urls : [],
        submitter_name: (body.submitter_name as string) || '',
        submitter_email: body.submitter_email as string,
        honeypot: (body.website as string) || '',
        elapsed_ms: body.elapsed_ms || 0,
      }),
    })

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
