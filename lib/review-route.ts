/**
 * Helpers shared by the /api/review/* route handlers: id parsing, JSON
 * bodies, error mapping, and the attachment byte passthrough.
 */
import { NextResponse } from 'next/server'
import { ReviewApiError } from '@/lib/review-api'

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

export function badRequest(error: string, field?: string): NextResponse {
  return NextResponse.json(field ? { error, field } : { error }, {
    status: 400,
  })
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

export const ATTACHMENT_PASSTHROUGH_HEADERS = [
  'content-type',
  'content-length',
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
