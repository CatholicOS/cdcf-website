import { NextRequest, NextResponse } from 'next/server'
import { forwardedClientIpHeaders } from '@/lib/forwarded-client-ip'
import { clientIp, createIpRateLimiter } from '@/lib/ip-rate-limit'

// In-memory rate limiting: 3 submissions per hour per IP
const isRateLimited = createIpRateLimiter(3, 60 * 60 * 1000)

export async function POST(request: NextRequest) {
  const ip = clientIp(request)

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
  const { project_name, description, submitter_name, submitter_email, verification_code } = body as Record<string, string>
  if (!project_name || !description || !submitter_name || !submitter_email || !verification_code) {
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
    const wpRes = await fetch(`${wpBase}/wp-json/cdcf/v1/refer-community-project`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...forwardedClientIpHeaders(ip),
      },
      body: JSON.stringify({
        project_name: body.project_name,
        category: body.category || '',
        description: body.description,
        project_url: body.project_url || '',
        github_url: body.github_url || '',
        tags: Array.isArray(body.tags) ? body.tags : [],
        language: typeof body.language === 'string' ? body.language : '',
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
