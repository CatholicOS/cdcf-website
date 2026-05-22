import { cookies, draftMode } from 'next/headers'

/**
 * httpOnly cookie set by /api/preview alongside Next.js draft mode. It carries
 * which post the preview link targeted so the page routes can fetch it by
 * database id (drafts have no usable slug yet) and scope preview rendering to
 * the intended target rather than hijacking every route while draft mode is on.
 */
export const PREVIEW_COOKIE = 'cdcf_preview'

export interface PreviewTarget {
  id: number
  type: string
  slug: string
}

/**
 * Returns the active preview target, or null when not in a preview session.
 * Draft mode and the target cookie are set together by /api/preview, so both
 * must be present.
 */
export async function getPreviewTarget(): Promise<PreviewTarget | null> {
  const { isEnabled } = await draftMode()
  if (!isEnabled) return null

  const raw = (await cookies()).get(PREVIEW_COOKIE)?.value
  if (!raw) return null

  try {
    const parsed = JSON.parse(raw) as {
      id?: unknown
      type?: unknown
      slug?: unknown
    }
    const id = Number(parsed.id)
    if (!id || typeof parsed.type !== 'string') return null
    return {
      id,
      type: parsed.type,
      slug: typeof parsed.slug === 'string' ? parsed.slug : '',
    }
  } catch {
    return null
  }
}

/**
 * True when the requested route slug refers to the previewed post. New drafts
 * have no slug yet, so /api/preview falls back to the numeric id as the URL
 * segment — hence matching either the stored slug or the id.
 */
export function previewMatchesSlug(target: PreviewTarget, slug: string): boolean {
  return slug === target.slug || slug === String(target.id)
}
