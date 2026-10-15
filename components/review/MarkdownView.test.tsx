import { describe, expect, it } from 'vitest'
import { renderToStaticMarkup } from 'react-dom/server'
import { MarkdownView } from './MarkdownView'

const render = (source: string) =>
  renderToStaticMarkup(<MarkdownView source={source} />)

describe('MarkdownView', () => {
  it('renders GitHub-flavoured Markdown', () => {
    const html = render(
      '# Title\n\n- [x] done\n\n| a | b |\n| - | - |\n| 1 | 2 |'
    )
    expect(html).toContain('<h1>Title</h1>')
    expect(html).toContain('type="checkbox"')
    expect(html).toContain('<table>')
  })

  it('never renders raw HTML', () => {
    const html = render(
      '<script>alert(1)</script>\n\n<b onclick="x()">bold</b> text'
    )
    expect(html).not.toContain('<script')
    expect(html).not.toContain('onclick')
    expect(html).not.toContain('<b>')
  })

  it('opens links in a new tab without referrer or ranking', () => {
    const html = render('[site](https://example.org)')
    expect(html).toContain('href="https://example.org"')
    expect(html).toContain('target="_blank"')
    expect(html).toContain('rel="noopener noreferrer nofollow"')
  })

  it('strips javascript: URLs', () => {
    const html = render('[click](javascript:alert(1))')
    expect(html).not.toContain('javascript:')
  })

  it('turns Markdown images into links instead of loading them', () => {
    const html = render('![pixel](https://tracker.example/p.gif)')
    expect(html).not.toContain('<img')
    expect(html).toContain('href="https://tracker.example/p.gif"')
    expect(html).toContain('>pixel</a>')
  })

  it('wraps output in prose and accepts extra classes', () => {
    const html = renderToStaticMarkup(
      <MarkdownView source="x" className="mt-2" />
    )
    expect(
      html.startsWith('<div class="prose prose-sm max-w-none mt-2">')
    ).toBe(true)
  })
})
