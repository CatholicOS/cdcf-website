import type { WPAuthor, WPTeamMember } from './wordpress/types'

/**
 * Normalized author profile for rendering. Profile detail (photo, role, bio,
 * social links) is sourced from a linked team_member when present so it can be
 * translated; otherwise it falls back to core WordPress user fields.
 */
export interface AuthorProfile {
  name: string
  slug: string
  role: string | null
  /** Bio markup. From team_member it is rich HTML; from the core user field it
   *  is the plain Biographical Info wrapped in a paragraph. */
  bioHtml: string | null
  image: { url: string; alt: string } | null
  links: {
    website?: string
    linkedin?: string
    github?: string
  }
}

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function decodeEntities(text: string): string {
  const named: Record<string, string> = {
    '&amp;': '&',
    '&lt;': '<',
    '&gt;': '>',
    '&quot;': '"',
    '&#39;': "'",
    '&apos;': "'",
    '&nbsp;': ' ',
  }
  return text
    .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16)))
    .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(parseInt(d, 10)))
    .replace(/&[a-z]+;/gi, (m) => named[m.toLowerCase()] ?? m)
}

/**
 * Bio markup split into plain-text paragraphs. Block boundaries become
 * paragraph breaks and remaining tags are stripped, so bios render without
 * raw-HTML injection. Inline formatting (links, bold) is not preserved.
 */
export function bioParagraphs(bioHtml: string | null): string[] {
  if (!bioHtml) return []
  return decodeEntities(
    bioHtml
      .replace(/<\/(p|div|h[1-6]|li|blockquote)>/gi, '\n')
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<[^>]*>/g, '')
  )
    .split(/\n+/)
    .map((s) => s.replace(/\s+/g, ' ').trim())
    .filter(Boolean)
}

/** Single-line plain text from bio markup, for meta descriptions and JSON-LD. */
export function bioPlainText(bioHtml: string | null): string {
  return bioParagraphs(bioHtml).join(' ')
}

/**
 * Human-readable author name, preferring (in order) nickname, display name,
 * first + last, and finally the nicename. We never surface the WordPress
 * username (login) here.
 */
export function authorDisplayName(author: WPAuthor): string {
  const fullName = [author.firstName, author.lastName]
    .filter((part) => part && part.trim())
    .join(' ')
    .trim()
  return (
    author.nickname?.trim() ||
    author.name?.trim() ||
    fullName ||
    author.slug
  )
}

function slugify(value: string): string {
  return value
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '') // strip diacritics
    .replace(/['’]/g, '') // drop straight/smart apostrophes (D'Orazio → dorazio)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

/**
 * URL slug for an author, derived from the display-name chain so the public URL
 * never contains the WordPress nicename/login. Falls back to the nicename only
 * if the derived value is empty (effectively never, since display_name is
 * always set). Author pages resolve this by matching, since WPGraphQL cannot
 * look a user up by a derived slug.
 */
export function deriveAuthorSlug(author: WPAuthor): string {
  return slugify(authorDisplayName(author)) || author.slug
}

export function resolveAuthorProfile(
  author: WPAuthor,
  teamMember: WPTeamMember | null
): AuthorProfile {
  const name = authorDisplayName(author)

  const role =
    teamMember?.teamMemberFields?.memberRole ||
    teamMember?.teamMemberFields?.memberTitle ||
    null

  const tmImage = teamMember?.featuredImage?.node
  const image = tmImage
    ? { url: tmImage.sourceUrl, alt: tmImage.altText || name }
    : author.avatar?.url
      ? { url: author.avatar.url, alt: name }
      : null

  // Prefer the (translatable) team_member content; fall back to the user's
  // plain-text Biographical Info.
  const bioHtml = teamMember?.content
    ? teamMember.content
    : author.description
      ? `<p>${escapeHtml(author.description)}</p>`
      : null

  const links: AuthorProfile['links'] = {}
  if (author.url) links.website = author.url
  if (teamMember?.teamMemberFields?.memberLinkedinUrl) {
    links.linkedin = teamMember.teamMemberFields.memberLinkedinUrl
  }
  if (teamMember?.teamMemberFields?.memberGithubUrl) {
    links.github = teamMember.teamMemberFields.memberGithubUrl
  }

  return {
    name,
    slug: deriveAuthorSlug(author),
    role,
    bioHtml,
    image,
    links,
  }
}
