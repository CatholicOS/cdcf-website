<?php

use PHPUnit\Framework\TestCase;

final class ReviewEmailStringsTest extends TestCase
{
    public function test_every_language_has_every_template_with_its_placeholders(): void
    {
        foreach (['en', 'it', 'es', 'fr', 'pt', 'de'] as $lang) {
            foreach (['confirmation' => ['{title}', '{link}'], 'question' => ['{title}', '{link}', '{question}'], 'link' => ['{title}', '{link}']] as $template => $needs) {
                $raw = cdcf_review_email_strings()[$lang][$template];
                $this->assertNotSame('', $raw['subject'], "$lang/$template subject");
                $this->assertStringContainsString('{title}', $raw['subject'], "$lang/$template subject");
                foreach ($needs as $placeholder) {
                    $this->assertStringContainsString($placeholder, $raw['body'], "$lang/$template body $placeholder");
                }
            }
        }
    }

    public function test_render_fills_placeholders(): void
    {
        $mail = cdcf_review_render_email('question', 'it', [
            'title' => 'Liturgy Helper', 'link' => 'https://x/open?t=abc', 'question' => 'Which licence?',
        ]);

        $this->assertStringContainsString('Liturgy Helper', $mail['subject']);
        $this->assertStringContainsString('https://x/open?t=abc', $mail['body']);
        $this->assertStringContainsString('Which licence?', $mail['body']);
        $this->assertStringNotContainsString('{', $mail['body']);
    }

    public function test_unknown_language_falls_back_to_english(): void
    {
        $this->assertSame(
            cdcf_review_render_email('link', 'en', ['title' => 'T', 'link' => 'L']),
            cdcf_review_render_email('link', 'xx', ['title' => 'T', 'link' => 'L'])
        );
    }
}
