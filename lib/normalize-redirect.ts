// Pure helper extracted from proxy.ts so it can be unit-tested without
// pulling in next/server or next-intl. See proxy.ts for how it is wired
// into the locale-redirect path.

const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '::1'])

/**
 * Split a `Host` header into its hostname and port, handling RFC 3986
 * bracketed IPv6 literals (`[::1]`, `[::1]:3000`). A naive
 * `hostHeader.split(':')` mangles those (`[::1]:3000` → `['[', '', '1]',
 * '3000']`), which would both defeat loopback detection and corrupt the
 * rewritten authority.
 *
 * `hostname` is the bare address for loopback lookup (`::1`);
 * `authorityHost` is the form to assign back to `URL.hostname`, which keeps
 * the brackets for IPv6 (`[::1]`) and matches what `URL.hostname` returns.
 */
function parseHostHeader(hostHeader: string): {
  hostname: string
  authorityHost: string
  port: string
} {
  if (hostHeader.startsWith('[')) {
    const end = hostHeader.indexOf(']')
    if (end !== -1) {
      const hostname = hostHeader.slice(1, end)
      const rest = hostHeader.slice(end + 1)
      return {
        hostname,
        authorityHost: `[${hostname}]`,
        port: rest.startsWith(':') ? rest.slice(1) : '',
      }
    }
  }
  const colon = hostHeader.indexOf(':')
  if (colon === -1) {
    return { hostname: hostHeader, authorityHost: hostHeader, port: '' }
  }
  const hostname = hostHeader.slice(0, colon)
  return { hostname, authorityHost: hostname, port: hostHeader.slice(colon + 1) }
}

/**
 * Correct the authority of a locale-redirect `Location` against the public
 * `Host` header.
 *
 * next-intl builds its 307 target from `request.nextUrl.origin`, which —
 * because Next runs with `trustHostHeader: false` behind Plesk's Passenger
 * reverse proxy — reflects the Next standalone server's own bind address
 * and port (`0.0.0.0:3000`) rather than the public URL. The `Host` header
 * itself is clean (nginx forwards `proxy_set_header Host $host`, no port),
 * so we rebuild the redirect authority from it: replace the hostname and
 * drop the port, since public traffic only ever reaches us on the default
 * port. The sole exception is local-dev loopback (`Host: localhost:3000`),
 * where the port is genuine and must be preserved.
 *
 * IMPORTANT: this uses the `hostname` + `port` setters, not `host`. The
 * WHATWG `URL.host` setter does NOT clear an existing port when the
 * assigned value omits one, so `url.host = 'example.org'` on
 * `https://0.0.0.0:3000/it` would silently keep `:3000` — the exact bug an
 * earlier revision shipped.
 *
 * @returns the corrected Location string, or `null` when no rewrite is
 *   needed (already correct, empty inputs, or a non-absolute Location —
 *   middleware never produces relative Locations, so the last case is just
 *   defence in depth).
 */
export function normalizeRedirectLocation(
  location: string | null | undefined,
  hostHeader: string
): string | null {
  const { hostname, authorityHost, port } = parseHostHeader(hostHeader)
  if (!location || !hostname) return null

  const desiredPort = LOOPBACK_HOSTS.has(hostname) ? port : ''

  try {
    const url = new URL(location)
    // url.hostname keeps IPv6 brackets, so compare against authorityHost.
    if (url.hostname === authorityHost && url.port === desiredPort) return null
    url.hostname = authorityHost
    url.port = desiredPort
    return url.toString()
  } catch {
    return null
  }
}
