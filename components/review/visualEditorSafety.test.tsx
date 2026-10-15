// @vitest-environment happy-dom
import { describe, it, expect } from 'vitest'
import { canEditVisually, roundTripMarkdown } from './visualEditorSafety'

const EVERYTHING_THE_TOOLBAR_MAKES = [
  '## Heading',
  '',
  'Some **bold**, *italic*, ~~struck~~ and `code` with a [link](https://example.org).',
  '',
  '- one',
  '- two',
  '',
  '1. first',
  '2. second',
  '',
  '> quoted',
  '',
  '```js',
  'const x = 1',
  '```',
].join('\n')

describe('canEditVisually', () => {
  it('accepts empty text', () => {
    expect(canEditVisually('')).toBe(true)
    expect(canEditVisually('  \n')).toBe(true)
  })

  it('accepts everything the visual toolbar can produce', () => {
    expect(canEditVisually(EVERYTHING_THE_TOOLBAR_MAKES)).toBe(true)
  })

  it('ignores syntax differences that render the same', () => {
    expect(canEditVisually('_a_ and __b__\n\n* x\n* y')).toBe(true)
  })

  it('rejects GFM tables', () => {
    expect(canEditVisually('| a | b |\n| - | - |\n| 1 | 2 |')).toBe(false)
  })

  it('rejects task lists', () => {
    expect(canEditVisually('- [ ] todo\n- [x] done')).toBe(false)
  })

  it('rejects footnotes', () => {
    expect(canEditVisually('Claim[^1].\n\n[^1]: Source.')).toBe(false)
  })

  it('rejects images, which the visual editor cannot hold', () => {
    expect(canEditVisually('![diagram](https://example.org/d.png)')).toBe(false)
  })
})

describe('roundTripMarkdown', () => {
  it('returns Markdown that renders like the input', () => {
    const out = roundTripMarkdown('Some **bold** text')
    expect(out).toContain('**bold**')
  })
})
