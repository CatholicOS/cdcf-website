<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared REST args sanitize_callback helpers in
 * includes/sanitizers.php — currently:
 *   - cdcf_sanitize_translations_map() — used by both
 *     /cdcf/v1/link-translations and /cdcf/v1/link-term-translations
 */
final class SanitizersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('absint')->alias(static fn($v): int => (int) max(0, (int) $v));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_array_input_returns_empty_array(): void
    {
        $this->assertSame([], cdcf_sanitize_translations_map('not-an-array'));
        $this->assertSame([], cdcf_sanitize_translations_map(null));
        $this->assertSame([], cdcf_sanitize_translations_map(42));
        $this->assertSame([], cdcf_sanitize_translations_map(new stdClass()));
    }

    public function test_empty_array_returns_empty_array(): void
    {
        $this->assertSame([], cdcf_sanitize_translations_map([]));
    }

    public function test_well_formed_map_passes_through_with_int_coercion(): void
    {
        $sanitized = cdcf_sanitize_translations_map([
            'en' => 10,
            'it' => '11',  // string-int coerced via absint
            'es' => 12.0,  // float coerced
        ]);
        $this->assertSame(['en' => 10, 'it' => 11, 'es' => 12], $sanitized);
    }

    public function test_accepts_iso_639_1_with_optional_iso_3166_1_region(): void
    {
        // 2-letter alone, and the 2-2 hyphenated region form (en-US, pt-BR).
        $sanitized = cdcf_sanitize_translations_map([
            'en'    => 1,
            'en-US' => 2,
            'pt-BR' => 3,
            'zh-CN' => 4,
        ]);
        $this->assertSame(
            ['en' => 1, 'en-US' => 2, 'pt-BR' => 3, 'zh-CN' => 4],
            $sanitized
        );
    }

    public function test_drops_malformed_language_keys(): void
    {
        // Mix of valid and invalid keys; only valid ones survive.
        $sanitized = cdcf_sanitize_translations_map([
            'en'      => 1,        // valid
            'eng'     => 2,        // 3-letter — drop
            'EN'      => 3,        // uppercase — drop
            'e1'      => 4,        // digit — drop
            'en-us'   => 5,        // region must be uppercase — drop
            'en_US'   => 6,        // underscore not allowed — drop
            'en-USA'  => 7,        // 3-letter region — drop
            ''        => 8,        // empty — drop
            'it'      => 9,        // valid
        ]);
        $this->assertSame(['en' => 1, 'it' => 9], $sanitized);
    }

    public function test_drops_non_string_keys(): void
    {
        // PHP numeric-string-key coercion: array literal keys like 0
        // and '0' become int(0). is_string() returns false → dropped.
        $sanitized = cdcf_sanitize_translations_map([
            'en' => 1,
            0    => 2,  // int key
            '1'  => 3,  // string-numeric coerced to int by PHP
            'it' => 4,
        ]);
        $this->assertSame(['en' => 1, 'it' => 4], $sanitized);
    }

    public function test_drops_zero_and_negative_values(): void
    {
        $sanitized = cdcf_sanitize_translations_map([
            'en' => 10,
            'it' => 0,        // absint → 0 → drop
            'es' => -5,       // absint → 0 (max guard) → drop
            'fr' => '0',      // absint('0') → 0 → drop
            'pt' => 'abc',    // absint('abc') → 0 → drop
            'de' => 20,
        ]);
        $this->assertSame(['en' => 10, 'de' => 20], $sanitized);
    }

    public function test_polylang_six_language_map_round_trips_intact(): void
    {
        // The standard cdcf production map — must round-trip with no churn.
        $input = [
            'en' => 169, 'it' => 231, 'es' => 241,
            'fr' => 252, 'pt' => 264, 'de' => 275,
        ];
        $this->assertSame($input, cdcf_sanitize_translations_map($input));
    }
}
