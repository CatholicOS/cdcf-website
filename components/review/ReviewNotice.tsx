export function ReviewNotice({
  title,
  message,
}: {
  title: string
  message: string
}) {
  return (
    <main className="cdcf-section">
      <div className="prose max-w-prose">
        <h1>{title}</h1>
        <p>{message}</p>
      </div>
    </main>
  )
}
