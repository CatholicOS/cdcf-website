<?php
/**
 * Protect in-page fragment anchors from wp_kses_post().
 *
 * WordPress core's wp_kses_bad_protocol() reads the substring before the first
 * ":" in an href as a URL scheme. For a footnote anchor like
 * href="#fn:encyclical" it sees scheme "fn" (the leading "#" is normalized
 * away), finds it disallowed, and strips it — leaving href="encyclical". That
 * silently breaks every named-anchor footnote / back-link produced by Markdown
 * converters using the fn:/fnref: convention (Python-Markdown, PHP Markdown
 * Extra, …). Numeric anchors (#fn1) have no colon and are unaffected.
 *
 * cdcf_protect_fragment_anchors() percent-encodes colons inside in-page
 * fragment hrefs (href="#…:…" → "#…%3A…") *before* wp_kses_post, so KSES sees
 * no scheme delimiter and keeps the href. The matching id="fn:encyclical"
 * keeps its literal colon (KSES doesn't protocol-check id values), and the
 * browser percent-decodes the fragment back to "fn:encyclical" when matching
 * it to the id (HTML fragment navigation decodes before id comparison), so
 * both the footnote links and the back-links still resolve. Real URLs,
 * colon-free fragments, and id attributes are untouched; already-encoded %3A
 * is a no-op, so this is idempotent.
 *
 * Apply at every theme content sink that runs wp_kses_post on author/translated
 * HTML (team-member, AI-translate, translation orchestrator, deploy-translation).
 * The cdcf-mcp plugin carries its own copy (cdcf_mcp_protect_fragment_anchors)
 * since the two trees are independent.
 */

defined('ABSPATH') || exit;

function cdcf_protect_fragment_anchors(string $html): string
{
    $out = preg_replace_callback(
        '/\bhref\s*=\s*(["\'])(#[^"\']*)\1/i',
        static function (array $m): string {
            return 'href=' . $m[1] . str_replace(':', '%3A', $m[2]) . $m[1];
        },
        $html
    );
    // preg_replace_callback returns null only on a PCRE error; fall back to the
    // original content rather than nulling it out.
    return $out ?? $html;
}
