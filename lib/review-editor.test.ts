import { describe, it, expect } from 'vitest'
import {
  EDITOR_MODE_KEY,
  isAllowedLinkHref,
  readEditorMode,
  writeEditorMode,
} from './review-editor'

function memoryStorage(initial: Record<string, string> = {}) {
  const data = new Map(Object.entries(initial))
  return {
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
    data,
  }
}

const throwing = {
  getItem: () => {
    throw new Error('blocked')
  },
  setItem: () => {
    throw new Error('blocked')
  },
}

describe('editor mode preference', () => {
  it('defaults to the visual editor', () => {
    expect(readEditorMode(memoryStorage())).toBe('visual')
    expect(readEditorMode(null)).toBe('visual')
  })

  it('reads a stored markdown preference', () => {
    expect(
      readEditorMode(memoryStorage({ [EDITOR_MODE_KEY]: 'markdown' }))
    ).toBe('markdown')
  })

  it('ignores unknown stored values', () => {
    expect(
      readEditorMode(memoryStorage({ [EDITOR_MODE_KEY]: 'wysiwyg' }))
    ).toBe('visual')
  })

  it('falls back to visual when storage throws', () => {
    expect(readEditorMode(throwing)).toBe('visual')
  })

  it('writes the preference, and survives storage that throws', () => {
    const s = memoryStorage()
    writeEditorMode(s, 'markdown')
    expect(s.data.get(EDITOR_MODE_KEY)).toBe('markdown')
    expect(() => writeEditorMode(throwing, 'visual')).not.toThrow()
    expect(() => writeEditorMode(null, 'visual')).not.toThrow()
  })
})

describe('isAllowedLinkHref', () => {
  it('allows http, https and mailto links', () => {
    expect(isAllowedLinkHref('https://example.org/a?b=c')).toBe(true)
    expect(isAllowedLinkHref('http://example.org')).toBe(true)
    expect(isAllowedLinkHref('mailto:someone@example.org')).toBe(true)
    expect(isAllowedLinkHref('  HTTPS://Example.org ')).toBe(true)
  })

  it('rejects other schemes, relative and malformed links', () => {
    expect(isAllowedLinkHref('javascript:alert(1)')).toBe(false)
    expect(isAllowedLinkHref('data:text/html,hi')).toBe(false)
    expect(isAllowedLinkHref('/review/1')).toBe(false)
    expect(isAllowedLinkHref('example.org')).toBe(false)
    expect(isAllowedLinkHref('')).toBe(false)
  })
})
