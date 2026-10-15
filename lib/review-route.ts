/**
 * Helpers shared by the /api/review/* route handlers: id parsing, JSON
 * bodies, error mapping, and the attachment byte passthrough.
 */
import { NextResponse } from 'next/server'
import { ReviewApiError } from '@/lib/review-api'
import { checkAttachmentFile } from '@/lib/review-ui'

export function parseId(raw: string): number | null {
  return /^[1-9]\d{0,15}$/.test(raw) ? Number(raw) : null
}

export async function readJsonObject(
  request: Request
): Promise<Record<string, unknown> | null> {
  try {
    const body: unknown = await request.json()
    return body && typeof body === 'object' && !Array.isArray(body)
      ? (body as Record<string, unknown>)
      : null
  } catch {
    return null
  }
}

export type LimitedJson =
  | { ok: true; value: Record<string, unknown> }
  | { ok: false; error: 'invalid_body' | 'body_too_long' }

/**
 * Read a JSON object body of at most `maxBytes`. Route handlers have no
 * body-size limit of their own, so the size is enforced while the body is
 * consumed — a declared Content-Length is refused up front, and reading
 * stops as soon as the streamed bytes pass the limit — before parsing.
 */
export async function readLimitedJsonObject(
  request: Request,
  maxBytes: number
): Promise<LimitedJson> {
  const tooLong = { ok: false, error: 'body_too_long' } as const
  const invalid = { ok: false, error: 'invalid_body' } as const
  const declared = Number(request.headers.get('content-length'))
  if (Number.isFinite(declared) && declared > maxBytes) return tooLong
  if (!request.body) return invalid

  const reader = request.body.getReader()
  const chunks: Uint8Array[] = []
  let size = 0
  try {
    for (;;) {
      const { done, value } = await reader.read()
      if (done) break
      size += value.byteLength
      if (size > maxBytes) {
        await reader.cancel()
        return tooLong
      }
      chunks.push(value)
    }
  } catch {
    return invalid
  }

  const bytes = new Uint8Array(size)
  let offset = 0
  for (const chunk of chunks) {
    bytes.set(chunk, offset)
    offset += chunk.byteLength
  }
  try {
    const parsed: unknown = JSON.parse(new TextDecoder().decode(bytes))
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
      ? { ok: true, value: parsed as Record<string, unknown> }
      : invalid
  } catch {
    return invalid
  }
}

export function badRequest(error: string, field?: string): NextResponse {
  return NextResponse.json(field ? { error, field } : { error }, {
    status: 400,
  })
}

/**
 * The multipart `file` field, or the error response to send instead. A
 * truncated or malformed body is the client's fault (400), not a 500.
 */
export async function readUploadFile(
  request: Request
): Promise<File | NextResponse> {
  let form: FormData
  try {
    form = await request.formData()
  } catch {
    return badRequest('invalid_body')
  }
  const file = form.get('file')
  if (!(file instanceof File) || file.size === 0)
    return badRequest('empty_file')
  const problem = checkAttachmentFile(file.name, file.size)
  if (problem === 'tooLarge')
    return NextResponse.json({ error: 'file_too_large' }, { status: 413 })
  if (problem !== null) return badRequest('unsupported_type')
  return file
}

export function unauthorized(): NextResponse {
  return NextResponse.json({ error: 'unauthorized' }, { status: 401 })
}

export function reviewErrorResponse(err: unknown): NextResponse {
  if (err instanceof ReviewApiError) {
    return NextResponse.json(
      { error: err.code ?? 'wp_error', message: err.message },
      { status: err.status }
    )
  }
  throw err
}

// No content-length: undici may decompress the upstream body, so a
// forwarded length could disagree with the bytes actually sent.
export const ATTACHMENT_PASSTHROUGH_HEADERS = [
  'content-type',
  'content-disposition',
  'cache-control',
  'x-content-type-options',
  'content-security-policy',
] as const

export function attachmentResponse(wp: Response): Response {
  const headers = new Headers()
  for (const name of ATTACHMENT_PASSTHROUGH_HEADERS) {
    const value = wp.headers.get(name)
    if (value) headers.set(name, value)
  }
  return new Response(wp.body, { status: wp.status, headers })
}
