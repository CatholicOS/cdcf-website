const GRAPHQL_URL =
  process.env.WORDPRESS_GRAPHQL_URL || 'http://localhost/graphql'

interface WPQueryOptions {
  revalidate?: number
  draft?: boolean
  token?: string
}

export async function wpQuery<T = unknown>(
  query: string,
  variables: Record<string, unknown> = {},
  options: WPQueryOptions = {}
): Promise<T> {
  const { revalidate = 60, draft = false, token } = options

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  }

  if (draft && token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  const res = await fetch(GRAPHQL_URL, {
    method: 'POST',
    headers,
    body: JSON.stringify({ query, variables }),
    next: { revalidate: draft ? 0 : revalidate },
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
