<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReviewMemberEmailStringsTest extends TestCase
{
    private const TYPES = ['submission_received', 'submitter_answered', 'decision_recorded', 'admin_override', 'reply_posted'];

    public function test_every_language_has_every_template_with_its_placeholders(): void
    {
        $all = cdcf_review_member_email_strings();
        $this->assertSame(['en', 'it', 'es', 'fr', 'pt', 'de'], array_keys($all));
        foreach ($all as $lang => $templates) {
            $this->assertSame(self::TYPES, array_keys($templates), $lang);
            foreach ($templates as $type => $t) {
                $this->assertStringContainsString('{title}', $t['subject'], "$lang $type subject");
                $this->assertStringContainsString('{link}', $t['body'], "$lang $type body");
                if (in_array($type, ['decision_recorded', 'admin_override', 'reply_posted'], true)) {
                    $this->assertStringContainsString('{actor}', $t['body'], "$lang $type actor");
                }
                if (in_array($type, ['decision_recorded', 'admin_override'], true)) {
                    $this->assertStringContainsString('{outcome}', $t['body'], "$lang $type outcome");
                }
            }
        }
    }

    public function test_renders_with_the_outcome_label_and_footer(): void
    {
        $mail = cdcf_review_render_member_email('decision_recorded', 'it', [
            'title' => 'Liturgy Helper', 'actor' => 'Fr. A', 'outcome' => 'community', 'link' => 'https://x/it/review/40',
        ]);

        $this->assertSame('Decisione registrata: Liturgy Helper', $mail['subject']);
        $this->assertStringContainsString('Fr. A ha registrato la decisione su "Liturgy Helper": Accettato come progetto della comunità.', $mail['body']);
        $this->assertStringContainsString('https://x/it/review/40', $mail['body']);
        $this->assertStringContainsString('Consiglio di amministrazione', $mail['body']);
    }

    public function test_unknown_language_falls_back_to_english_and_unknown_type_is_null(): void
    {
        $this->assertSame('New project to review: T', cdcf_review_render_member_email('submission_received', 'xx', ['title' => 'T'])['subject']);
        $this->assertNull(cdcf_review_render_member_email('review_posted', 'en', []));
    }

    public function test_someone_label_per_language(): void
    {
        $this->assertSame('Someone', cdcf_review_member_email_someone('en'));
        $this->assertSame('Jemand', cdcf_review_member_email_someone('de'));
        $this->assertSame('Someone', cdcf_review_member_email_someone('xx'));
    }
}
