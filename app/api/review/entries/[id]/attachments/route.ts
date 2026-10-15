import { NextResponse, type NextRequest } from 'next/server'
import { auth } from '@/lib/auth'
import { uploadReviewAttachment } from '@/lib/review-api'
import { ATTACHMENT_LIMITS } from '@/lib/review-types'
import {
  badRequest,
  parseId,
  readJsonObject,
  readUploadFile,
  reviewErrorResponse,
  unauthorized,
} from '@/lib/review-route'

const tooLarge = () =>
  NextResponse.json({ error: 'file_too_large' }, { status: 413 })

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const id = parseId((await params).id)
  if (id === null) return badRequest('invalid_id')
  const session = await auth()
  if (!session?.user) return unauthorized()

  try {
    if (
      (request.headers.get('content-type') ?? '').startsWith(
        'multipart/form-data'
      )
    ) {
      const file = await readUploadFile(request)
      if (!(file instanceof File)) return file
      const form = new FormData()
      form.append('file', file, file.name)
      return NextResponse.json(
        await uploadReviewAttachment(session, id, form),
        { status: 201 }
      )
    }
    const body = await readJsonObject(request)
    if (
      !body ||
      typeof body.markdown !== 'string' ||
      body.markdown.trim() === ''
    )
      return badRequest('empty_file')
    if (
      new TextEncoder().encode(body.markdown).length >
      ATTACHMENT_LIMITS.markdownBytes
    )
      return tooLarge()
    const title = typeof body.title === 'string' ? body.title : ''
    return NextResponse.json(
      await uploadReviewAttachment(session, id, {
        title,
        markdown: body.markdown,
      }),
      { status: 201 }
    )
  } catch (err) {
    return reviewErrorResponse(err)
  }
}
