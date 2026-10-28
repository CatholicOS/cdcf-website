import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

// After a navigation, Next.js 16.4+ checks whether the new page's top is
// inside the viewport below `<html>`'s scroll-padding-top; if not, it calls
// scrollIntoView on every section of the page, and the page could settle
// part way down (the hero hidden, the stats bar showing). Two things set
// that off and must not come back.

const read = (path: string) => readFileSync(join(process.cwd(), path), 'utf8')

describe('page scroll setup', () => {
  it('does not pad <html> deeper than the sticky header', () => {
    const htmlRule = read('css/globals.css').match(/(^|\n)html\s*\{[^}]*\}/)
    expect(htmlRule?.[0]).not.toMatch(/scroll-padding-top/)
  })

  it('offsets anchor targets for the header with scroll-margin instead', () => {
    expect(read('css/globals.css')).toMatch(
      /@layer base\s*\{[^}]*\[id\]\s*\{\s*scroll-margin-top:/
    )
  })

  it('lets Next.js switch smooth scrolling off during navigation', () => {
    expect(read('app/[lang]/layout.tsx')).toMatch(
      /<html[^>]*data-scroll-behavior="smooth"/
    )
  })
})
