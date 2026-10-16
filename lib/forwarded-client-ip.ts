import 'server-only'
import { createHmac } from 'node:crypto'

/**
 * Headers that tell WordPress which visitor a public request is for. Every
 * such request reaches WordPress from this server, so without them its
 * per-IP limits and blocklist check would all see our own address.
 * WordPress trusts the address only when the signature (HMAC-SHA256 under
 * CDCF_CLIENT_IP_SECRET) holds; see includes/client-ip.php. Not the preview
 * secret: that one reaches browsers in preview URLs.
 */
export function forwardedClientIpHeaders(ip: string): Record<string, string> {
  const secret = process.env.CDCF_CLIENT_IP_SECRET
  if (!secret || !ip || ip === 'unknown') return {}
  return {
    'X-CDCF-Client-IP': ip,
    'X-CDCF-Client-IP-Signature': createHmac('sha256', secret)
      .update(ip)
      .digest('hex'),
  }
}
