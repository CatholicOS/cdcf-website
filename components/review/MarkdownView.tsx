import clsx from "clsx";
import Markdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";

/**
 * The only Markdown renderer for council review content (review bodies
 * and .md attachments). Raw HTML is dropped, links open in a new tab
 * without a referrer, and Markdown images become plain links so a
 * confidential page never loads third-party resources. react-markdown's
 * default urlTransform strips javascript: and other unsafe schemes.
 */
const components: Components = {
  a: ({ href, children }) => (
    <a href={href} target="_blank" rel="noopener noreferrer nofollow">
      {children}
    </a>
  ),
  img: ({ src, alt }) =>
    typeof src === "string" && src !== "" ? (
      <a href={src} target="_blank" rel="noopener noreferrer nofollow">
        {alt || src}
      </a>
    ) : null,
};

export function MarkdownView({
  source,
  className,
}: {
  source: string;
  className?: string;
}) {
  return (
    <div className={clsx("prose prose-sm max-w-none", className)}>
      <Markdown remarkPlugins={[remarkGfm]} skipHtml components={components}>
        {source}
      </Markdown>
    </div>
  );
}
