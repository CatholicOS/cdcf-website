import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { BioApiError, saveMyTeamMember, type BioSavePayload } from '@/lib/bio-api'

// Allow-list of payload fields we forward to the WP PATCH endpoint.
// Extra keys in the request body are dropped silently (forward-compat
// friendly); any allow-listed field present with a non-string value
// rejects the whole request 400 (catches bug/abuse alike).
const ALLOWED_PAYLOAD_FIELDS = [
  'content',
  'member_title',
  'member_linkedin_url',
  'member_github_url',
] as const

// POST /api/my-bio/save
// Forwards the bio editor's payload to the WP PATCH endpoint, attaching
// the user's Zitadel access token server-side so it never reaches the
// browser. The client posts:
//   { lang: 'de', content: '...', member_title: '...', ... }
// We pull `lang` out, allow-list + type-check the remaining fields, and
// hand the sanitized object to saveMyTeamMember. WP-side
// sanitize_callbacks (wp_kses_post, sanitize_text_field, esc_url_raw)
// and the URL hostname allow-list run downstream; this is the first
// validation checkpoint.
export async function POST(request: NextRequest) {
  const session = await auth()
  if (!session?.user) {
    return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  }
  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: 'invalid_json' }, { status: 400 })
  }
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return NextResponse.json({ error: 'invalid_body' }, { status: 400 })
  }
  const record = body as Record<string, unknown>
  const lang = record.lang
  if (typeof lang !== 'string') {
    return NextResponse.json({ error: 'missing_lang' }, { status: 400 })
  }
  const payload: BioSavePayload = {}
  for (const field of ALLOWED_PAYLOAD_FIELDS) {
    if (!(field in record)) continue
    const value = record[field]
    if (typeof value !== 'string') {
      return NextResponse.json(
        { error: 'invalid_field', field, message: `${field} must be a string` },
        { status: 400 }
      )
    }
    payload[field] = value
  }
  try {
    const result = await saveMyTeamMember(session, lang, payload)
    return NextResponse.json(result)
  } catch (err) {
    if (err instanceof BioApiError) {
      return NextResponse.json(
        { error: err.code ?? 'wp_error', message: err.message },
        { status: err.status }
      )
    }
    throw err
  }
}
