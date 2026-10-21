<?php

use PHPUnit\Framework\TestCase;

final class ReviewEmailStringsTest extends TestCase
{
    public function test_every_language_has_every_template_with_its_placeholders(): void
    {
        foreach (['en', 'it', 'es', 'fr', 'pt', 'de'] as $lang) {
            foreach ([
                'confirmation' => ['{title}', '{link}'], 'question' => ['{title}', '{link}', '{question}'], 'link' => ['{title}', '{link}'],
                'referral_confirmation' => ['{title}', '{link}'], 'referral_question' => ['{title}', '{link}', '{question}'],
                'referral_link' => ['{title}', '{link}'], 'referral_decision' => ['{title}', '{link}', '{outcome}', '{summary}'],
            ] as $template => $needs) {
                $raw = cdcf_review_email_strings()[$lang][$template];
                $this->assertNotSame('', $raw['subject'], "$lang/$template subject");
                $this->assertStringContainsString('{title}', $raw['subject'], "$lang/$template subject");
                foreach ($needs as $placeholder) {
                    $this->assertStringContainsString($placeholder, $raw['body'], "$lang/$template body $placeholder");
                }
            }
        }
    }

    public function test_referral_templates_never_say_your_project(): void
    {
        foreach (cdcf_review_email_strings()['en'] as $template => $raw) {
            if (!str_starts_with($template, 'referral_')) {
                continue;
            }
            foreach ([$raw['subject'], $raw['body']] as $text) {
                $this->assertDoesNotMatchRegularExpression('/your (project|submission)/i', $text, $template);
            }
        }
        $this->assertArrayHasKey('referral_decision', cdcf_review_email_strings()['en']);
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

    public function test_decision_email_names_the_outcome_and_carries_the_summary_in_every_language(): void
    {
        foreach (['en', 'it', 'es', 'fr', 'pt', 'de'] as $lang) {
            $mail = cdcf_review_render_email('decision', $lang, [
                'title' => 'Liturgy Helper', 'link' => 'https://x/l', 'outcome' => 'community', 'summary' => 'Well done.',
            ]);
            $this->assertStringContainsString('Liturgy Helper', $mail['subject'] . $mail['body'], $lang);
            $this->assertStringContainsString(cdcf_review_outcome_labels()[$lang]['community'], $mail['body'], $lang);
            $this->assertStringContainsString('Well done.', $mail['body'], $lang);
            $this->assertStringContainsString('https://x/l', $mail['body'], $lang);
        }
    }

    public function test_outcome_labels_match_the_status_page(): void
    {
        foreach (['en', 'it', 'es', 'fr', 'pt', 'de'] as $lang) {
            $messages = json_decode(file_get_contents(__DIR__ . "/../../../../messages/{$lang}.json"), true);
            $this->assertSame($messages['SubmissionStatus']['outcome'], cdcf_review_outcome_labels()[$lang], $lang);
        }
    }
}
