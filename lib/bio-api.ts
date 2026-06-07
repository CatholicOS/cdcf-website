// Server-only helpers for the bio self-edit flow.
//
// All three functions attach the caller's Zitadel access token from the
// Auth.js session as a Bearer header. The WP-side bearer validator
// (wordpress/themes/cdcf-headless/includes/auth/zitadel-bearer.php) then
// resolves the email claim to a WP user before the cdcf/v1 handler runs.
// These helpers are how the access token reaches WP without ever passing
// through the browser.

import 'server-only'
import type { Session } from 'next-auth'
import { getAccessToken } from '@/lib/auth-utils'

export type BioLanguage = {
  slug: string
  post_id: number
  title: string
  status: string
}

export type BioDiscovery = {
  team_member_id: number
  available_languages: BioLanguage[]
  /**
   * True when the caller's team_member is on the Board of Directors
   * (English About page's `team_members` ACF relationship field).
   * Board titles reflect formal council positions and the
   * member_title (Position / Affiliation) field is server-side
   * read-only for them — the bio editor disables that input.
   */
  is_board_member?: boolean
}

export type BioPostContent = {
  id: number
  title: string
  content: string
  member_title?: string
  member_linkedin_url?: string
  member_github_url?: string
  /**
   * Surfaced here too (in addition to BioDiscovery) so a hot
   * language switch in the bio editor doesn't need a second
   * round-trip to decide read-only state for member_title.
   */
  is_board_member?: boolean
}

export type BioSaveResponse = {
  post_id: number
  queued: string[]
  errors: string[]
}

export class BioApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string
  ) {
    super(message)
    this.name = 'BioApiError'
  }
}

function getWpRestUrl(): string {
  // Fall back to WP_GRAPHQL_URL → /wp-json so deploys that only configure
  // the GraphQL endpoint (the historical default — WP_REST_URL was added
  // later for the Python CLI) still work for these helpers. Both vars
  // resolve to the same WordPress origin in every production-shaped
  // deploy.
  const url =
    process.env.WP_REST_URL ??
    process.env.WP_GRAPHQL_URL?.replace(/\/graphql\/?$/, '/wp-json')
  if (!url) {
    throw new BioApiError(
      'Neither WP_REST_URL nor WP_GRAPHQL_URL is configured',
      500,
      'config_missing'
    )
  }
  return url.replace(/\/$/, '')
}

function bearerHeader(session: Session | null | undefined): Record<string, string> {
  const token = getAccessToken(session)
  if (!token) {
    throw new BioApiError('No access token on session', 401, 'no_token')
  }
  return { Authorization: `Bearer ${token}` }
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return await response.json()
  } catch {
    return null
  }
}

function toBioApiError(body: unknown, fallback: string, status: number): BioApiError {
  if (body && typeof body === 'object') {
    const obj = body as { message?: unknown; code?: unknown }
    const message = typeof obj.message === 'string' ? obj.message : fallback
    const code = typeof obj.code === 'string' ? obj.code : undefined
    return new BioApiError(message, status, code)
  }
  return new BioApiError(fallback, status)
}

export async function fetchMyTeamMember(session: Session | null): Promise<BioDiscovery> {
  const response = await fetch(`${getWpRestUrl()}/cdcf/v1/my-team-member`, {
    headers: bearerHeader(session),
    cache: 'no-store',
  })
  const body = await readJson(response)
  if (!response.ok) {
    throw toBioApiError(body, 'Discovery request failed', response.status)
  }
  return body as BioDiscovery
}

export async function fetchTeamMemberPost(
  session: Session | null,
  lang: string
): Promise<BioPostContent> {
  // Hits the custom /cdcf/v1/my-team-member/{lang} endpoint, NOT core
  // /wp/v2/team_member/{id}?context=edit. The core REST GET requires
  // `edit_post` capability on the specific post — Phase 5
  // auto-provisioned Subscribers who own a bio via `author_team_member`
  // have no `edit_post` cap and would hit `rest_forbidden_context`,
  // even though the Phase 5 design treats the link as the ownership
  // signal (not a capability). The custom endpoint applies the same
  // Polylang-group ownership check the PATCH counterpart uses, so a
  // linked Subscriber correctly succeeds.
  const response = await fetch(
    `${getWpRestUrl()}/cdcf/v1/my-team-member/${lang}`,
    {
      headers: bearerHeader(session),
      cache: 'no-store',
    }
  )
  const body = await readJson(response)
  if (!response.ok) {
    throw toBioApiError(body, 'Failed to load post', response.status)
  }
  return normaliseTeamMemberPost(body)
}

function normaliseTeamMemberPost(body: unknown): BioPostContent {
  // The custom /cdcf/v1/my-team-member/{lang} endpoint returns a flat
  // shape — id/title/content as plain strings, ACF fields as flat keys
  // — rather than core REST's nested `title.raw|rendered` /
  // `content.raw|rendered` / `acf.*` shape. Runtime type guards stay
  // because the response could still be malformed (network
  // intermediary, etc.).
  const obj = (body ?? {}) as {
    id?: unknown
    title?: unknown
    content?: unknown
    member_title?: unknown
    member_linkedin_url?: unknown
    member_github_url?: unknown
    is_board_member?: unknown
  }
  return {
    id: typeof obj.id === 'number' ? obj.id : 0,
    title: typeof obj.title === 'string' ? obj.title : '',
    content: typeof obj.content === 'string' ? obj.content : '',
    member_title: stringOrUndefined(obj.member_title),
    member_linkedin_url: stringOrUndefined(obj.member_linkedin_url),
    member_github_url: stringOrUndefined(obj.member_github_url),
    is_board_member:
      typeof obj.is_board_member === 'boolean' ? obj.is_board_member : false,
  }
}

function stringOrUndefined(v: unknown): string | undefined {
  return typeof v === 'string' ? v : undefined
}

export type BioSavePayload = {
  content?: string
  member_title?: string
  member_linkedin_url?: string
  member_github_url?: string
}

export async function saveMyTeamMember(
  session: Session | null,
  lang: string,
  payload: BioSavePayload
): Promise<BioSaveResponse> {
  if (!/^[a-z]{2}$/.test(lang)) {
    throw new BioApiError('Invalid language slug', 400, 'invalid_lang')
  }
  const response = await fetch(
    `${getWpRestUrl()}/cdcf/v1/my-team-member/${lang}`,
    {
      method: 'PATCH',
      headers: {
        ...bearerHeader(session),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
      cache: 'no-store',
    }
  )
  const body = await readJson(response)
  if (!response.ok) {
    throw toBioApiError(body, 'Save failed', response.status)
  }
  return body as BioSaveResponse
}
