'use client'

import { useEffect, useRef } from 'react'
import { useTranslations } from 'next-intl'

declare global {
  interface Window {
    disqus_config?: () => void
  }
}

interface DisqusCommentsProps {
  slug: string
  title: string
}

const SHORTNAME = process.env.NEXT_PUBLIC_DISQUS_SHORTNAME

export default function DisqusComments({ slug, title }: DisqusCommentsProps) {
  const t = useTranslations('blog')
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!SHORTNAME) return

    window.disqus_config = function (this: { page: { identifier: string; title: string } }) {
      this.page.identifier = slug
      this.page.title = title
    }

    const script = document.createElement('script')
    script.src = `https://${SHORTNAME}.disqus.com/embed.js`
    script.setAttribute('data-timestamp', String(+new Date()))
    script.async = true
    containerRef.current?.appendChild(script)

    return () => {
      // Clean up on unmount
      const disqusThread = document.getElementById('disqus_thread')
      if (disqusThread) disqusThread.innerHTML = ''
    }
  }, [slug, title])

  return (
    <div className="mt-12">
      <h3 className="cdcf-heading text-xl">{t('comments')}</h3>
      <div className="cdcf-divider" />
      {SHORTNAME ? (
        <div ref={containerRef}>
          <div id="disqus_thread" />
        </div>
      ) : (
        <p className="text-gray-500">{t('commentsComingSoon')}</p>
      )}
    </div>
  )
}
