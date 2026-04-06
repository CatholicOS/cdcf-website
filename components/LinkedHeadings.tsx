'use client'

import { useEffect, useRef } from 'react'

/**
 * Wraps a prose HTML block and converts headings that have `id` attributes
 * into self-linking anchors with a § glyph that appears on hover.
 *
 * The `html` prop contains trusted CMS content from WordPress (never user input).
 */
export default function LinkedHeadings({
  html,
  className,
}: {
  html: string
  className?: string
}) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!ref.current) return
    const headings = ref.current.querySelectorAll<HTMLElement>(
      'h1[id], h2[id], h3[id], h4[id], h5[id], h6[id]'
    )
    for (const heading of headings) {
      if (heading.querySelector('.heading-anchor')) continue

      const anchor = document.createElement('a')
      anchor.href = `#${heading.id}`
      anchor.className = 'heading-anchor'
      anchor.setAttribute('aria-hidden', 'true')
      anchor.textContent = '§'
      heading.style.position = 'relative'
      heading.prepend(anchor)
    }
  }, [html])

  return (
    <div
      ref={ref}
      className={className}
      // nosemgrep: react-dangerouslysetinnerhtml -- trusted CMS content from WordPress
      dangerouslySetInnerHTML={{ __html: html }}
    />
  )
}
