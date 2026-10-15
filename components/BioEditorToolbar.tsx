'use client'

import { useTranslations } from 'next-intl'
import type { Editor } from '@tiptap/react'
import { EditorToolbar } from '@/components/EditorToolbar'

/** The bio editor's toolbar: the shared toolbar's `bio` variant. */
export default function BioEditorToolbar({
  editor,
}: {
  editor: Editor | null
}) {
  const t = useTranslations('MyBio.toolbar')
  return (
    <EditorToolbar editor={editor} ariaLabel={t('ariaLabel')} variant="bio" />
  )
}
