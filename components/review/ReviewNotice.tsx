import type { ReactNode } from 'react'

export function ReviewNotice({
  title,
  message,
  action,
}: {
  title: string
  message: string
  action?: ReactNode
}) {
  return (
    <main className="cdcf-section">
      <div className="prose max-w-prose">
        <h1>{title}</h1>
        <p>
          {message} {action}
        </p>
      </div>
    </main>
  )
}
