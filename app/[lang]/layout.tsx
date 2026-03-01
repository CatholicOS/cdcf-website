import type { Metadata, Viewport } from 'next'
import { NextIntlClientProvider } from 'next-intl'
import { getMessages, setRequestLocale } from 'next-intl/server'
import { notFound } from 'next/navigation'
import { Inter, Merriweather, Playfair_Display } from 'next/font/google'
import { locales } from '@/src/i18n/routing'
import Header from '@/components/Header'
import Footer from '@/components/Footer'
import '@/css/globals.css'

const inter = Inter({
  subsets: ['latin'],
  variable: '--font-inter',
  display: 'swap',
})

const merriweather = Merriweather({
  subsets: ['latin'],
  weight: ['400', '700'],
  variable: '--font-merriweather',
  display: 'swap',
})

const playfairDisplay = Playfair_Display({
  subsets: ['latin'],
  variable: '--font-playfair-display',
  display: 'swap',
  preload: false,
})

export function generateStaticParams() {
  return locales.map((lang) => ({ lang }))
}

const siteDescription =
  'Nurturing open-source projects that serve the Catholic community worldwide.'

export const metadata: Metadata = {
  metadataBase: new URL(
    process.env.NEXT_PUBLIC_SITE_URL ?? 'https://staging.catholicdigitalcommons.org'
  ),
  title: {
    default: 'Catholic Digital Commons Foundation',
    template: '%s | CDCF',
  },
  description: siteDescription,
  icons: {
    icon: [
      { url: '/favicon.ico', sizes: '32x32' },
      { url: '/icon.svg', type: 'image/svg+xml' },
    ],
    apple: [{ url: '/apple-icon.png', sizes: '180x180' }],
  },
  openGraph: {
    type: 'website',
    siteName: 'Catholic Digital Commons Foundation',
    title: 'Catholic Digital Commons Foundation',
    description: siteDescription,
    images: [{ url: '/og-image.png', width: 1200, height: 630 }],
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Catholic Digital Commons Foundation',
    description: siteDescription,
    images: ['/og-image.png'],
  },
  manifest: '/site.webmanifest',
  other: {
    'msapplication-TileColor': '#213463',
  },
}

export const viewport: Viewport = {
  themeColor: '#213463',
}

export default async function LangLayout({
  children,
  params,
}: {
  children: React.ReactNode
  params: Promise<{ lang: string }>
}) {
  const { lang } = await params

  if (!locales.includes(lang as any)) {
    notFound()
  }

  setRequestLocale(lang)
  const messages = await getMessages()

  return (
    <html lang={lang} className={`${inter.variable} ${merriweather.variable} ${playfairDisplay.variable}`} suppressHydrationWarning>
      <body className="flex min-h-screen flex-col bg-white font-sans text-gray-900 antialiased">
        <NextIntlClientProvider messages={messages}>
          <Header />
          <main className="flex-1">{children}</main>
          <Footer />
        </NextIntlClientProvider>
      </body>
    </html>
  )
}
