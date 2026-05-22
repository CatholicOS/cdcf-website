<?php
/**
 * REST route handler for /cdcf/v1/update-disposable-domains.
 *
 * Extracted from functions.php so the body can be unit-tested with
 * Brain Monkey + Mockery. The target file path is read from the
 * CDCF_DISPOSABLE_DOMAINS_FILE constant (defined in functions.php
 * pointing at the theme directory) so tests can redirect writes to
 * a tmp path via the test bootstrap.
 */

if (defined('ABSPATH') === false) {
    return;
}

function cdcf_rest_update_disposable_domains(WP_REST_Request $request) {
    $url  = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf';
    $file = CDCF_DISPOSABLE_DOMAINS_FILE;

    $response = wp_remote_get($url, ['timeout' => 30]);
    if (is_wp_error($response)) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => $response->get_error_message(),
        ], 502);
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => "GitHub returned HTTP {$code}",
        ], 502);
    }

    $body    = wp_remote_retrieve_body($response);
    $domains = array_filter(array_map('trim', explode("\n", $body)));
    $count   = count($domains);

    if ($count < 100) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => "Downloaded list suspiciously small ({$count} domains), aborting.",
        ], 422);
    }

    $tmp = $file . '.tmp.' . getmypid();
    $written = file_put_contents($tmp, $body);
    if ($written === false) {
        // file_put_contents may or may not have created the file
        // depending on where it failed; guard the cleanup explicitly
        // so unlink doesn't warn on a missing path.
        if (file_exists($tmp)) {
            unlink($tmp);
        }
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'Failed to write temp file',
        ], 500);
    }

    // Flush to disk before renaming. Best-effort: fopen mode must allow
    // write access ('r+' rather than 'r') so fsync has a syncable stream
    // — on PHP 8.4 fsync() on a read-only handle emits a runtime warning.
    // Filesystems that don't support fsync at all (e.g. tmpfs / WSL
    // drvfs in dev) also emit a warning; scope a no-op error handler
    // around the call so durability degrades gracefully to "whatever
    // the OS decides to flush" without polluting stderr.
    $fh = fopen($tmp, 'r+');
    if ($fh) {
        set_error_handler(static fn (): bool => true);
        fsync($fh);
        restore_error_handler();
        fclose($fh);
    }

    if (!rename($tmp, $file)) {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'Failed to rename temp file to disposable-domains.txt',
        ], 500);
    }

    return rest_ensure_response([
        'success' => true,
        'domains' => $count,
        'bytes'   => $written,
    ]);
}
