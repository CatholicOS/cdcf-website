'use client'

import { useState, useRef, useCallback } from 'react'
import { useTranslations } from 'next-intl'

const CORRECT_ANSWER = 24

function getHintKey(guess: number): { key: string; color: string } {
  const diff = Math.abs(guess - CORRECT_ANSWER)
  if (diff === 0) return { key: '', color: '' }
  if (diff <= 2) return { key: 'hot', color: 'text-red-600' }
  if (diff <= 5) return { key: 'warm', color: 'text-orange-500' }
  if (diff <= 10) return { key: 'lukewarm', color: 'text-yellow-600' }
  if (diff <= 20) return { key: 'cold', color: 'text-blue-400' }
  return { key: 'freezing', color: 'text-blue-700' }
}

interface FishEasterEggProps {
  explanationHtml?: string
}

export default function FishEasterEgg({ explanationHtml }: FishEasterEggProps) {
  const t = useTranslations('fishEasterEgg')
  const [guess, setGuess] = useState('')
  const [hint, setHint] = useState<{ key: string; color: string } | null>(null)
  const [solved, setSolved] = useState(false)
  const dialogRef = useRef<HTMLDialogElement>(null)

  const openDialog = useCallback(() => {
    dialogRef.current?.showModal()
  }, [])

  const closeDialog = useCallback(() => {
    dialogRef.current?.close()
  }, [])

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const num = parseInt(guess, 10)
    if (isNaN(num) || num < 1) return
    if (num === CORRECT_ANSWER) {
      setSolved(true)
      setHint(null)
    } else {
      setHint(getHintKey(num))
    }
  }

  return (
    <>
      <div className="mt-12 rounded-lg border border-dashed border-cdcf-gold/40 bg-cdcf-gold/5 px-6 py-5 text-center">
        <p className="font-serif text-lg italic text-cdcf-navy/70">
          {t('prompt')}
        </p>

        <form onSubmit={handleSubmit} className="mt-4 flex items-center justify-center gap-3">
          <input
            type="number"
            min="1"
            value={guess}
            onChange={(e) => setGuess(e.target.value)}
            placeholder={t('placeholder')}
            className="w-36 rounded-md border border-gray-300 px-3 py-2 text-center text-lg focus:border-cdcf-gold focus:ring-1 focus:ring-cdcf-gold focus:outline-none"
          />
          <button
            type="submit"
            className="cdcf-btn-primary rounded-md px-5 py-2 text-sm"
          >
            {t('guess')}
          </button>
        </form>

        {hint && (
          <p className={`mt-3 text-lg font-semibold ${hint.color}`}>
            {t(hint.key)}
          </p>
        )}

        {solved && (
          <p className="mt-3 text-lg font-semibold text-green-600">
            {t('correct', { count: CORRECT_ANSWER })}{' '}
            {explanationHtml && (
              <button
                onClick={openDialog}
                className="text-cdcf-gold underline underline-offset-2 hover:text-cdcf-navy"
              >
                {t('discoverWhy')} &rarr;
              </button>
            )}
          </p>
        )}
      </div>

      {/* Explanation dialog */}
      {explanationHtml && (
        <dialog
          ref={dialogRef}
          className="mx-auto max-h-[80vh] w-full max-w-3xl rounded-xl border-0 p-0 shadow-2xl backdrop:bg-black/50 open:flex open:flex-col"
        >
          <div className="flex shrink-0 items-center justify-between border-b bg-cdcf-navy px-6 py-4">
            <h2 className="font-serif text-xl font-bold text-white">
              {t('dialogTitle')}
            </h2>
            <button
              onClick={closeDialog}
              className="rounded-full p-1 text-white/70 transition-colors hover:bg-white/10 hover:text-white"
              aria-label="Close"
            >
              <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" strokeWidth="2" fill="none">
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div
            className="prose prose-lg overflow-y-auto p-6 text-gray-700"
            dangerouslySetInnerHTML={{ __html: explanationHtml }}
          />
        </dialog>
      )}
    </>
  )
}
