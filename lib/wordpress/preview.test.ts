import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('next/headers', () => ({
  draftMode: vi.fn(),
  cookies: vi.fn(),
}))

import { cookies, draftMode } from 'next/headers'
import {
  getPreviewTarget,
  previewMatchesSlug,
  serializePreviewCookie,
  type PreviewTarget,
} from './preview'

const draftModeMock = vi.mocked(draftMode)
const cookiesMock = vi.mocked(cookies)

function setup(isEnabled: boolean, cookieValue?: string) {
  draftModeMock.mockResolvedValue({ isEnabled } as never)
  cookiesMock.mockResolvedValue({
    get: () => (cookieValue === undefined ? undefined : { value: cookieValue }),
  } as never)
}

beforeEach(() => {
  draftModeMock.mockReset()
  cookiesMock.mockReset()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('previewMatchesSlug', () => {
  const target: PreviewTarget = { id: 825, type: 'post', slug: 'hello' }

  it('matches the stored slug', () => {
    expect(previewMatchesSlug(target, 'hello')).toBe(true)
  })

  it('matches the numeric id (slugless new draft)', () => {
    expect(previewMatchesSlug({ ...target, slug: '' }, '825')).toBe(true)
  })

  it('returns false for an unrelated slug', () => {
    expect(previewMatchesSlug(target, 'other')).toBe(false)
  })
})

describe('serializePreviewCookie', () => {
  it('round-trips through JSON', () => {
    const target: PreviewTarget = { id: 1, type: 'page', slug: 'about' }
    expect(JSON.parse(serializePreviewCookie(target))).toEqual(target)
  })
})

describe('getPreviewTarget', () => {
  it('returns null when draft mode is disabled (cookie not even read)', async () => {
    setup(false, serializePreviewCookie({ id: 1, type: 'post', slug: 'x' }))
    expect(await getPreviewTarget()).toBeNull()
  })

  it('returns null when draft mode is on but no cookie', async () => {
    setup(true, undefined)
    expect(await getPreviewTarget()).toBeNull()
  })

  it('parses a valid cookie', async () => {
    setup(true, serializePreviewCookie({ id: 42, type: 'post', slug: 'hi' }))
    expect(await getPreviewTarget()).toEqual({ id: 42, type: 'post', slug: 'hi' })
  })

  it('coerces a numeric-string id', async () => {
    setup(true, JSON.stringify({ id: '42', type: 'post', slug: '' }))
    expect(await getPreviewTarget()).toEqual({ id: 42, type: 'post', slug: '' })
  })

  it('defaults a missing/non-string slug to empty string', async () => {
    setup(true, JSON.stringify({ id: 5, type: 'post' }))
    expect(await getPreviewTarget()).toEqual({ id: 5, type: 'post', slug: '' })
  })

  it('returns null for zero/NaN id', async () => {
    setup(true, JSON.stringify({ id: 0, type: 'post', slug: '' }))
    expect(await getPreviewTarget()).toBeNull()
    setup(true, JSON.stringify({ id: 'abc', type: 'post', slug: '' }))
    expect(await getPreviewTarget()).toBeNull()
  })

  it('returns null when type is not a string', async () => {
    setup(true, JSON.stringify({ id: 5, type: 123, slug: '' }))
    expect(await getPreviewTarget()).toBeNull()
  })

  it('logs and returns null on malformed JSON', async () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {})
    setup(true, '{not json')
    expect(await getPreviewTarget()).toBeNull()
    expect(spy).toHaveBeenCalled()
  })
})
