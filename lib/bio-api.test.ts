import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { Session } from 'next-auth'

// Mock getAccessToken so we can simulate a session with/without a
// usable bearer (the helper checks for an undefined return from this
// function to throw BioApiError(401)). The real auth-utils transitively
// imports next/navigation which fails outside Next; the mock skips that.
vi.mock('@/lib/auth-utils', () => ({
  getAccessToken: vi.fn(),
}))

import { getAccessToken } from '@/lib/auth-utils'
import {
  BioApiError,
  fetchMyTeamMember,
  fetchTeamMemberPost,
  saveMyTeamMember,
} from './bio-api'

const mockedGetAccessToken = vi.mocked(getAccessToken)

const session: Session = {
  accessToken: 'token-abc',
  expires: '2099-01-01T00:00:00.000Z',
}

let fetchMock: ReturnType<typeof vi.fn>

beforeEach(() => {
  vi.resetAllMocks()
  process.env.WP_REST_URL = 'https://wp.example.org/wp-json'
  // Clear so tests of the fallback branch start from a clean slate.
  delete process.env.WP_GRAPHQL_URL
  mockedGetAccessToken.mockReturnValue('token-abc')
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

// ─── fetchMyTeamMember ───────────────────────────────────────────────

describe('fetchMyTeamMember', () => {
  it('attaches the bearer token and returns the parsed discovery payload', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, {
        team_member_id: 702,
        available_languages: [
          { slug: 'en', post_id: 702, title: 'Me', status: 'publish' },
        ],
      })
    )

    const result = await fetchMyTeamMember(session)

    expect(result.team_member_id).toBe(702)
    expect(fetchMock).toHaveBeenCalledOnce()
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('https://wp.example.org/wp-json/cdcf/v1/my-team-member')
    expect((init as RequestInit).headers).toMatchObject({
      Authorization: 'Bearer token-abc',
    })
  })

  it('throws BioApiError(401) when the session has no access token', async () => {
    mockedGetAccessToken.mockReturnValue(undefined)

    await expect(fetchMyTeamMember(session)).rejects.toMatchObject({
      name: 'BioApiError',
      status: 401,
    })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('propagates a 403 from WP as a BioApiError', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(403, {
        code: 'rest_no_team_member_link',
        message: 'Not linked',
      })
    )

    const err = await fetchMyTeamMember(session).catch((e) => e)
    expect(err).toBeInstanceOf(BioApiError)
    expect(err.status).toBe(403)
    expect(err.code).toBe('rest_no_team_member_link')
  })

  it('throws when both WP_REST_URL and WP_GRAPHQL_URL are unset', async () => {
    delete process.env.WP_REST_URL
    delete process.env.WP_GRAPHQL_URL

    const err = await fetchMyTeamMember(session).catch((e) => e)
    expect(err).toBeInstanceOf(BioApiError)
    expect(err.status).toBe(500)
    expect(err.code).toBe('config_missing')
  })

  it('derives the REST URL from WP_GRAPHQL_URL when WP_REST_URL is unset', async () => {
    delete process.env.WP_REST_URL
    process.env.WP_GRAPHQL_URL = 'https://cms.example.org/graphql'
    fetchMock.mockResolvedValue(
      jsonResponse(200, { team_member_id: 1, available_languages: [] })
    )

    await fetchMyTeamMember(session)

    const [url] = fetchMock.mock.calls[0]
    expect(url).toBe('https://cms.example.org/wp-json/cdcf/v1/my-team-member')
  })
})

// ─── fetchTeamMemberPost ─────────────────────────────────────────────

