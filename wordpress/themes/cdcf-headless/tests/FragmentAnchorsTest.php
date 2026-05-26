<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_protect_fragment_anchors() — the helper applied before
 * wp_kses_post at every theme content sink so colon footnote anchors
 * (#fn:…/#fnref:…) survive KSES's protocol stripping. Pure string function;
 * no WordPress runtime needed.
 */
final class FragmentAnchorsTest extends TestCase
{
    public function test_encodes_colons_in_fragment_hrefs(): void
    {
        $this->assertSame(
            '<a href="#fn%3Aencyclical">1</a>',
            cdcf_protect_fragment_anchors('<a href="#fn:encyclical">1</a>')
        );
        // numbered back-ref form, single quotes
        $this->assertSame(
            "<a href='#fnref2%3Amanifesto'>↩</a>",
            cdcf_protect_fragment_anchors("<a href='#fnref2:manifesto'>↩</a>")
        );
    }

    public function test_leaves_urls_ids_and_plain_fragments_untouched(): void
    {
        // Real URL (scheme colon) untouched.
        $this->assertSame(
            '<a href="https://example.org/x">x</a>',
            cdcf_protect_fragment_anchors('<a href="https://example.org/x">x</a>')
        );
        // id attribute keeps its literal colon (kses doesn't protocol-check ids).
        $this->assertSame(
            '<li id="fn:encyclical">n</li>',
            cdcf_protect_fragment_anchors('<li id="fn:encyclical">n</li>')
        );
        // Colon-free fragment untouched.
        $this->assertSame(
            '<a href="#section">x</a>',
            cdcf_protect_fragment_anchors('<a href="#section">x</a>')
        );
    }

    public function test_is_idempotent(): void
    {
        $once  = cdcf_protect_fragment_anchors('<a href="#fn:1">x</a>');
        $twice = cdcf_protect_fragment_anchors($once);
        $this->assertSame('<a href="#fn%3A1">x</a>', $twice);
        $this->assertSame($once, $twice);
    }

    public function test_paired_footnote_link_and_backlink_stay_consistent(): void
    {
        // Forward link href encoded; the matching id keeps its literal colon —
        // the browser percent-decodes the fragment back to fn:encyclical to
        // match the id, so both directions resolve.
        $html = '<sup><a href="#fn:encyclical" id="fnref:encyclical">1</a></sup>'
            . '<li id="fn:encyclical">note <a href="#fnref:encyclical">↩</a></li>';
        $out = cdcf_protect_fragment_anchors($html);

        $this->assertStringContainsString('href="#fn%3Aencyclical"', $out);
        $this->assertStringContainsString('href="#fnref%3Aencyclical"', $out);
        $this->assertStringContainsString('id="fnref:encyclical"', $out);
        $this->assertStringContainsString('id="fn:encyclical"', $out);
    }
}
