import { describe, expect, it } from 'vitest'
import {
  authorDisplayName,
  authorHref,
  deriveAuthorSlug,
  htmlToParagraphs,
  linkedTeamMemberId,
  resolveAuthorProfile,
  textToParagraphs,
  type AuthorSlug,
} from './author-profile'
import type { Nicename, WPAuthor, WPTeamMember } from './wordpress/types'

function author(partial: Partial<WPAuthor> = {}): WPAuthor {
  return {
    name: '',
    nickname: null,
    firstName: null,
    lastName: null,
    slug: 'nicename_x' as Nicename,
    description: null,
    url: null,
    avatar: null,
    authorProfile: null,
    ...partial,
  }
}

function teamMember(partial: Partial<WPTeamMember> = {}): WPTeamMember {
  return {
    title: 'TM',
    content: null,
    featuredImage: null,
    teamMemberFields: {
      memberRole: null,
      memberTitle: null,
      memberLinkedinUrl: null,
      memberGithubUrl: null,
    },
    ...partial,
  }
}

describe('authorDisplayName', () => {
  it('prefers nickname over everything', () => {
    expect(
      authorDisplayName(
        author({ nickname: 'Nick', name: 'Display', firstName: 'F', lastName: 'L' })
      )
    ).toBe('Nick')
  })

  it('falls through whitespace-only nickname to display name', () => {
    expect(authorDisplayName(author({ nickname: '   ', name: 'Display' }))).toBe(
      'Display'
    )
  })

  it('uses first + last when nickname and name are empty', () => {
    expect(
      authorDisplayName(author({ name: '', firstName: 'Jane', lastName: 'Doe' }))
    ).toBe('Jane Doe')
  })

  it('uses only first name when last is missing (no stray space)', () => {
    expect(authorDisplayName(author({ firstName: 'Jane', lastName: null }))).toBe(
      'Jane'
    )
  })

  it('falls back to the nicename when nothing else is set', () => {
    expect(authorDisplayName(author({ slug: 'nicename_x' as Nicename }))).toBe(
      'nicename_x'
    )
  })
})

describe('deriveAuthorSlug', () => {
  it('strips diacritics', () => {
    expect(deriveAuthorSlug(author({ name: 'José Peña' }))).toBe('jose-pena')
  })

  it('drops straight and smart apostrophes', () => {
    expect(deriveAuthorSlug(author({ name: "John R. D'Orazio" }))).toBe(
      'john-r-dorazio'
    )
    expect(deriveAuthorSlug(author({ name: 'D’Orazio' }))).toBe('dorazio')
  })

  it('collapses punctuation/whitespace and trims hyphens', () => {
    expect(deriveAuthorSlug(author({ name: '  Foo --  Bar!! ' }))).toBe('foo-bar')
  })

  it('falls back to the raw nicename only when slugify yields empty', () => {
    // name slugifies to empty → raw nicename used verbatim
    expect(deriveAuthorSlug(author({ name: '!!!', slug: 'admin_9z' as Nicename }))).toBe(
      'admin_9z'
    )
    // whitespace name → display name resolves to the nicename, which then
    // slugifies non-empty (underscore → hyphen), so that wins over the raw fallback
    expect(deriveAuthorSlug(author({ name: '   ', slug: 'admin_9z' as Nicename }))).toBe(
      'admin-9z'
    )
  })
})

describe('authorHref', () => {
  it('builds the author page path', () => {
    expect(authorHref('jane-doe' as AuthorSlug)).toBe('/blog/authors/jane-doe')
  })
})

