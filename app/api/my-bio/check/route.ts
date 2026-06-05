import { NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { fetchMyTeamMember, type BioDiscovery } from '@/lib/bio-api'

// GET /api/my-bio/check
// Tiny proxy used by the AuthButton header dropdown to decide whether
// to show the "Edit my bio" entry. Returns {linked, available_languages}
// rather than just a boolean so the editor page can reuse the same
// fetch without a second round-trip if desired.
//
// Fails soft on EVERY error path: this is a UI-decoration endpoint, not
// a security boundary. Showing or hiding the dropdown entry must never
// surface a 500 to the browser console. Anonymous, unlinked, expired
// token, unreachable WP, missing env vars — all collapse to
// {linked: false} with HTTP 200, and the underlying error is
// console.error'd server-side for diagnosis. The /[lang]/my-bio page
// itself still surfaces real errors as the appropriate localized copy.
export async function GET() {
  const session = await auth()
  if (!session?.user) {
    return NextResponse.json({ linked: false, available_languages: [] })
  }
  try {
    const discovery: BioDiscovery = await fetchMyTeamMember(session)
    return NextResponse.json({
      linked: true,
      team_member_id: discovery.team_member_id,
      available_languages: discovery.available_languages,
    })
  } catch (err) {
    console.error('[my-bio/check] discovery failed (degrading to linked=false):', err)
    return NextResponse.json({ linked: false, available_languages: [] })
  }
}
