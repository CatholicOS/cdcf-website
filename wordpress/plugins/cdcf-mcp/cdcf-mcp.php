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

// Register the ability category and abilities on their respective core hooks
// (WP 6.9+ gives categories and abilities separate init actions; the category
// must exist before the abilities that reference it, or core drops them).
add_action('wp_abilities_api_categories_init', 'cdcf_mcp_register_category');
add_action('wp_abilities_api_init', 'cdcf_mcp_register_abilities');

// Boot the MCP adapter. It is pulled in as a Composer library with PSR-4-only
// autoloading, so its own plugin entry file (which calls Plugin::instance())
// never runs — we must boot it explicitly. Plugin::instance() →
// McpAdapter::instance(), which fires `mcp_adapter_init` on rest_api_init
// (web) / init (WP-CLI), where includes/server.php creates the cdcf-mcp
// server. Without this the abilities still register but aren't served over MCP.
if (class_exists('\\WP\\MCP\\Plugin')) {
    \WP\MCP\Plugin::instance();
}
