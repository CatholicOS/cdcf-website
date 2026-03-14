import createMiddleware from 'next-intl/middleware'
import { routing } from './src/i18n/routing'
import { auth } from "./lib/auth"

const intlMiddleware = createMiddleware(routing)

export default auth((req) => {
  return intlMiddleware(req)
})

export const config = {
  matcher: [
    '/((?!api/auth|_next|api/github|api/preview|api/revalidate|api/stats|api/submit-project|api/refer-community-project|api/refer-local-group|wp-admin|wp-login|wp-json|graphql|wp-content|favicon.ico|logo.svg|.*\\..*).*)',
  ],
}
