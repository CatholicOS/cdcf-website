import { cookies, draftMode } from 'next/headers'
import { redirect } from 'next/navigation'

import { PREVIEW_COOKIE } from '@/lib/wordpress/preview'

export async function GET() {
  const draft = await draftMode()
  draft.disable()
  ;(await cookies()).delete(PREVIEW_COOKIE)

  redirect('/')
}
