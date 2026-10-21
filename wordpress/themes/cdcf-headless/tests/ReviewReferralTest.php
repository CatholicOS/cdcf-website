<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/review/referral.php — referral identity and the
 * submitter accessors that fall back to the referrer's details.
 */
final class ReviewReferralTest extends TestCase
{
    /** @var array<string, string> */
    private array $meta = [];
    private string $post_type = 'community_project';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];
        $this->post_type = 'community_project';
        Functions\when('get_post_type')->alias(fn() => $this->post_type);
        Functions\when('get_post_meta')->alias(
            fn(int $id, string $key) => $this->meta[$key] ?? ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_community_project_with_referrer_email_is_a_referral(): void
    {
        $this->meta['_referral_submitter_email'] = 'r@example.org';

        $this->assertTrue(cdcf_review_is_referral(40));
        $this->assertSame('referral', cdcf_review_kind(40));
    }

    public function test_converted_submission_is_not_a_referral(): void
    {
        $this->meta['_referral_submitter_email'] = '';
        $this->meta['_submission_submitter_email'] = 's@example.org';

        $this->assertFalse(cdcf_review_is_referral(40));
        $this->assertSame('submission', cdcf_review_kind(40));
    }

    public function test_project_is_not_a_referral(): void
    {
        $this->post_type = 'project';
        $this->meta['_referral_submitter_email'] = 'r@example.org';

        $this->assertFalse(cdcf_review_is_referral(40));
        $this->assertSame('submission', cdcf_review_kind(40));
    }

    public function test_submitter_email_prefers_submission_key_then_referral_key(): void
    {
        $this->meta = [
            '_submission_submitter_email' => 's@example.org',
            '_referral_submitter_email'   => 'r@example.org',
        ];
        $this->assertSame('s@example.org', cdcf_review_submitter_email(40));

        $this->meta = ['_referral_submitter_email' => 'r@example.org'];
        $this->assertSame('r@example.org', cdcf_review_submitter_email(40));

        $this->meta = [];
        $this->assertSame('', cdcf_review_submitter_email(40));
    }

    public function test_submitter_name_falls_back_the_same_way(): void
    {
        $this->meta = [
            '_submission_submitter_name' => 'Sam',
            '_referral_submitter_name'   => 'Rae',
        ];
        $this->assertSame('Sam', cdcf_review_submitter_name(40));

        $this->meta = ['_referral_submitter_name' => 'Rae'];
        $this->assertSame('Rae', cdcf_review_submitter_name(40));

        $this->meta = [];
        $this->assertSame('', cdcf_review_submitter_name(40));
    }
}
