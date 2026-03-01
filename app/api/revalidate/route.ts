import { revalidatePath, revalidateTag } from 'next/cache'
import { NextRequest } from 'next/server'

export async function POST(request: NextRequest) {
  const body = await request.json()
  const secret = body.secret || request.headers.get('x-revalidate-secret')

  if (secret !== process.env.WORDPRESS_PREVIEW_SECRET) {
    return Response.json({ message: 'Invalid token' }, { status: 401 })
  }

  const revalidated: { paths: string[]; tags: string[] } = {
    paths: [],
    tags: [],
  }

  // Revalidate by path (existing behavior)
  if (body.path) {
    revalidatePath(body.path, 'page')
    revalidated.paths.push(body.path)
  }

  // Revalidate by cache tags
  if (Array.isArray(body.tags)) {
    for (const tag of body.tags) {
      if (typeof tag === 'string') {
        revalidateTag(tag)
        revalidated.tags.push(tag)
      }
    }
  }

  return Response.json({ revalidated: true, ...revalidated })
}
