import { slugify } from './slugify'

/**
 * Shareable anchor ids for a list of bio cards (e.g. `tom-ciszek`), one per
 * member, in order. Each id is the slug of the member's name, or
 * `member-N` (1-based position) when the name has no usable characters.
 *
 * Ids are unique against everything in `used`, which the function adds to:
 * pass one Set to every call for a page so cards in different sections
 * never share an id. A taken id gets the first free `-2`, `-3`, … suffix,
 * each candidate checked against all ids allocated so far.
 *
 * Call it from the component that owns the page, not inside a section's
 * render: React may render a component twice, and a registry mutated during
 * render would then suffix every id.
 */
export function allocateMemberAnchors(
  members: ReadonlyArray<{ title: string }>,
  used: Set<string> = new Set()
): string[] {
  return members.map((member, i) => {
    const base = slugify(member.title) || `member-${i + 1}`
    let candidate = base
    for (let n = 2; used.has(candidate); n++) candidate = `${base}-${n}`
    used.add(candidate)
    return candidate
  })
}
