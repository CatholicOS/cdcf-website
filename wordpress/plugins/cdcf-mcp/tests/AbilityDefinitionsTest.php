<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Structural tests over the ability registry. These need no WordPress
 * runtime — they assert the definitions are internally consistent so a
 * malformed entry can't silently ship.
 */
final class AbilityDefinitionsTest extends TestCase
{
    public function test_definitions_are_non_empty(): void
    {
        $this->assertNotEmpty(cdcf_mcp_ability_definitions());
    }

    public function test_every_definition_has_required_keys(): void
    {
        foreach (cdcf_mcp_ability_definitions() as $def) {
            foreach (['name', 'label', 'description', 'capability', 'callback', 'input_schema'] as $key) {
                $this->assertArrayHasKey($key, $def, "Missing '{$key}' in: " . ($def['name'] ?? '?'));
            }
        }
    }

    public function test_names_are_unique_and_namespaced(): void
    {
        $names = cdcf_mcp_ability_names();
        $this->assertSame($names, array_values(array_unique($names)), 'Duplicate ability name found.');
        foreach ($names as $name) {
            $this->assertStringStartsWith('cdcf/', $name);
        }
    }

    public function test_ability_names_matches_definition_order(): void
    {
        $fromDefs = array_map(static fn(array $d): string => $d['name'], cdcf_mcp_ability_definitions());
        $this->assertSame($fromDefs, cdcf_mcp_ability_names());
    }

    public function test_every_callback_is_callable(): void
    {
        foreach (cdcf_mcp_ability_definitions() as $def) {
            $this->assertTrue(
                function_exists($def['callback']),
                "Callback {$def['callback']} for {$def['name']} is not defined."
            );
        }
    }

    public function test_input_schemas_are_object_typed(): void
    {
        foreach (cdcf_mcp_ability_definitions() as $def) {
            $this->assertSame('object', $def['input_schema']['type'] ?? null, "Bad schema for {$def['name']}");
            $this->assertArrayHasKey('properties', $def['input_schema']);
        }
    }

    public function test_required_props_exist_in_properties(): void
    {
        foreach (cdcf_mcp_ability_definitions() as $def) {
            $props    = array_keys($def['input_schema']['properties'] ?? []);
            $required = $def['input_schema']['required'] ?? [];
            foreach ($required as $req) {
                $this->assertContains($req, $props, "Required '{$req}' missing from properties of {$def['name']}");
            }
        }
    }

    public function test_capabilities_are_known_wp_caps(): void
    {
        $allowed = ['edit_posts', 'edit_pages', 'delete_posts', 'upload_files'];
        foreach (cdcf_mcp_ability_definitions() as $def) {
            $this->assertContains($def['capability'], $allowed, "Unexpected capability for {$def['name']}");
        }
    }

    public function test_academic_liaison_requires_collab_post_id(): void
    {
        $defs = [];
        foreach (cdcf_mcp_ability_definitions() as $def) {
            $defs[$def['name']] = $def;
        }
        $this->assertArrayHasKey('cdcf/create-academic-liaison', $defs);
        $this->assertContains('collab_post_id', $defs['cdcf/create-academic-liaison']['input_schema']['required']);
    }
}
