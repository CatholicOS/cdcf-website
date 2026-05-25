<?php
/**
 * Plugin Name: CDCF MCP
 * Description: Exposes CDCF content-management operations (members, councils, projects, collaborations, pages, posts, media) as WordPress Abilities and serves them over the Model Context Protocol via the WordPress MCP adapter.
 * Version:     0.1.0
 * Author:      Catholic Digital Commons Foundation
 * Requires PHP: 8.1
 *
 * PROTOTYPE — see docs/wordpress-mcp-evaluation.md. Activate only behind
 * authentication and ideally against a role-limited bot user.
 */

defined('ABSPATH') || exit;

// Composer autoloader (wordpress/mcp-adapter + php-mcp-schema). Optional:
// the abilities still register without it; only the MCP server transport
// needs the adapter classes.
$cdcf_mcp_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($cdcf_mcp_autoload)) {
    require_once $cdcf_mcp_autoload;
}

require_once __DIR__ . '/includes/abilities.php';
require_once __DIR__ . '/includes/server.php';

// Register abilities once the Abilities API has booted (core in WP 6.9+).
add_action('wp_abilities_api_init', 'cdcf_mcp_register_abilities');
