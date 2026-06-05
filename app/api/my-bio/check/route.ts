import { NextResponse } from 'next/server'
import { auth } from '@/lib/auth'
import { BioApiError, fetchMyTeamMember } from '@/lib/bio-api'

// GET /api/my-bio/check
// Tiny proxy used by the AuthButton header dropdown to decide whether
// to show the "Edit my bio" entry. Returns {linked, available_languages}
// rather than just a boolean so the editor page can reuse the same
// fetch without a second round-trip if desired.
//
// Anonymous callers get {linked: false} with HTTP 200 (the dropdown
// shouldn't blink a 401 on every header render for logged-out users).
// Authenticated callers without a team_member link get the same shape
// — the WP-side endpoint returns a 403 we translate to "no link".
export async function GET() {
  const session = await auth()
  if (!session?.user) {
    return NextResponse.json({ linked: false, available_languages: [] })
  }
  try {
    const discovery = await fetchMyTeamMember(session)
    return NextResponse.json({
      linked: true,
      team_member_id: discovery.team_member_id,
      available_languages: discovery.available_languages,
    })
  } catch (err) {
    if (err instanceof BioApiError && (err.status === 401 || err.status === 403)) {
      return NextResponse.json({ linked: false, available_languages: [] })
    }
    throw err
  }
}
