import { NextRequest, NextResponse } from 'next/server'

// In-memory rate limiting: 3 submissions per hour per IP
const rateMap = new Map<string, number[]>()
const RATE_LIMIT = 3
const RATE_WINDOW = 60 * 60 * 1000 // 1 hour in ms

function isRateLimited(ip: string): boolean {
  const now = Date.now()
  const timestamps = rateMap.get(ip) ?? []
  const recent = timestamps.filter((t) => now - t < RATE_WINDOW)
  rateMap.set(ip, recent)
  if (recent.length >= RATE_LIMIT) return true
  recent.push(now)
  rateMap.set(ip, recent)
  return false
}

export async function POST(request: NextRequest) {
  const ip =
    request.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ||
    request.headers.get('x-real-ip') ||
    'unknown'

  if (isRateLimited(ip)) {
    return NextResponse.json(
      { error: 'Too many submissions. Please try again later.' },
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

  // Validate required fields
  const { project_name, url, description, submitter_name, submitter_email, verification_code } = body as Record<string, string>
  if (!project_name || !url || !description || !submitter_name || !submitter_email || !verification_code) {
    return NextResponse.json({ error: 'Missing required fields.' }, { status: 400 })
  }

  // Proxy to WordPress
  const wpUrl = process.env.WP_GRAPHQL_URL
  if (!wpUrl) {
    return NextResponse.json({ error: 'Server configuration error.' }, { status: 500 })
  }

  // Derive the WordPress base URL from the GraphQL endpoint
  const wpBase = wpUrl.replace(/\/graphql$/, '')

  try {
    const wpRes = await fetch(`${wpBase}/wp-json/cdcf/v1/submit-project`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        project_name: body.project_name,
        category: (body.category as string) || '',
        description: body.description,
        url: body.url,
        repo_urls: Array.isArray(body.repo_urls) ? body.repo_urls : [],
        submitter_name: body.submitter_name,
        submitter_email: body.submitter_email,
        verification_code: body.verification_code || '',
      }),
    })

    if (!wpRes.ok) {
      const err = await wpRes.json().catch(() => ({}))
      return NextResponse.json(
        { error: err.message || 'Submission failed.' },
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
