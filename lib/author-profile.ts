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

export function resolveAuthorProfile(
  author: WPAuthor,
  teamMember: WPTeamMember | null
): AuthorProfile {
  const role =
    teamMember?.teamMemberFields?.memberRole ||
    teamMember?.teamMemberFields?.memberTitle ||
    null

  const tmImage = teamMember?.featuredImage?.node
  const image = tmImage
    ? { url: tmImage.sourceUrl, alt: tmImage.altText || author.name }
    : author.avatar?.url
      ? { url: author.avatar.url, alt: author.name }
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
    name: author.name,
    slug: author.slug,
    role,
    bioHtml,
    image,
    links,
  }
}
