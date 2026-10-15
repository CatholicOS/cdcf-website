import { describe, it, expect } from 'vitest'
import { secretMatches } from './secret'

describe('secretMatches', () => {
  it('accepts the expected secret', () => {
    expect(secretMatches('s3cret-value', 's3cret-value')).toBe(true)
  })

  it('rejects a different secret, including one of another length', () => {
    expect(secretMatches('s3cret-valuX', 's3cret-value')).toBe(false)
    expect(secretMatches('s3cret', 's3cret-value')).toBe(false)
  })

  it('fails closed when the expected secret is unset or empty', () => {
    expect(secretMatches(undefined, undefined)).toBe(false)
    expect(secretMatches('', '')).toBe(false)
    expect(secretMatches('anything', undefined)).toBe(false)
  })

  it('rejects a missing or non-string given secret', () => {
    expect(secretMatches(null, 's3cret-value')).toBe(false)
    expect(secretMatches(42, 's3cret-value')).toBe(false)
  })
})
