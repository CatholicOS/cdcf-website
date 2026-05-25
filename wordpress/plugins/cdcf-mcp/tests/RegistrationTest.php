<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Abilities API registration callbacks. These run on core's
 * registration hooks at runtime, so they're stubbed here (the live behaviour
 * is exercised by the local docker pilot, see docs/wordpress-mcp-evaluation.md).
 *
 * The category and the abilities register on two SEPARATE core hooks; this
 * suite locks in that the category is declared once as `cdcf`, and that every
 * ability is registered under that category, public, and capability-gated.
 */
final class RegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_category_declares_the_cdcf_category(): void
    {
        $captured = null;
        Functions\when('wp_register_ability_category')->alias(
            function ($slug, $args) use (&$captured) {
                $captured = [$slug, $args];
                return null;
            }
        );

        cdcf_mcp_register_category();

        $this->assertNotNull($captured, 'wp_register_ability_category was not called.');
        $this->assertSame('cdcf', $captured[0]);
        $this->assertArrayHasKey('label', $captured[1]);
        $this->assertArrayHasKey('description', $captured[1]);
    }

    public function test_register_abilities_registers_every_definition_under_cdcf(): void
    {
        $calls = [];
        Functions\when('wp_register_ability')->alias(
            function ($name, $args) use (&$calls) {
                $calls[] = [$name, $args];
                return null;
            }
        );

        cdcf_mcp_register_abilities();

        // One registration per definition, in definition order.
        $this->assertSame(
            cdcf_mcp_ability_names(),
            array_map(static fn(array $c): string => $c[0], $calls)
        );

        // Every ability is filed under the cdcf category, exposed to MCP, and
        // gated behind a permission callback.
        foreach ($calls as [$name, $args]) {
            $this->assertSame('cdcf', $args['category'], "Wrong category for {$name}");
            $this->assertTrue($args['meta']['mcp']['public'], "Not MCP-public: {$name}");
            $this->assertIsCallable($args['permission_callback'], "No permission gate: {$name}");
        }
    }
}
