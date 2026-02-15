import { revalidatePath } from 'next/cache'
import { NextRequest } from 'next/server'

export async function POST(request: NextRequest) {
  const body = await request.json()
  const secret = body.secret || request.headers.get('x-revalidate-secret')

  if (secret !== process.env.WORDPRESS_PREVIEW_SECRET) {
    return Response.json({ message: 'Invalid token' }, { status: 401 })
  }

  const path = body.path || '/'

  revalidatePath(path, 'page')

  return Response.json({ revalidated: true, path })
}
