import { cookies, draftMode } from 'next/headers'
import { redirect } from 'next/navigation'
import { NextRequest } from 'next/server'
import { PREVIEW_COOKIE, serializePreviewCookie } from '@/lib/wordpress/preview'
import { locales } from '@/src/i18n/routing'

// Only post/page have by-id preview support (mirrors the theme's
// preview_post_link filter, which only rewrites these types).
const PREVIEWABLE_TYPES = new Set(['post', 'page'])

export async function GET(request: NextRequest) {
  const { searchParams } = request.nextUrl
  const secret = searchParams.get('secret')
  const id = searchParams.get('id')
  const type = searchParams.get('type') || 'post'
  const slug = searchParams.get('slug') || ''
  const langParam = searchParams.get('lang') || 'en'

  if (secret !== process.env.WP_PREVIEW_SECRET) {
    return new Response('Invalid token', { status: 401 })
  }

  const postId = Number(id)
  if (!id || !Number.isInteger(postId) || postId <= 0) {
    return new Response('Invalid or missing post id', { status: 400 })
  }

  if (!PREVIEWABLE_TYPES.has(type)) {
    return new Response('Unsupported post type', { status: 400 })
  }

  // The slug is concatenated into the redirect path, so reject anything that
  // could escape it into an absolute/protocol-relative (open-redirect) URL.
  if (/^\/|\/\/|\\|:/.test(slug)) {
    return new Response('Invalid slug', { status: 400 })
  }

  // Normalize the locale to a known one so the path prefix can't be spoofed.
  const lang = (locales as readonly string[]).includes(langParam)
    ? langParam
    : 'en'

  const draft = await draftMode()
  draft.enable()

  // Carry the target so the page routes can fetch it by id and scope preview
  // rendering to this post only (draft mode otherwise affects every route).
  ;(await cookies()).set(PREVIEW_COOKIE, serializePreviewCookie({ id: postId, type, slug }), {
    httpOnly: true,
    sameSite: 'lax',
    secure: process.env.NODE_ENV === 'production',
    path: '/',
    maxAge: 60 * 60, // 1h; bounds an orphaned preview target if exit isn't hit
  })

  // Address the preview target by its database id, not its slug. The editor
  // can hand us a stale slug — e.g. after a slug change the block editor hasn't
  // refreshed, or for a never-published draft with no slug yet — which would
  // 404 against the published-slug lookup. The id is stable and the page routes
  // match it directly via previewMatchesSlug(), so id-based segments make
  // preview immune to slug drift.
  const segment = String(postId)
  const prefix = lang && lang !== 'en' ? `/${lang}` : ''
  const path =
    type === 'post' ? `${prefix}/blog/${segment}` : `${prefix}/${segment}`

  redirect(path)
}
