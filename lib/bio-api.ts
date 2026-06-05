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
}

export type BioPostContent = {
  id: number
  title: string
  content: string
  member_title?: string
  member_linkedin_url?: string
  member_github_url?: string
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
  postId: number
): Promise<BioPostContent> {
  // Hits the core /wp/v2 endpoint (NOT /cdcf/v1) — Polylang exposes the
  // language siblings as independent posts so we can fetch the {lang}
  // version directly. The ?_embed=false keeps the payload tight.
  // Use context=edit so unfiltered content is returned for editing
  // (default 'view' returns the rendered/sanitized HTML which we'd
  // then re-edit, causing drift).
  const response = await fetch(
    `${getWpRestUrl()}/wp/v2/team_member/${postId}?context=edit`,
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
  const obj = (body ?? {}) as {
    id?: unknown
    title?: { rendered?: unknown; raw?: unknown }
    content?: { rendered?: unknown; raw?: unknown }
    acf?: {
      member_title?: unknown
      member_linkedin_url?: unknown
      member_github_url?: unknown
    }
  }
  const titleSrc =
    (typeof obj.title?.raw === 'string' && obj.title.raw) ||
    (typeof obj.title?.rendered === 'string' && obj.title.rendered) ||
    ''
  const contentSrc =
    (typeof obj.content?.raw === 'string' && obj.content.raw) ||
    (typeof obj.content?.rendered === 'string' && obj.content.rendered) ||
    ''
  return {
    id: typeof obj.id === 'number' ? obj.id : 0,
    title: titleSrc,
    content: contentSrc,
    member_title: stringOrUndefined(obj.acf?.member_title),
    member_linkedin_url: stringOrUndefined(obj.acf?.member_linkedin_url),
    member_github_url: stringOrUndefined(obj.acf?.member_github_url),
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
