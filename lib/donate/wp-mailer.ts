import 'server-only'

import { createHmac } from 'node:crypto'

/**
 * Both donation emails are sent by WordPress via wp_mail, matching every
 * other transactional email in this repository. Returns false rather
 * than throwing so callers can decide whether the failure should be
 * surfaced to Stripe as a retryable error.
 *
 * WordPress accepts these requests only with an HMAC-SHA256 signature of
 * the raw body under CDCF_DONATION_MAIL_SECRET (X-CDCF-Signature), on top
 * of the Application Password: edit_posts alone is held by every author
 * and contributor, and these endpoints email donors in the Foundation's
 * name. The body is serialized once so the signature covers exactly the
 * bytes sent.
 */
export async function postToWordPress(
  path: string,
  body: unknown
): Promise<boolean> {
  const base = process.env.WP_REST_URL
  const user = process.env.WP_APP_USERNAME
  const pass = process.env.WP_APP_PASSWORD
  const secret = process.env.CDCF_DONATION_MAIL_SECRET

  if (!base || !user || !pass || !secret) {
    console.error('WordPress donation mail credentials are not configured')
    return false
  }

  const payload = JSON.stringify(body)
  const signature = createHmac('sha256', secret).update(payload).digest('hex')

  try {
    const response = await fetch(`${base.replace(/\/$/, '')}/${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Basic ${Buffer.from(`${user}:${pass}`).toString('base64')}`,
        'X-CDCF-Signature': signature,
      },
      body: payload,
      cache: 'no-store',
    })

    if (!response.ok) {
      console.error(`WordPress ${path} returned ${response.status}`)
      return false
    }
    return true
  } catch (error) {
    console.error(`WordPress ${path} request failed:`, error)
    return false
  }
}
