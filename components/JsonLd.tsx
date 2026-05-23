/**
 * Renders a JSON-LD structured-data script. The serialized JSON is the script's
 * text child (script is a raw-text element, so React inserts it verbatim); `<`
 * is replaced with its JSON unicode escape (backslash-u-003c) so an embedded
 * "</script>" in the data cannot break out of the tag.
 */
export default function JsonLd({ data }: { data: Record<string, unknown> }) {
  const json = JSON.stringify(data).replace(/</g, '\\u003c')
  return <script type="application/ld+json">{json}</script>
}
