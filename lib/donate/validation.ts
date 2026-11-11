// lib/donate/validation.ts

/** Stripe's technical minimum is 50 cents, but a $1 gift nets ~$0.67 after
 *  the 30c fixed fee. The floor also raises the per-attempt cost of card
 *  testing, which is the main abuse vector on a public donation form. */
export const MIN_DONATION_CENTS = 500

/** The cap keeps every contribution below the $250 IRS written-
 *  acknowledgment threshold. Separate contributions are not aggregated,
 *  so a $200/month recurring gift stays under it too. */
export const MAX_DONATION_CENTS = 20_000

/** The only two intervals a donor can choose. */
export type DonationFrequency = 'once' | 'monthly'

/** The amount, in whole cents, or why it was refused. */
export type AmountValidation =
  | { ok: true; amountCents: number }
  | { ok: false; reason: 'not_an_integer' | 'below_minimum' | 'above_maximum' }

/** The designated project's slug (`null` for the general fund), or a refusal. */
export type ProjectSlugValidation =
  { ok: true; slug: string | null } | { ok: false; reason: 'unknown_project' }

/**
 * Check a donation amount from a request body. Only a JavaScript integer
 * number of cents between the floor and the cap passes; a numeric string is
 * refused, so a hand-written body cannot slip one past the type check.
 */
export function validateAmountCents(value: unknown): AmountValidation {
  if (typeof value !== 'number' || !Number.isInteger(value)) {
    return { ok: false, reason: 'not_an_integer' }
  }
  if (value < MIN_DONATION_CENTS) {
    return { ok: false, reason: 'below_minimum' }
  }
  if (value > MAX_DONATION_CENTS) {
    return { ok: false, reason: 'above_maximum' }
  }
  return { ok: true, amountCents: value }
}

/** Whether `value` is one of the supported donation frequencies. */
export function isDonationFrequency(
  value: unknown
): value is DonationFrequency {
  return value === 'once' || value === 'monthly'
}

/**
 * Check a project designation against the allowlist of projects that accept
 * donations. An absent or empty designation means the general fund.
 */
export function validateProjectSlug(
  value: unknown,
  allowed: readonly string[]
): ProjectSlugValidation {
  if (value === undefined || value === null || value === '') {
    return { ok: true, slug: null }
  }
  if (typeof value !== 'string' || !allowed.includes(value)) {
    return { ok: false, reason: 'unknown_project' }
  }
  return { ok: true, slug: value }
}
