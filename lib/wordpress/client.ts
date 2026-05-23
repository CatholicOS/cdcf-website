const GRAPHQL_URL =
  process.env.WP_GRAPHQL_URL || 'http://localhost/graphql'

interface WPQueryOptions {
  revalidate?: number
  draft?: boolean
  token?: string
  tags?: string[]
}

export async function wpQuery<T = unknown>(
  query: string,
  variables: Record<string, unknown> = {},
  options: WPQueryOptions = {}
): Promise<T> {
  const { revalidate = 60, draft = false, token, tags } = options

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  }

  // Draft/preview requests must authenticate so WPGraphQL returns
  // unpublished content. An explicit bearer token wins; otherwise fall back
  // to the WordPress Application Password (Basic auth) from the server-only
  // WP_APP_USERNAME / WP_APP_PASSWORD env vars (these must never be exposed to
  // the client bundle). The theme opts the /graphql endpoint into app-password
  // auth via the `application_password_is_api_request` filter.
  if (draft) {
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    } else if (process.env.WP_APP_USERNAME && process.env.WP_APP_PASSWORD) {
      const creds = Buffer.from(
        `${process.env.WP_APP_USERNAME}:${process.env.WP_APP_PASSWORD}`
      ).toString('base64')
      headers['Authorization'] = `Basic ${creds}`
    } else {
      // No credentials → the request goes out unauthenticated and WPGraphQL
      // returns published-only content, so a draft silently 404s. Surface it.
      console.warn(
        'Draft WPGraphQL request without credentials: set WP_APP_USERNAME/WP_APP_PASSWORD (server-only) or pass a token, or preview will return published-only content.'
      )
    }
  }

  const res = await fetch(GRAPHQL_URL, {
    method: 'POST',
    headers,
    body: JSON.stringify({ query, variables }),
    next: {
      revalidate: draft ? 0 : revalidate,
      ...(tags?.length ? { tags } : {}),
    },
  })

  if (!res.ok) {
    throw new Error(`WPGraphQL request failed: ${res.status} ${res.statusText}`)
  }

  const contentType = res.headers.get('content-type') || ''
  if (!contentType.includes('application/json')) {
    throw new Error(
      `WPGraphQL returned non-JSON response (${contentType}). WordPress may not be installed or WPGraphQL plugin is not active.`
    )
  }

  const json = await res.json()

  if (json.errors) {
    throw new Error(
      `WPGraphQL errors: ${json.errors.map((e: { message: string }) => e.message).join(', ')}`
    )
  }

  return json.data as T
}
