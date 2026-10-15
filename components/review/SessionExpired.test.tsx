import { describe, expect, it, vi } from 'vitest'
import { renderToStaticMarkup } from 'react-dom/server'
import { NextIntlClientProvider } from 'next-intl'
import messages from '@/messages/en.json'

vi.mock('next-auth/react', () => ({ signIn: vi.fn() }))

import { ComposerStatus } from './ComposerStatus'
import { ReviewNotice } from './ReviewNotice'
import { SignInAgain } from './SignInAgain'

const render = (node: React.ReactNode) =>
  renderToStaticMarkup(
    <NextIntlClientProvider locale="en" messages={messages}>
      {node}
    </NextIntlClientProvider>
  )

describe('session-expired messages offer a way to sign in', () => {
  it('ComposerStatus adds a sign-in button to the session-expired error', () => {
    const html = render(
      <ComposerStatus errorKey="sessionExpired" failed={[]} />
    )

    expect(html).toContain('Your session has expired.')
    expect(html).toMatch(/<button[^>]*>Sign in<\/button>/)
  })

  it('ComposerStatus shows no sign-in button for other errors', () => {
    expect(
      render(<ComposerStatus errorKey="forbidden" failed={[]} />)
    ).not.toContain('Sign in')
  })

  it('ReviewNotice renders an optional action', () => {
    const html = render(
      <ReviewNotice title="Review" message="Expired" action={<SignInAgain />} />
    )

    expect(html).toMatch(/<button[^>]*>Sign in<\/button>/)
  })
})
