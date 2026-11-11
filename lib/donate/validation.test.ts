// lib/donate/validation.test.ts
import { describe, expect, it } from 'vitest'

import {
  MAX_DONATION_CENTS,
  MIN_DONATION_CENTS,
  isDonationFrequency,
  validateAmountCents,
  validateProjectSlug,
} from './validation'

describe('validateAmountCents', () => {
  it('accepts an amount at the floor', () => {
    expect(validateAmountCents(500)).toEqual({ ok: true, amountCents: 500 })
  })

  it('accepts an amount at the cap', () => {
    expect(validateAmountCents(20000)).toEqual({
      ok: true,
      amountCents: 20000,
    })
  })

  it('rejects one cent above the cap', () => {
    expect(validateAmountCents(20001)).toEqual({
      ok: false,
      reason: 'above_maximum',
    })
  })

  it('rejects one cent below the floor', () => {
    expect(validateAmountCents(499)).toEqual({
      ok: false,
      reason: 'below_minimum',
    })
  })

  it('rejects a fractional amount', () => {
    expect(validateAmountCents(500.5)).toEqual({
      ok: false,
      reason: 'not_an_integer',
    })
  })

  it('rejects a numeric string, so a hand-written body cannot smuggle one', () => {
    expect(validateAmountCents('5000')).toEqual({
      ok: false,
      reason: 'not_an_integer',
    })
  })

  it('rejects NaN and Infinity', () => {
    expect(validateAmountCents(Number.NaN).ok).toBe(false)
    expect(validateAmountCents(Number.POSITIVE_INFINITY).ok).toBe(false)
  })

  it('rejects a negative amount', () => {
    expect(validateAmountCents(-500)).toEqual({
      ok: false,
      reason: 'below_minimum',
    })
  })

  it('exports the cap and floor the spec fixes', () => {
    expect(MIN_DONATION_CENTS).toBe(500)
    expect(MAX_DONATION_CENTS).toBe(20000)
  })
})

describe('isDonationFrequency', () => {
  it('accepts the two supported frequencies', () => {
    expect(isDonationFrequency('once')).toBe(true)
    expect(isDonationFrequency('monthly')).toBe(true)
  })

  it('rejects any other interval', () => {
    expect(isDonationFrequency('weekly')).toBe(false)
    expect(isDonationFrequency('yearly')).toBe(false)
    expect(isDonationFrequency(undefined)).toBe(false)
  })
})

describe('validateProjectSlug', () => {
  const allowed = ['liturgical-calendar', 'bibleget'] as const

  it('treats an absent designation as the general fund', () => {
    expect(validateProjectSlug(undefined, allowed)).toEqual({
      ok: true,
      slug: null,
    })
    expect(validateProjectSlug('', allowed)).toEqual({ ok: true, slug: null })
    expect(validateProjectSlug(null, allowed)).toEqual({
      ok: true,
      slug: null,
    })
  })

  it('accepts a slug on the allowlist', () => {
    expect(validateProjectSlug('bibleget', allowed)).toEqual({
      ok: true,
      slug: 'bibleget',
    })
  })

  it('rejects a slug that is not on the allowlist', () => {
    expect(validateProjectSlug('not-a-project', allowed)).toEqual({
      ok: false,
      reason: 'unknown_project',
    })
  })

  it('rejects a non-string designation', () => {
    expect(validateProjectSlug(42, allowed)).toEqual({
      ok: false,
      reason: 'unknown_project',
    })
  })
})
