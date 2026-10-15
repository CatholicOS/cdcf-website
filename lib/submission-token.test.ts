import { describe, expect, it } from 'vitest'
import {
  STATUS_TOKEN_PATTERN,
  statusCookieName,
  statusCookieOptions,
  statusPagePath,
} from './submission-token'

describe('submission token helpers', () => {
  it('accepts only 64 lowercase hex characters', () => {
    expect(STATUS_TOKEN_PATTERN.test('a'.repeat(64))).toBe(true)
    expect(STATUS_TOKEN_PATTERN.test('A'.repeat(64))).toBe(false)
    expect(STATUS_TOKEN_PATTERN.test('a'.repeat(63))).toBe(false)
    expect(STATUS_TOKEN_PATTERN.test(`${'a'.repeat(64)}x`)).toBe(false)
  })

  it('names one cookie per project, httpOnly and site-wide', () => {
    expect(statusCookieName(42)).toBe('cdcf_status_42')
    expect(statusCookieOptions(true)).toEqual({
      httpOnly: true,
      secure: true,
      sameSite: 'lax',
      path: '/',
      maxAge: 60 * 60 * 24 * 180,
    })
  })

  it('builds the clean page path without the token, in the submission language', () => {
    expect(statusPagePath('en', 42)).toBe('/submission-status/42')
    expect(statusPagePath('it', 42)).toBe('/it/submission-status/42')
    expect(statusPagePath('xx', 42)).toBe('/submission-status/42')
  })
})
