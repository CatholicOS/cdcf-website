<?php
/**
 * Shared sanitize_callback helpers for REST route arg declarations.
 *
 * Per the convention documented in CLAUDE.md (settled in #111):
 *   - Sanitization happens ONCE at register_rest_route() args time.
 *   - Handlers trust the sanitized input and do NOT re-sanitize.
 *   - Structural validation + contextual WP_Error returns stay in the
 *     handler body (e.g. count-checks, existence-checks).
 *
 * Functions are top-level (callable by name from sanitize_callback).
 */

defined('ABSPATH') || exit;

/**
 * Sanitize the {lang => id} translations map used by both
 * /cdcf/v1/link-translations (posts) and /cdcf/v1/link-term-translations
 * (terms). Coerces to an associative array of language-code keys to
 * positive integer IDs; silently drops entries whose keys are not a
 * recognized language-code shape (`/^[a-z]{2}(-[A-Z]{2})?$/`, covering
 * ISO 639-1 alone plus optional ISO 3166-1 region — matches the
 * Polylang slug shape the rest of the site uses), and entries whose
 * IDs are zero or non-numeric after absint(). The handler is then
 * responsible for the count >= 2 structural check and the per-id
 * existence check, both of which return contextual WP_Error.
 *
 * @param  mixed $value  Whatever the client sent (usually a
 *                       JSON-decoded associative array; WP REST
 *                       framework leaves objects as arrays).
 * @return array<string, int>  Empty array on no valid entries.
 */
function cdcf_sanitize_translations_map($value): array {
    if (!is_array($value)) {
        return [];
    }
    $sanitized = [];
    foreach ($value as $lang => $id) {
        if (!is_string($lang) || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang)) {
            continue;
        }
        $id = absint($id);
        if ($id > 0) {
            $sanitized[$lang] = $id;
        }
    }
    return $sanitized;
}
