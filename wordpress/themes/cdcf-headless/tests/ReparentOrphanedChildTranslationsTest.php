<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for cdcf_reparent_orphaned_child_translations().
 *
 * The translation handlers copy post_parent onto a new translation draft only
 * when the parent's translation already exists at draft-creation time — so a
 * child translated BEFORE its parent is created parentless and never healed
 * (the /governance/research papers shipped root-level in 5 languages this
 * way). This helper is the backfill: when a translation of a PARENT page is
 * created, any existing target-language translations of the source's children
 * that are still orphaned (post_parent = 0) get re-parented under the new
 * post.
 */
final class ReparentOrphanedChildTranslationsTest extends TestCase
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

    private function makeSource(array $overrides = []): object
    {
        return (object) array_merge([
            'ID'        => 1150,
            'post_type' => 'page',
        ], $overrides);
    }

    public function test_reparents_orphaned_child_translations(): void
    {
        Functions\when('is_post_type_hierarchical')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        // Source page 1150 has children 1128 and 1129.
        Functions\expect('get_posts')->once()->andReturn([1128, 1129]);
        // Their Italian translations exist and are both orphaned.
        Functions\when('pll_get_post')->alias(
            static fn(int $id, string $lang): int => [1128 => 1132, 1129 => 1137][$id] ?? 0
        );
        Functions\when('get_post_field')->justReturn(0);

        $updates = [];
        Functions\when('wp_update_post')->alias(
            static function (array $args) use (&$updates): int {
                $updates[] = $args;
                return $args['ID'];
            }
        );

        Functions\when('function_exists')->alias(static fn(string $n): bool => true);

        $result = cdcf_reparent_orphaned_child_translations($this->makeSource(), 1151, 'it');

        $this->assertSame([1132, 1137], $result['reparented']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([
            ['ID' => 1132, 'post_parent' => 1151],
            ['ID' => 1137, 'post_parent' => 1151],
        ], $updates);
    }

    public function test_skips_child_translation_that_already_has_a_parent(): void
    {
        Functions\when('is_post_type_hierarchical')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('get_posts')->once()->andReturn([1128]);
        Functions\when('pll_get_post')->justReturn(1132);
        // Already parented (deliberately or by an earlier pass) — leave it alone.
        Functions\when('get_post_field')->justReturn(999);
        Functions\expect('wp_update_post')->never();

        Functions\when('function_exists')->alias(static fn(string $n): bool => true);

        $result = cdcf_reparent_orphaned_child_translations($this->makeSource(), 1151, 'it');

        $this->assertSame(['reparented' => [], 'errors' => []], $result);
    }

    public function test_skips_children_without_a_target_language_translation(): void
    {
        Functions\when('is_post_type_hierarchical')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('get_posts')->once()->andReturn([1128]);
        Functions\when('pll_get_post')->justReturn(0); // not translated yet
        Functions\expect('wp_update_post')->never();

        Functions\when('function_exists')->alias(static fn(string $n): bool => true);

        $result = cdcf_reparent_orphaned_child_translations($this->makeSource(), 1151, 'it');

        $this->assertSame(['reparented' => [], 'errors' => []], $result);
    }

    public function test_noop_for_non_hierarchical_post_types(): void
    {
        // Posts/CPTs have no child pages; also avoids sweeping up attachments
        // (whose post_parent is the post they're attached to).
        Functions\when('is_post_type_hierarchical')->justReturn(false);
        Functions\expect('get_posts')->never();
        Functions\expect('wp_update_post')->never();

        Functions\when('function_exists')->alias(static fn(string $n): bool => true);

        $result = cdcf_reparent_orphaned_child_translations(
            $this->makeSource(['post_type' => 'post']),
            1151,
            'it'
        );

        $this->assertSame(['reparented' => [], 'errors' => []], $result);
    }

    public function test_noop_when_polylang_missing(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $n): bool => $n !== 'pll_get_post'
        );
        Functions\expect('get_posts')->never();
        Functions\expect('wp_update_post')->never();

        $result = cdcf_reparent_orphaned_child_translations($this->makeSource(), 1151, 'it');

        $this->assertSame(['reparented' => [], 'errors' => []], $result);
    }

    public function test_failed_update_is_not_reported_as_reparented(): void
    {
        Functions\when('is_post_type_hierarchical')->justReturn(true);
        Functions\expect('get_posts')->once()->andReturn([1128]);
        Functions\when('pll_get_post')->justReturn(1132);
        Functions\when('get_post_field')->justReturn(0);
        $err = new WP_Error('update_failed', 'nope');
        Functions\when('wp_update_post')->justReturn($err);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);

        Functions\when('function_exists')->alias(static fn(string $n): bool => true);

        $result = cdcf_reparent_orphaned_child_translations($this->makeSource(), 1151, 'it');

        $this->assertSame([], $result['reparented']);
        $this->assertSame(
            ['Failed to re-parent orphaned child translation 1132 under 1151.'],
            $result['errors']
        );
    }
}