describe('fetchTeamMemberPost', () => {
  it('hits /cdcf/v1/my-team-member/{lang} and normalises the flat response', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, {
        id: 703,
        title: 'Mein Name',
        content: '<p>Hallo.</p>',
        member_title: 'Theologe',
        member_linkedin_url: 'https://linkedin.com/in/me',
        member_github_url: '',
      })
    )

    const post = await fetchTeamMemberPost(session, 'de')

    expect(post).toEqual({
      id: 703,
      title: 'Mein Name',
      content: '<p>Hallo.</p>',
      member_title: 'Theologe',
      member_linkedin_url: 'https://linkedin.com/in/me',
      member_github_url: '',
    })
    const [url] = fetchMock.mock.calls[0]
    expect(url).toBe(
      'https://wp.example.org/wp-json/cdcf/v1/my-team-member/de'
    )
  })

  it('returns empty strings + undefined optional fields when keys are absent', async () => {
    // Missing keys (no title, no content, ACF unset) shouldn't throw —
    // string fields collapse to '' and optional ones to undefined so
    // the editor renders a blank-but-functional form.
    fetchMock.mockResolvedValue(jsonResponse(200, { id: 999 }))

    const post = await fetchTeamMemberPost(session, 'en')

    expect(post.id).toBe(999)
    expect(post.title).toBe('')
    expect(post.content).toBe('')
    expect(post.member_title).toBeUndefined()
    expect(post.member_linkedin_url).toBeUndefined()
    expect(post.member_github_url).toBeUndefined()
  })

  it('propagates non-200 errors as BioApiError', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(404, { code: 'rest_no_translation_for_lang' })
    )

    const err = await fetchTeamMemberPost(session, 'pt').catch((e) => e)
    expect(err).toBeInstanceOf(BioApiError)
    expect(err.status).toBe(404)
    expect(err.code).toBe('rest_no_translation_for_lang')
  })
})

// ─── saveMyTeamMember ────────────────────────────────────────────────

describe('saveMyTeamMember', () => {
  it('PATCHes /cdcf/v1/my-team-member/{lang} with the payload', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, {
        post_id: 703,
        queued: ['en', 'it', 'es', 'fr', 'pt'],
        errors: [],
      })
    )

    const result = await saveMyTeamMember(session, 'de', {
      content: '<p>Bio.</p>',
      member_title: 'Theologe',
    })

    expect(result.queued).toEqual(['en', 'it', 'es', 'fr', 'pt'])
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('https://wp.example.org/wp-json/cdcf/v1/my-team-member/de')
    const reqInit = init as RequestInit
    expect(reqInit.method).toBe('PATCH')
    expect(reqInit.headers).toMatchObject({
      Authorization: 'Bearer token-abc',
      'Content-Type': 'application/json',
    })
    expect(JSON.parse(reqInit.body as string)).toEqual({
      content: '<p>Bio.</p>',
      member_title: 'Theologe',
    })
  })

  it('rejects malformed lang slugs before touching the network', async () => {
    const err = await saveMyTeamMember(session, 'deu', {}).catch((e) => e)
    expect(err).toBeInstanceOf(BioApiError)
    expect(err.status).toBe(400)
    expect(err.code).toBe('invalid_lang')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('surfaces the WP-side rest_invalid_url 400', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(400, {
        code: 'rest_invalid_url',
        message: 'LinkedIn URL must point at linkedin.com (or empty to clear).',
      })
    )

    const err = await saveMyTeamMember(session, 'en', {
      member_linkedin_url: 'https://evil.example.org/in/me',
    }).catch((e) => e)
    expect(err).toBeInstanceOf(BioApiError)
    expect(err.status).toBe(400)
    expect(err.code).toBe('rest_invalid_url')
    expect(err.message).toMatch(/LinkedIn/)
  })

  it('surfaces a 403 when the ownership invariant fails', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(403, {
        code: 'rest_forbidden',
        message: 'You do not own this team_member.',
      })
    )

    const err = await saveMyTeamMember(session, 'en', { content: '<p>x</p>' })
      .catch((e) => e)
    expect(err.status).toBe(403)
    expect(err.code).toBe('rest_forbidden')
  })
})
