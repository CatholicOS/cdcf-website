import { cookies, draftMode } from 'next/headers'
import { redirect } from 'next/navigation'
import { NextRequest } from 'next/server'
import { PREVIEW_COOKIE } from '@/lib/wordpress/preview'

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

  if (!id) {
    return new Response('No post id provided', { status: 400 })
  }

  const draft = await draftMode()
  draft.enable()

  // Carry the target so the page routes can fetch it by id and scope preview
  // rendering to this post only (draft mode otherwise affects every route).
  ;(await cookies()).set(
    PREVIEW_COOKIE,
    JSON.stringify({ id: Number(id), type, slug }),
    { httpOnly: true, sameSite: 'lax', path: '/' }
  )

  // New drafts have no slug yet — fall back to the numeric id as the segment;
  // the page route resolves the real post from the cookie regardless.
  const segment = slug || id
  const prefix = lang && lang !== 'en' ? `/${lang}` : ''
  const path =
    type === 'post' ? `${prefix}/blog/${segment}` : `${prefix}/${segment}`

  redirect(path)
}