describe('htmlToParagraphs (team_member HTML)', () => {
  it('returns [] for null', () => {
    expect(htmlToParagraphs(null)).toEqual([])
  })

  it('splits block tags and <br> into paragraphs', () => {
    expect(htmlToParagraphs('<p>One</p><p>Two</p>')).toEqual(['One', 'Two'])
    expect(htmlToParagraphs('Line1<br>Line2')).toEqual(['Line1', 'Line2'])
  })

  it('drops empty paragraphs and collapses whitespace', () => {
    expect(htmlToParagraphs('<p></p><p>  Hi   there </p>')).toEqual(['Hi there'])
  })

  it('decodes named, decimal and hex entities', () => {
    expect(htmlToParagraphs('<p>Tom &amp; Jerry&#39;s&#x2e;</p>')).toEqual([
      "Tom & Jerry's.",
    ])
    expect(htmlToParagraphs('<p>a&nbsp;b</p>')).toEqual(['a b'])
  })

  it('strips arbitrary/script tags to plain text (no raw HTML)', () => {
    expect(htmlToParagraphs('<p>safe<script>alert(1)</script></p>')).toEqual([
      'safealert(1)',
    ])
  })

  it('neutralizes entity-encoded tags by decoding before stripping', () => {
    // &lt;script&gt;…&lt;/script&gt; must not survive as literal "<script>" text
    expect(htmlToParagraphs('&lt;script&gt;alert(1)&lt;/script&gt;')).toEqual([
      'alert(1)',
    ])
  })
})

describe('textToParagraphs (plain Biographical Info)', () => {
  it('returns [] for null/empty', () => {
    expect(textToParagraphs(null)).toEqual([])
    expect(textToParagraphs('')).toEqual([])
  })

  it('preserves literal angle brackets (plain text is never tag-stripped)', () => {
    expect(textToParagraphs('Loves C++ <generics> & math')).toEqual([
      'Loves C++ <generics> & math',
    ])
  })

  it('splits on blank lines and decodes entities', () => {
    expect(textToParagraphs('Para &amp; one\n\nPara two')).toEqual([
      'Para & one',
      'Para two',
    ])
  })
})

describe('resolveAuthorProfile', () => {
  it('takes title/photo/links from the team_member; HTML bio stripped to text', () => {
    const profile = resolveAuthorProfile(
      author({
        name: 'Jane Doe',
        url: 'https://jane.example',
        description: 'Plain <bio> & stuff',
        avatar: { url: 'https://gravatar/x' },
      }),
      teamMember({
        content: '<p>Rich <strong>bio</strong></p>',
        featuredImage: {
          node: { sourceUrl: 'https://wp/photo.jpg', altText: '' },
        },
        teamMemberFields: {
          memberRole: 'Engineer',
          memberTitle: 'AI Specialist',
          memberLinkedinUrl: 'https://linkedin/x',
          memberGithubUrl: 'https://github/x',
        },
      })
    )

    expect(profile.title).toBe('AI Specialist') // title, not role
    expect(profile.slug).toBe('jane-doe')
    expect(profile.image).toEqual({ url: 'https://wp/photo.jpg', alt: 'Jane Doe' })
    expect(profile.bio).toEqual(['Rich bio']) // HTML stripped to plain text
    expect(profile.links).toEqual({
      website: 'https://jane.example',
      linkedin: 'https://linkedin/x',
      github: 'https://github/x',
    })
  })

  it('falls back to gravatar + plain Biographical Info, preserving literal markup', () => {
    const profile = resolveAuthorProfile(
      author({
        name: 'Jane Doe',
        description: 'A & B <c>',
        avatar: { url: 'https://gravatar/x' },
      }),
      null
    )

    expect(profile.title).toBeNull()
    expect(profile.image).toEqual({ url: 'https://gravatar/x', alt: 'Jane Doe' })
    // plain text kept verbatim — the literal "<c>" must NOT be stripped
    expect(profile.bio).toEqual(['A & B <c>'])
    expect(profile.links).toEqual({}) // no url/links → empty
  })

  it('has no image when neither team_member photo nor avatar exist', () => {
    expect(resolveAuthorProfile(author({ name: 'X' }), null).image).toBeNull()
  })
})

describe('linkedTeamMemberId', () => {
  it('returns the first linked team_member id', () => {
    expect(
      linkedTeamMemberId(
        author({ authorProfile: { authorTeamMember: { nodes: [{ databaseId: 7 }] } } })
      )
    ).toBe(7)
  })

  it('returns null when nothing is linked', () => {
    expect(linkedTeamMemberId(author())).toBeNull()
    expect(
      linkedTeamMemberId(author({ authorProfile: { authorTeamMember: { nodes: [] } } }))
    ).toBeNull()
  })
})
