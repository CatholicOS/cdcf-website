import { describe, expect, it } from 'vitest'
import type {
  ReviewAttachment,
  ReviewEntry,
  ReviewProject,
} from './review-types'
import {
  buildAttachmentIndex,
  checkAttachmentFile,
  formatBytes,
  imageNeighbours,
  parseAttachmentParam,
  parseWpGmt,
  pendingName,
  reviewErrorKey,
  safeHttpUrl,
  tallySegments,
  withAttachmentParam,
} from './review-ui'

const att = (
  id: number,
  kind: ReviewAttachment['kind'] = 'image'
): ReviewAttachment => ({
  id,
  kind,
  mime: kind === 'image' ? 'image/png' : 'text/markdown',
  name: `f${id}`,
  size: 10,
})

const entry = (
  id: number,
  attachments: ReviewAttachment[],
  extra: Partial<ReviewEntry> = {}
): ReviewEntry => ({
  id,
  kind: 'review',
  parent_id: null,
  body_md: '',
  vote: null,
  author: null,
  created_at: '',
  edited_at: null,
  version: 'v',
  attachments,
  ...extra,
})

describe('reviewErrorKey', () => {
  it('prefers specific codes', () => {
    expect(reviewErrorKey(403, 'review_locked')).toBe('locked')
    expect(reviewErrorKey(409, 'stale_entry')).toBe('stale')
    expect(reviewErrorKey(409, 'review_exists')).toBe('reviewExists')
    expect(reviewErrorKey(413, 'file_too_large')).toBe('tooLarge')
    expect(reviewErrorKey(400, 'type_mismatch')).toBe('unsupportedType')
    expect(reviewErrorKey(400, 'unsupported_type')).toBe('unsupportedType')
    expect(reviewErrorKey(400, 'too_many_attachments')).toBe('tooMany')
  })

  it('treats every expired-session signal as sessionExpired', () => {
    expect(reviewErrorKey(401, 'no_token')).toBe('sessionExpired')
    expect(reviewErrorKey(401, 'rest_not_logged_in')).toBe('sessionExpired')
    expect(reviewErrorKey(401, 'unauthorized')).toBe('sessionExpired')
    expect(reviewErrorKey(401)).toBe('sessionExpired')
  })

  it('falls back on status', () => {
    expect(reviewErrorKey(403, 'rest_forbidden')).toBe('forbidden')
    expect(reviewErrorKey(404)).toBe('notFound')
    expect(reviewErrorKey(413)).toBe('tooLarge')
    expect(reviewErrorKey(400, 'empty_body')).toBe('invalidInput')
    expect(reviewErrorKey(500)).toBe('generic')
    expect(reviewErrorKey(0)).toBe('generic')
  })
})

describe('attachment URL param', () => {
  it('sets and clears att while keeping other params', () => {
    expect(withAttachmentParam('stage=x', 4)).toBe('?stage=x&att=4')
    expect(withAttachmentParam('stage=x&att=4', null)).toBe('?stage=x')
    expect(withAttachmentParam('att=4', null)).toBe('')
  })

  it('parses only positive integers', () => {
    expect(parseAttachmentParam('7')).toBe(7)
    expect(parseAttachmentParam(null)).toBeNull()
    expect(parseAttachmentParam('0')).toBeNull()
    expect(parseAttachmentParam('x')).toBeNull()
  })
})

describe('tallySegments', () => {
  it('computes shares including pending voters', () => {
    expect(tallySegments({ cdcf: 2, community: 1, reject: 0 }, 1)).toEqual([
      { key: 'cdcf', count: 2, percent: 50 },
      { key: 'community', count: 1, percent: 25 },
      { key: 'reject', count: 0, percent: 0 },
      { key: 'pending', count: 1, percent: 25 },
    ])
  })

  it('is all zero with nobody to count', () => {
    expect(
      tallySegments({ cdcf: 0, community: 0, reject: 0 }, 0).every(
        (s) => s.percent === 0
      )
    ).toBe(true)
  })
})

describe('formatting', () => {
  it('parses WordPress GMT datetimes as UTC', () => {
    expect(parseWpGmt('2026-10-01 09:00:00').toISOString()).toBe(
      '2026-10-01T09:00:00.000Z'
    )
  })

  it('parses microsecond datetimes from the review tables', () => {
    expect(parseWpGmt('2026-10-15 10:00:00.123456').toISOString()).toBe(
      '2026-10-15T10:00:00.123Z'
    )
  })

  it('formats byte sizes', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(1536)).toBe('1.5 KB')
    expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MB')
  })
})

describe('attachments', () => {
  it('finds previous and next images, skipping Markdown', () => {
    const list = [att(1), att(2, 'markdown'), att(3), att(4)]
    expect(imageNeighbours(list, 3)).toEqual({ prev: 1, next: 4 })
    expect(imageNeighbours(list, 1)).toEqual({ prev: null, next: 3 })
    expect(imageNeighbours(list, 2)).toEqual({ prev: null, next: null })
  })

  it('indexes every attachment on the page with its siblings', () => {
    const reply = entry(5, [att(30)], { kind: 'reply' })
    const project = {
      reviews: [{ ...entry(1, [att(10), att(11)]), replies: [reply] }],
      discussion: [entry(6, [att(40, 'markdown')], { kind: 'discussion' })],
      questions: [{ ...entry(7, [], { kind: 'question' }), answers: [] }],
    } as unknown as ReviewProject

    const index = buildAttachmentIndex(project)

    expect(Object.keys(index).sort()).toEqual(['10', '11', '30', '40'])
    expect(index['11'].siblings.map((a) => a.id)).toEqual([10, 11])
    expect(index['30'].siblings.map((a) => a.id)).toEqual([30])
  })

  it('pre-checks files against the upload limits', () => {
    expect(checkAttachmentFile('notes.md', 1000)).toBeNull()
    expect(checkAttachmentFile('photo.JPEG', 1000)).toBeNull()
    expect(checkAttachmentFile('notes.md', 512 * 1024 + 1)).toBe('tooLarge')
    expect(checkAttachmentFile('photo.png', 5 * 1024 * 1024 + 1)).toBe(
      'tooLarge'
    )
    expect(checkAttachmentFile('logo.svg', 10)).toBe('unsupportedType')
    expect(checkAttachmentFile('README', 10)).toBe('unsupportedType')
  })

  it('names pending attachments', () => {
    expect(
      pendingName({
        key: 'a',
        kind: 'paste',
        title: 'License concerns',
        markdown: '#',
      })
    ).toBe('License concerns.md')
    expect(
      pendingName({ key: 'b', kind: 'paste', title: ' ', markdown: '#' })
    ).toBe('notes.md')
    expect(
      pendingName({ key: 'c', kind: 'file', file: new File(['x'], 'a.png') })
    ).toBe('a.png')
  })
})

describe('safeHttpUrl', () => {
  it('keeps http(s) URLs and rejects every other scheme', () => {
    expect(safeHttpUrl('https://github.com/a/b')).toBe('https://github.com/a/b')
    expect(safeHttpUrl('http://example.org')).toBe('http://example.org/')
    expect(safeHttpUrl('javascript:alert(1)')).toBeNull()
    expect(safeHttpUrl(' JaVaScRiPt:alert(1)')).toBeNull()
    expect(safeHttpUrl('data:text/html,<script>x</script>')).toBeNull()
    expect(safeHttpUrl('not a url')).toBeNull()
    expect(safeHttpUrl('')).toBeNull()
  })
})
