/**
 * Renders a JSON-LD structured-data script. The serialized JSON is the script's
 * text child (script is a raw-text element, so React inserts it verbatim); `<`
 * is escaped to `<` so an embedded "</script>" cannot break out of the tag.
 */
export default function JsonLd({ data }: { data: Record<string, unknown> }) {
  const json = JSON.stringify(data).replace(/</g, '\\u003c')
  return <script type="application/ld+json">{json}</script>
}
