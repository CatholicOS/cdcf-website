import createMiddleware from 'next-intl/middleware'
import { routing } from './src/i18n/routing'

export default createMiddleware(routing)

export const config = {
  matcher: [
    '/((?!api|_next|wp-admin|wp-login|wp-json|graphql|wp-content|favicon.ico|logo.svg|.*\\..*).*)',
  ],
}
