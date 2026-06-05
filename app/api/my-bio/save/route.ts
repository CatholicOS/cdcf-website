import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { BioApiError, saveMyTeamMember, type BioSavePayload } from '@/lib/bio-api'

// POST /api/my-bio/save
// Forwards the bio editor's payload to the WP PATCH endpoint, attaching
// the user's Zitadel access token server-side so it never reaches the
// browser. The client posts:
//   { lang: 'de', content: '...', member_title: '...', ... }
// We pull `lang` out and pass the rest as the PATCH body.
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
  if (!body || typeof body !== 'object') {
    return NextResponse.json({ error: 'invalid_body' }, { status: 400 })
  }
  const { lang, ...payload } = body as { lang?: unknown } & BioSavePayload
  if (typeof lang !== 'string') {
    return NextResponse.json({ error: 'missing_lang' }, { status: 400 })
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
