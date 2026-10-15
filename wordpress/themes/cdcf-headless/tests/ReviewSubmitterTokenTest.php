<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewSubmitterTokenTest extends TestCase
{
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$id][$key] = $value;
            return true;
        });
        Functions\when('get_post_meta')->alias(
            fn(int $id, string $key, bool $single = false) => $this->meta[$id][$key] ?? ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_issue_stores_only_the_hash_of_a_64_hex_token(): void
    {
        $token = cdcf_review_issue_token(40);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $this->meta[40][CDCF_REVIEW_TOKEN_HASH_META]);
        $this->assertNotContains($token, $this->meta[40]);
    }

    public function test_each_issue_rotates_the_token(): void
    {
        $first  = cdcf_review_issue_token(40);
        $second = cdcf_review_issue_token(40);

        $this->assertNotSame($first, $second);
        $this->assertFalse(cdcf_review_token_matches(40, $first));
        $this->assertTrue(cdcf_review_token_matches(40, $second));
    }

    public function test_wrong_malformed_or_missing_tokens_do_not_match(): void
    {
        $token = cdcf_review_issue_token(40);

        $this->assertFalse(cdcf_review_token_matches(40, str_repeat('0', 64)));
        $this->assertFalse(cdcf_review_token_matches(40, strtoupper($token)));
        $this->assertFalse(cdcf_review_token_matches(40, ''));
        $this->assertFalse(cdcf_review_token_matches(41, $token), 'another project has no hash');
    }

    public function test_expiry_is_honoured_when_set(): void
    {
        $token = cdcf_review_issue_token(40);

        $this->meta[40][CDCF_REVIEW_TOKEN_EXPIRES_META] = time() + 3600;
        $this->assertTrue(cdcf_review_token_matches(40, $token));

        $this->meta[40][CDCF_REVIEW_TOKEN_EXPIRES_META] = time() - 1;
        $this->assertFalse(cdcf_review_token_matches(40, $token));
    }
}
