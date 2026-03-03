'use client'

import { useEffect, useState } from 'react'
import { usePathname } from '@/src/i18n/navigation'
import { useTranslations } from 'next-intl'

interface ShareButtonsProps {
  title: string
}

const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL || 'https://catholicdigitalcommons.org'

export default function ShareButtons({ title }: ShareButtonsProps) {
  const t = useTranslations('blog')
  const pathname = usePathname()
  const [canShare, setCanShare] = useState(false)
  const [url, setUrl] = useState('')

  useEffect(() => {
    const fullUrl = `${SITE_URL}${pathname}`
    setUrl(fullUrl)
    setCanShare(typeof navigator.share === 'function')
  }, [pathname])

  const encodedUrl = encodeURIComponent(url)
  const encodedTitle = encodeURIComponent(title)

  async function handleNativeShare() {
    try {
      await navigator.share({ title, url })
    } catch {
      // User cancelled or share failed — no action needed
    }
  }

  return (
    <div className="mt-10">
      <h3 className="text-sm font-semibold uppercase tracking-wider text-gray-500">
        {t('share')}
      </h3>
      <div className="mt-3 flex flex-wrap gap-2">
        {canShare && (
          <button
            onClick={handleNativeShare}
            className="rounded-full border border-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
          >
            {t('shareNative')}
          </button>
        )}
        <a
          href={`https://x.com/intent/tweet?url=${encodedUrl}&text=${encodedTitle}`}
          target="_blank"
          rel="noopener noreferrer"
          className="rounded-full border border-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
        >
          X / Twitter
        </a>
        <a
          href={`https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`}
          target="_blank"
          rel="noopener noreferrer"
          className="rounded-full border border-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
        >
          Facebook
        </a>
        <a
          href={`https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}`}
          target="_blank"
          rel="noopener noreferrer"
          className="rounded-full border border-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
        >
          LinkedIn
        </a>
        <a
          href={`mailto:?subject=${encodedTitle}&body=${encodedUrl}`}
          className="rounded-full border border-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
        >
          Email
        </a>
      </div>
    </div>
  )
}
