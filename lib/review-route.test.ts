import { describe, expect, it, vi } from 'vitest'

// review-api → auth-utils → next-auth, which Node can't load outside Next.
vi.mock('@/lib/auth-utils', () => ({ getAccessToken: vi.fn() }))

import { ReviewApiError } from './review-api'
import {
  attachmentResponse,
  badRequest,
  parseId,
  readJsonObject,
  readLimitedJsonObject,
  readUploadFile,
  reviewErrorResponse,
  unauthorized,
} from './review-route'

describe('review-route helpers', () => {
  it('parses positive integer ids only', () => {
    expect(parseId('42')).toBe(42)
    for (const bad of [
      '0',
      '-1',
      '1.5',
      'abc',
      '',
      '01',
      '99999999999999999999',
    ]) {
      expect(parseId(bad)).toBeNull()
    }
  })

  it('reads JSON objects and rejects everything else', async () => {
    const req = (body: string) =>
      new Request('http://x', { method: 'POST', body })
    expect(await readJsonObject(req('{"a":1}'))).toEqual({ a: 1 })
    expect(await readJsonObject(req('[1]'))).toBeNull()
    expect(await readJsonObject(req('null'))).toBeNull()
    expect(await readJsonObject(req('{oops'))).toBeNull()
  })

  it('builds 400 and 401 responses', async () => {
    const r = badRequest('invalid_field', 'body_md')
    expect(r.status).toBe(400)
    expect(await r.json()).toEqual({
      error: 'invalid_field',
      field: 'body_md',
    })
    expect(unauthorized().status).toBe(401)
  })

  it('maps ReviewApiError to its status and code', async () => {
    const r = reviewErrorResponse(
      new ReviewApiError('Locked.', 403, 'review_locked')
    )
    expect(r.status).toBe(403)
    expect(await r.json()).toEqual({
      error: 'review_locked',
      message: 'Locked.',
    })
    const generic = reviewErrorResponse(new ReviewApiError('Nope', 502))
    expect(await generic.json()).toEqual({
      error: 'wp_error',
      message: 'Nope',
    })
  })

  it('rethrows unexpected errors', () => {
    expect(() => reviewErrorResponse(new Error('boom'))).toThrow('boom')
  })

  it('passes attachment bytes and only the safe headers through', async () => {
    const wp = new Response('bytes', {
      status: 200,
      headers: {
        'Content-Type': 'image/png',
        'Content-Disposition': 'inline',
        'X-Content-Type-Options': 'nosniff',
        'Content-Security-Policy': "default-src 'none'; sandbox",
        'Cache-Control': 'private, no-store',
        'Content-Length': '5',
        'Set-Cookie': 'wp=1',
      },
    })

    const out = attachmentResponse(wp)

    expect(await out.text()).toBe('bytes')
    expect(out.headers.get('content-type')).toBe('image/png')
    expect(out.headers.get('x-content-type-options')).toBe('nosniff')
    expect(out.headers.get('content-security-policy')).toBe(
      "default-src 'none'; sandbox"
    )
    expect(out.headers.get('set-cookie')).toBeNull()
    // undici may decompress the upstream body; a forwarded length would lie.
    expect(out.headers.get('content-length')).toBeNull()
  })
})

describe('readUploadFile', () => {
  const multipart = (form: FormData) =>
    new Request('http://x/api', { method: 'POST', body: form })

  it('returns the uploaded file', async () => {
    const form = new FormData()
    form.append('file', new File(['# hi'], 'notes.md'))

    const out = await readUploadFile(multipart(form))

    expect(out).toBeInstanceOf(File)
    expect((out as File).name).toBe('notes.md')
  })

  it('answers a malformed multipart body with 400 invalid_body', async () => {
    const request = new Request('http://x/api', {
      method: 'POST',
      headers: { 'content-type': 'multipart/form-data; boundary=zzz' },
      body: '--zzz\r\ntruncated',
    })

    const out = (await readUploadFile(request)) as Response

    expect(out.status).toBe(400)
    expect(await out.json()).toEqual({ error: 'invalid_body' })
  })

  it('rejects a missing or empty file', async () => {
    const empty = new FormData()
    empty.append('file', new File([], 'notes.md'))

    for (const form of [new FormData(), empty]) {
      const out = (await readUploadFile(multipart(form))) as Response
      expect(await out.json()).toEqual({ error: 'empty_file' })
    }
  })

  it('applies the Markdown limit to .md files and the image limit to images', async () => {
    const big = (name: string, bytes: number) => {
      const form = new FormData()
      form.append('file', new File([new Uint8Array(bytes)], name))
      return multipart(form)
    }

    const md = (await readUploadFile(big('notes.md', 600 * 1024))) as Response
    expect(md.status).toBe(413)
    expect(await readUploadFile(big('diagram.png', 600 * 1024))).toBeInstanceOf(
      File
    )
  })

  it('rejects unsupported extensions', async () => {
    const form = new FormData()
    form.append('file', new File(['x'], 'run.exe'))

    const out = (await readUploadFile(multipart(form))) as Response

    expect(out.status).toBe(400)
    expect(await out.json()).toEqual({ error: 'unsupported_type' })
  })
})

describe('readLimitedJsonObject', () => {
  const streamed = (chunks: string[], headers: Record<string, string> = {}) =>
    new Request('http://x/', {
      method: 'POST',
      headers,
      body: new ReadableStream({
        start(controller) {
          for (const c of chunks)
            controller.enqueue(new TextEncoder().encode(c))
          controller.close()
        },
      }),
      // @ts-expect-error -- required by undici for a streamed body
      duplex: 'half',
    })

  it('parses an object within the limit', async () => {
    expect(await readLimitedJsonObject(streamed(['{"a":', '1}']), 100)).toEqual(
      {
        ok: true,
        value: { a: 1 },
      }
    )
  })

  it('stops reading once the streamed bytes pass the limit', async () => {
    const big = ['{"body":"', 'x'.repeat(60), 'x'.repeat(60), '"}']
    expect(await readLimitedJsonObject(streamed(big), 100)).toEqual({
      ok: false,
      error: 'body_too_long',
    })
  })

  it('refuses a declared Content-Length over the limit without reading', async () => {
    const request = streamed(['{}'], { 'content-length': '5000' })
    expect(await readLimitedJsonObject(request, 100)).toEqual({
      ok: false,
      error: 'body_too_long',
    })
  })

  it('counts bytes, not characters', async () => {
    // 30 three-byte characters = 90 bytes of content.
    const wide = ['{"b":"', '€'.repeat(30), '"}']
    expect((await readLimitedJsonObject(streamed(wide), 60)).ok).toBe(false)
  })

  it('rejects invalid JSON and non-objects as invalid_body', async () => {
    for (const body of ['{oops', '[1]', 'null']) {
      expect(await readLimitedJsonObject(streamed([body]), 100)).toEqual({
        ok: false,
        error: 'invalid_body',
      })
    }
  })
})
