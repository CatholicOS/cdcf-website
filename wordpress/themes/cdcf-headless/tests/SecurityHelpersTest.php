<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the three pure-ish spam-protection helpers in
 * includes/security.php:
 *
 *   - cdcf_check_ip_rbl()       — DNSBL lookup with 1h transient cache
 *   - cdcf_is_disposable_email() — blocklist-file lookup with static cache
 *   - cdcf_is_spam_content()    — heuristic content scoring
 *
 * Notes on isolation:
 *   - checkdnsrr() is a PHP built-in. Patchwork can only redefine it
 *     because we listed it in patchwork.json under redefinable-internals.
 *   - cdcf_is_disposable_email() uses `static $domains` which lives for
 *     the lifetime of the PHP process — once the blocklist file has
 *     been loaded, subsequent calls hit the cache regardless of what
 *     happens on disk. Tests that need a *fresh* cache run under
 *     #[RunInSeparateProcess] so the static state starts from null.
 */
final class SecurityHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ─── cdcf_check_ip_rbl() ──────────────────────────────────────────

    public function test_rbl_returns_false_for_non_ipv4_input(): void
    {
        // No transient lookup, no DNS query — the IPv4 validation
        // short-circuits before either.
        Functions\expect('get_transient')->never();
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_check_ip_rbl('not-an-ip'));
        $this->assertFalse(cdcf_check_ip_rbl('2001:db8::1')); // IPv6 unsupported
    }

    public function test_rbl_returns_true_when_cached_as_listed(): void
    {
        Functions\when('get_transient')->justReturn('listed');
        Functions\expect('set_transient')->never();

        $this->assertTrue(cdcf_check_ip_rbl('198.51.100.1'));
    }

    public function test_rbl_returns_false_when_cached_as_clean(): void
    {
        Functions\when('get_transient')->justReturn('clean');
        Functions\expect('set_transient')->never();

        $this->assertFalse(cdcf_check_ip_rbl('198.51.100.1'));
    }

    public function test_rbl_uses_reversed_ip_against_each_blocklist(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $queried = [];
        Patchwork\redefine(
            'checkdnsrr',
            function (string $host, string $type) use (&$queried): bool {
                $queried[] = [$host, $type];
                return false; // no hits — exercise both lookups
            }
        );

        $this->assertFalse(cdcf_check_ip_rbl('198.51.100.42'));

        // 198.51.100.42 reversed → 42.100.51.198.
        $this->assertSame([
            ['42.100.51.198.zen.spamhaus.org', 'A'],
            ['42.100.51.198.bl.spamcop.net',   'A'],
        ], $queried);
    }

    public function test_rbl_short_circuits_after_first_listing_hit(): void
    {
        Functions\when('get_transient')->justReturn(false);
        $cached = null;
        Functions\when('set_transient')->alias(
            function (string $key, $value, int $ttl) use (&$cached): bool {
                $cached = $value;
                return true;
            }
        );

        $calls = 0;
        Patchwork\redefine(
            'checkdnsrr',
            function () use (&$calls): bool {
                $calls++;
                return true; // first RBL hits → second should not be queried
            }
        );

        $this->assertTrue(cdcf_check_ip_rbl('198.51.100.42'));
        $this->assertSame(1, $calls);
        $this->assertSame('listed', $cached);
    }

    public function test_rbl_caches_clean_result_when_no_blocklists_hit(): void
    {
        Functions\when('get_transient')->justReturn(false);
        $cached = null;
        $ttl    = null;
        Functions\when('set_transient')->alias(
            function (string $key, $value, int $secs) use (&$cached, &$ttl): bool {
                $cached = $value;
                $ttl    = $secs;
                return true;
            }
        );

        Patchwork\redefine('checkdnsrr', static fn(): bool => false);

        $this->assertFalse(cdcf_check_ip_rbl('198.51.100.42'));
        $this->assertSame('clean', $cached);
        $this->assertSame(HOUR_IN_SECONDS, $ttl);
    }

    // ─── cdcf_is_disposable_email() ───────────────────────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_disposable_returns_false_when_email_has_no_domain(): void
    {
        // No '@' → strrchr() returns false → empty domain → early-return.
        // The blocklist file is never opened, so this is safe even though
        // CDCF_DISPOSABLE_DOMAINS_FILE may not exist.
        $this->assertFalse(cdcf_is_disposable_email('no-at-sign'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_disposable_returns_false_when_blocklist_file_missing(): void
    {
        // Bootstrap defines CDCF_DISPOSABLE_DOMAINS_FILE to a pid-scoped
        // tmp path that we deliberately don't create here — exercises
        // the file_exists() guard.
        if (file_exists(CDCF_DISPOSABLE_DOMAINS_FILE)) {
            unlink(CDCF_DISPOSABLE_DOMAINS_FILE);
        }

        $this->assertFalse(cdcf_is_disposable_email('user@example.com'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_disposable_returns_true_for_blocklisted_domain(): void
    {
        file_put_contents(
            CDCF_DISPOSABLE_DOMAINS_FILE,
            "mailinator.com\nguerrillamail.com\n10minutemail.com\n"
        );

        $this->assertTrue(cdcf_is_disposable_email('throwaway@mailinator.com'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_disposable_returns_false_for_clean_domain(): void
    {
        file_put_contents(
            CDCF_DISPOSABLE_DOMAINS_FILE,
            "mailinator.com\nguerrillamail.com\n"
        );

        $this->assertFalse(cdcf_is_disposable_email('user@example.org'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_disposable_lookup_is_case_insensitive(): void
    {
        file_put_contents(
            CDCF_DISPOSABLE_DOMAINS_FILE,
            "mailinator.com\n"
        );

        $this->assertTrue(cdcf_is_disposable_email('user@MAILINATOR.COM'));
        $this->assertTrue(cdcf_is_disposable_email('user@Mailinator.Com'));
    }

    // ─── cdcf_is_spam_content() ───────────────────────────────────────

    public function test_spam_returns_false_for_clean_short_text(): void
    {
        $this->assertFalse(cdcf_is_spam_content(
            'A genuine project description with nothing flagged.'
        ));
    }

    public function test_spam_returns_true_on_html_injection_attempt(): void
    {
        // <script> alone contributes +10 — well above the threshold.
        $this->assertTrue(cdcf_is_spam_content(
            'Hello <script>alert(1)</script> world'
        ));
    }

    public function test_spam_detects_iframe_object_embed_form_style_tags(): void
    {
        foreach (['iframe', 'object', 'embed', 'form', 'style'] as $tag) {
            $this->assertTrue(
                cdcf_is_spam_content("Hello <{$tag} src=x>"),
                "expected <{$tag}> to be flagged"
            );
        }
    }

    public function test_spam_returns_true_on_two_keywords_below_url_threshold(): void
    {
        // 2 keywords × 3 = 6 → over threshold.
        $this->assertTrue(cdcf_is_spam_content(
            'Try our viagra and join the casino tonight!'
        ));
    }

    public function test_spam_returns_false_for_single_keyword_alone(): void
    {
        // 1 keyword × 3 = 3 → under threshold.
        $this->assertFalse(cdcf_is_spam_content('Visit our casino site.'));
    }

    public function test_spam_returns_true_when_many_urls_plus_one_keyword(): void
    {
        // 3 URLs (>2) → +2, one keyword → +3 = 5, meets threshold.
        $this->assertTrue(cdcf_is_spam_content(
            'casino http://a.test http://b.test http://c.test'
        ));
    }

    public function test_spam_returns_true_when_text_is_mostly_non_latin(): void
    {
        // Non-Latin ratio (+2) plus one keyword (+3) = 5, meets threshold.
        // Mixing latin keyword + Cyrillic-heavy body keeps the
        // non-latin ratio above 50%.
        $this->assertTrue(cdcf_is_spam_content(
            'casino пример текст для проверки спам фильтра кириллицей'
        ));
    }

    public function test_spam_returns_true_on_two_emails_plus_one_keyword(): void
    {
        // 2 emails (>1) → +2, casino keyword → +3 = 5, meets threshold.
        $this->assertTrue(cdcf_is_spam_content(
            'casino contact a@b.test or c@d.test for details'
        ));
    }
}
