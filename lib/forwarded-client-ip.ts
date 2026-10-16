import 'server-only'
import { createHmac } from 'node:crypto'

/**
 * Headers that tell WordPress which visitor a public request is for. Every
 * such request reaches WordPress from this server, so without them its
 * per-IP limits and blocklist check would all see our own address.
 * WordPress trusts the address only when the signature (HMAC-SHA256 under
 * the shared preview secret) holds; see includes/client-ip.php.
 */
export function forwardedClientIpHeaders(ip: string): Record<string, string> {
  const secret = process.env.WP_PREVIEW_SECRET
  if (!secret || !ip || ip === 'unknown') return {}
  return {
    'X-CDCF-Client-IP': ip,
    'X-CDCF-Client-IP-Signature': createHmac('sha256', secret)
      .update(ip)
      .digest('hex'),
  }
}
