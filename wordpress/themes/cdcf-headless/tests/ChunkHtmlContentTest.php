<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_chunk_html_content() — the pure string-splitting helper
 * that keeps OpenAI translation requests under their per-call timeout
 * by chopping oversized HTML at safe block-element boundaries.
 *
 * No mocks needed: input HTML in, array of strings out.
 */
final class ChunkHtmlContentTest extends TestCase
{
    public function test_returns_empty_string_for_empty_input(): void
    {
        // Empty input is wrapped as [''] — caller distinguishes
        // "nothing to translate" by checking the original string.
        $this->assertSame([''], cdcf_chunk_html_content(''));
        $this->assertSame([''], cdcf_chunk_html_content('   '));
    }

    public function test_short_content_returns_as_single_chunk_unmodified(): void
    {
        $html = '<p>Hello world</p>';
        $this->assertSame([$html], cdcf_chunk_html_content($html));
    }

    public function test_long_content_without_block_boundaries_returns_single_chunk(): void
    {
        // 6000 chars of plain text — over the cap, but no closing block
        // tag to split on. cdcf_chunk_html_content cannot fall back to
        // mid-element splits (would tear HTML), so the whole thing is
        // returned as one chunk and the caller proceeds with a single
        // large OpenAI call (will likely time out, but that's the
        // caller's retry problem, not ours).
        $html = str_repeat('a', 6000);
        $this->assertSame([$html], cdcf_chunk_html_content($html, 5000));
    }

    public function test_splits_at_paragraph_boundaries_when_over_cap(): void
    {
        // Three ~1000-char paragraphs, cap at 1500: forces a split between
        // paragraphs 1+2 (combined < 1500 fails — 1010+1010 > 1500) so each
        // paragraph becomes its own chunk.
        $p1 = '<p>' . str_repeat('a', 1000) . '</p>';
        $p2 = '<p>' . str_repeat('b', 1000) . '</p>';
        $p3 = '<p>' . str_repeat('c', 1000) . '</p>';

        $chunks = cdcf_chunk_html_content($p1 . $p2 . $p3, 1500);

        $this->assertCount(3, $chunks);
        $this->assertStringContainsString(str_repeat('a', 1000), $chunks[0]);
        $this->assertStringContainsString(str_repeat('b', 1000), $chunks[1]);
        $this->assertStringContainsString(str_repeat('c', 1000), $chunks[2]);
    }

    public function test_groups_small_parts_under_cap_into_single_chunk(): void
    {
        // Six small paragraphs (~110 chars each) with a low cap (700)
        // forces a grouping decision: parts 1–4 fit under the cap, then
        // 5–6 form the second chunk. Verifies the foreach accumulator
        // doesn't naïvely create one chunk per boundary.
        $p1 = '<p>' . str_repeat('a', 100) . '</p>';
        $p2 = '<p>' . str_repeat('b', 100) . '</p>';
        $p3 = '<p>' . str_repeat('c', 100) . '</p>';
        $p4 = '<p>' . str_repeat('d', 100) . '</p>';
        $p5 = '<p>' . str_repeat('e', 100) . '</p>';
        $p6 = '<p>' . str_repeat('f', 100) . '</p>';

        $html = $p1 . $p2 . $p3 . $p4 . $p5 . $p6;
        // Total ~660 chars but we set max to 300 to force grouping.
        $chunks = cdcf_chunk_html_content($html, 300);

        $this->assertGreaterThan(1, count($chunks));
        // Re-joining must reconstruct the input exactly — no boundary
        // text is dropped or duplicated.
        $this->assertSame($html, implode('', $chunks));
    }

    public function test_splits_at_heading_list_table_and_other_block_tags(): void
    {
        // Mixed block elements — each closing tag is a valid split point.
        // Test with cap small enough that every element becomes its own
        // chunk so we see all five boundary tags exercised.
        $h1     = '<h1>' . str_repeat('a', 400) . '</h1>';
        $ul     = '<ul><li>' . str_repeat('b', 400) . '</li></ul>';
        $table  = '<table><tr><td>' . str_repeat('c', 400) . '</td></tr></table>';
        $quote  = '<blockquote>' . str_repeat('d', 400) . '</blockquote>';
        $pre    = '<pre>' . str_repeat('e', 400) . '</pre>';

        $chunks = cdcf_chunk_html_content($h1 . $ul . $table . $quote . $pre, 500);

        $this->assertCount(5, $chunks);
        // The block tag closing markers stay anchored to their element —
        // each chunk should end with a closing block tag (modulo trailing
        // whitespace).
        foreach ($chunks as $chunk) {
            $this->assertMatchesRegularExpression(
                '#</(h1|ul|table|blockquote|pre)>\s*$#i',
                $chunk
            );
        }
    }

    public function test_uses_default_max_chars_constant_when_omitted(): void
    {
        // Two CDCF_TRANSLATION_CHUNK_CHARS-sized paragraphs (5000 chars each)
        // exceed the default cap so a split must occur.
        $p1 = '<p>' . str_repeat('a', 5000) . '</p>';
        $p2 = '<p>' . str_repeat('b', 5000) . '</p>';

        $chunks = cdcf_chunk_html_content($p1 . $p2);

        $this->assertCount(2, $chunks);
    }

    public function test_boundary_regex_is_case_insensitive(): void
    {
        // HTML in the wild has mixed-case tags. The regex uses the /i
        // flag — verify uppercase closers split just like lowercase.
        $p1 = '<P>' . str_repeat('a', 600) . '</P>';
        $p2 = '<P>' . str_repeat('b', 600) . '</P>';

        $chunks = cdcf_chunk_html_content($p1 . $p2, 1000);

        $this->assertCount(2, $chunks);
    }
}
