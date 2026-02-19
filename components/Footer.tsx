'use client'

import { Link } from '@/src/i18n/navigation'
import { useTranslations } from 'next-intl'
import Logo from './Logo'

export default function Footer() {
  const t = useTranslations('footer')

  return (
    <footer className="bg-cdcf-navy text-white">
      <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-5">
          {/* Brand column */}
          <div className="lg:col-span-2">
            <Link href="/" className="flex items-center gap-3">
              <Logo width={44} height={44} />
              <span className="font-serif text-lg font-bold text-white">
                Catholic Digital Commons Foundation
              </span>
            </Link>
            <p className="mt-4 max-w-sm text-sm leading-relaxed text-gray-300">
              {t('about.description')}
            </p>

            {/* Social icons */}
            <div className="mt-6 flex gap-4">
              <a
                href="https://github.com/CatholicOS"
                target="_blank"
                rel="noopener noreferrer"
                className="text-gray-400 transition-colors hover:text-cdcf-gold"
                aria-label="GitHub"
              >
                <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z" />
                </svg>
              </a>
              <a
                href="#"
                className="text-gray-400 transition-colors hover:text-cdcf-gold"
                aria-label="Discord"
              >
                <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.095 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.095 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" />
                </svg>
              </a>
            </div>
          </div>

          {/* Community column */}
          <div>
            <h3 className="text-sm font-semibold uppercase tracking-wider text-cdcf-gold">
              {t('community.title')}
            </h3>
            <ul className="mt-4 space-y-3">
              <li>
                <a href="https://github.com/CatholicOS" target="_blank" rel="noopener noreferrer" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('community.github')}
                </a>
              </li>
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('community.discord')}
                </a>
              </li>
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('community.forum')}
                </a>
              </li>
              <li>
                <Link href="/community" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('community.contribute')}
                </Link>
              </li>
            </ul>
          </div>

          {/* Resources column */}
          <div>
            <h3 className="text-sm font-semibold uppercase tracking-wider text-cdcf-gold">
              {t('resources.title')}
            </h3>
            <ul className="mt-4 space-y-3">
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('resources.documentation')}
                </a>
              </li>
              <li>
                <Link href="/blog" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('resources.blog')}
                </Link>
              </li>
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('resources.events')}
                </a>
              </li>
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('resources.newsletter')}
                </a>
              </li>
            </ul>
          </div>

          {/* Legal column */}
          <div>
            <h3 className="text-sm font-semibold uppercase tracking-wider text-cdcf-gold">
              {t('legal.title')}
            </h3>
            <ul className="mt-4 space-y-3">
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('legal.privacy')}
                </a>
              </li>
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('legal.terms')}
                </a>
              </li>
              <li>
                <a href="#" className="text-sm text-gray-300 transition-colors hover:text-white">
                  {t('legal.licenses')}
                </a>
              </li>
            </ul>
          </div>
        </div>

        {/* Bottom bar */}
        <div className="mt-12 border-t border-white/10 pt-8">
          <div className="flex flex-col items-center justify-between gap-4 sm:flex-row">
            <p className="text-sm text-gray-400">
              &copy; {new Date().getFullYear()} {t('copyright')}
            </p>
            <p className="text-sm italic text-gray-400">
              {t('tagline')}
            </p>
          </div>
        </div>
      </div>
    </footer>
  )
}
