<?php
/**
 * Expose the CDCF abilities as an MCP server via the WordPress MCP
 * adapter (wordpress/mcp-adapter).
 *
 * The `mcp_adapter_init` action only fires when the adapter is loaded, so
 * this registration is inert if the adapter is not installed — the
 * abilities themselves remain usable through the Abilities API regardless.
 *
 * Server endpoint (HTTP transport): /wp-json/cdcf-mcp/mcp
 */

if (defined('ABSPATH') === false) {
    return;
}

require_once __DIR__ . '/abilities.php';

add_action('mcp_adapter_init', function ($adapter) {
    // Guard against API drift in this pre-1.0 dependency: only the
    // create_server() call below is version-sensitive, so bail clearly if
    // the expected method or transport class is missing.
    if (!is_object($adapter) || !method_exists($adapter, 'create_server')) {
        return;
    }

    $transports = [];
    if (class_exists('\\WP\\MCP\\Transport\\HttpTransport')) {
        $transports[] = '\\WP\\MCP\\Transport\\HttpTransport';
    }
    if (empty($transports)) {
        return;
    }

    $error_handler = class_exists('\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler')
        ? '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler'
        : null;
    $observability = class_exists('\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler')
        ? '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler'
        : null;

    $adapter->create_server(
        'cdcf-mcp',
        'cdcf-mcp',
        'mcp',
        'CDCF Content MCP Server',
        'Drafting and content-management tools for the Catholic Digital Commons Foundation website.',
        'v0.1.0',
        $transports,
        $error_handler,
        $observability,
        cdcf_mcp_ability_names(),
        [],
        []
    );
});
