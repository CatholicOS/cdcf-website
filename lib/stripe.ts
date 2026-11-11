// lib/stripe.ts
import 'server-only'

import Stripe from 'stripe'

let client: Stripe | null = null

/**
 * Lazily constructed so importing this module in a context without the
 * secret (a build step, a test) does not throw at import time.
 */
export function getStripe(): Stripe {
  if (client) return client

  const secret = process.env.STRIPE_SECRET_KEY
  if (!secret) {
    throw new Error('STRIPE_SECRET_KEY is not set')
  }

  client = new Stripe(secret)
  return client
}
