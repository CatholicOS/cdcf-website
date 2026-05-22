<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_seed_polylang_default_language(): the admin_init
 * callback that writes pll_filter_content = pll_default_language()
 * exactly once per admin user, then never again.
 */
final class PolylangDefaultSeedTest extends TestCase
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

    /**
     * Stub the WP functions the seed callback may touch BEFORE
     * overriding function_exists — Brain Monkey's FunctionStub
     * constructor short-circuits when function_exists() says the
     * target already exists, leaving the symbol undefined at call time.
     */
    private function stubWpUserFunctions(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('update_user_meta')->justReturn(true);
        Functions\when('pll_default_language')->justReturn('en');
    }

    public function test_skips_seed_when_polylang_inactive(): void
    {
        $this->stubWpUserFunctions();
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_default_language'
        );

        // get_current_user_id / update_user_meta must not be reached on
        // the polylang-missing path. Track by replacing the simple stubs
        // with no-call-tracking aliases.
        $callsToUser   = 0;
        $callsToUpdate = 0;
        Functions\when('get_current_user_id')->alias(function () use (&$callsToUser): int {
            $callsToUser++;
            return 0;
        });
        Functions\when('update_user_meta')->alias(function () use (&$callsToUpdate): bool {
            $callsToUpdate++;
            return true;
        });

        cdcf_seed_polylang_default_language();

        $this->assertSame(0, $callsToUser);
        $this->assertSame(0, $callsToUpdate);
    }

    public function test_skips_seed_when_no_logged_in_user(): void
    {
        $this->stubWpUserFunctions();
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);

        $callsToGetMeta = 0;
        $callsToUpdate  = 0;
        Functions\when('get_user_meta')->alias(function () use (&$callsToGetMeta): string {
            $callsToGetMeta++;
            return '';
        });
        Functions\when('update_user_meta')->alias(function () use (&$callsToUpdate): bool {
            $callsToUpdate++;
            return true;
        });
        Functions\when('get_current_user_id')->justReturn(0);

        cdcf_seed_polylang_default_language();

        $this->assertSame(0, $callsToGetMeta);
        $this->assertSame(0, $callsToUpdate);
    }

    public function test_skips_seed_when_user_already_seeded(): void
    {
        $this->stubWpUserFunctions();
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
        Functions\when('get_current_user_id')->justReturn(42);
        // _cdcf_pll_default_filter_seeded already set → early return.
        Functions\when('get_user_meta')->alias(
            static fn(int $user_id, string $key) => $key === '_cdcf_pll_default_filter_seeded' ? '1' : ''
        );

        $updates = [];
        Functions\when('update_user_meta')->alias(
            function (int $user_id, string $key, $value) use (&$updates): bool {
                $updates[] = [$user_id, $key, $value];
                return true;
            }
        );

        cdcf_seed_polylang_default_language();

        $this->assertSame([], $updates);
    }

    public function test_first_visit_writes_filter_meta_and_seeded_flag(): void
    {
        $this->stubWpUserFunctions();
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
        Functions\when('get_current_user_id')->justReturn(42);
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('pll_default_language')->justReturn('en');

        $metaWrites = [];
        Functions\when('update_user_meta')->alias(
            function (int $user_id, string $key, $value) use (&$metaWrites): bool {
                $metaWrites[] = [$user_id, $key, $value];
                return true;
            }
        );

        cdcf_seed_polylang_default_language();

        // Both writes happen, in order, targeting the same user.
        $this->assertSame(
            [
                [42, 'pll_filter_content', 'en'],
                [42, '_cdcf_pll_default_filter_seeded', '1'],
            ],
            $metaWrites
        );
    }
}
