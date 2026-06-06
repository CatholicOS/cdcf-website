import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import {
  BioApiError,
  fetchMyTeamMember,
  fetchTeamMemberPost,
} from '@/lib/bio-api'

// GET /api/my-bio/load/{lang}
// Resolves the requested language's post id via the discovery endpoint
// (so the post_id never has to be trusted from the client) and returns
// the editable post content. Used by BioEditor when the user switches
// the language selector.
export async function GET(
  _request: NextRequest,
  { params }: { params: Promise<{ lang: string }> }
) {
  const { lang } = await params
  if (!/^[a-z]{2}$/.test(lang)) {
    return NextResponse.json({ error: 'invalid_lang' }, { status: 400 })
  }
  const session = await auth()
  if (!session?.user) {
    return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
  }
  try {
    const discovery = await fetchMyTeamMember(session)
    const entry = discovery.available_languages.find((l) => l.slug === lang)
    if (!entry) {
      return NextResponse.json(
        { error: 'no_translation_for_lang' },
        { status: 404 }
      )
    }
    const post = await fetchTeamMemberPost(session, lang)
    return NextResponse.json(post)
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
