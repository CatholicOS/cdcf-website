import type { NextConfig } from 'next'
import createNextIntlPlugin from 'next-intl/plugin'

const withNextIntl = createNextIntlPlugin('./src/i18n/request.ts')

const wpHost = process.env.WP_GRAPHQL_URL
  ? new URL(process.env.WP_GRAPHQL_URL).hostname
  : 'localhost'

const nextConfig: NextConfig = {
  output: 'standalone',
  images: {
    remotePatterns: [
      {
        protocol: 'http',
        hostname: wpHost,
      },
      {
        protocol: 'https',
        hostname: wpHost,
      },
      // Author avatars fall back to Gravatar when no team_member photo is set.
      {
        protocol: 'https',
        hostname: '*.gravatar.com',
      },
    ],
  },
  async rewrites() {
    return [
      {
        source: '/sitemap.xml',
        destination: '/api/sitemap',
      },
      {
        source: '/sitemap-:lang.xml',
        destination: '/api/sitemap/:lang',
      },
    ]
  },
}

export default withNextIntl(nextConfig)
