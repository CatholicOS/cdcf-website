import { cookies, draftMode } from 'next/headers'
import { redirect } from 'next/navigation'
import { NextRequest } from 'next/server'
import { PREVIEW_COOKIE, serializePreviewCookie } from '@/lib/wordpress/preview'

export async function GET(request: NextRequest) {
  const { searchParams } = request.nextUrl
  const secret = searchParams.get('secret')
  const id = searchParams.get('id')
  const type = searchParams.get('type') || 'post'
  const slug = searchParams.get('slug') || ''
  const lang = searchParams.get('lang') || 'en'

  if (secret !== process.env.WP_PREVIEW_SECRET) {
    return new Response('Invalid token', { status: 401 })
  }

  const postId = Number(id)
  if (!id || !Number.isInteger(postId) || postId <= 0) {
    return new Response('Invalid or missing post id', { status: 400 })
  }

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

  // New drafts have no slug yet — fall back to the numeric id as the segment;
  // the page route resolves the real post from the cookie regardless.
  const segment = slug || String(postId)
  const prefix = lang && lang !== 'en' ? `/${lang}` : ''
  const path =
    type === 'post' ? `${prefix}/blog/${segment}` : `${prefix}/${segment}`

  redirect(path)
}
