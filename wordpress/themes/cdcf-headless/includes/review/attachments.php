<?php
/**
 * Council review — attachment files.
 *
 * Files never go through the Media Library: anything under
 * wp-content/uploads is publicly fetchable by URL. They are stored under
 * CDCF_REVIEW_STORAGE_DIR (outside the web root) when wp-config.php
 * defines it, otherwise under uploads/cdcf-review/ behind deny-all guard
 * files (production must also deny that path at the web server). Files
 * are named by a random 64-hex key and only ever served through the
 * authenticated GET /cdcf/v1/review/attachments/{id}.
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_ATTACHMENT_TYPES = [
    'md'   => ['kind' => 'markdown', 'mime' => 'text/markdown', 'max' => 524288],
    'png'  => ['kind' => 'image', 'mime' => 'image/png', 'max' => 5242880],
    'jpg'  => ['kind' => 'image', 'mime' => 'image/jpeg', 'max' => 5242880],
    'jpeg' => ['kind' => 'image', 'mime' => 'image/jpeg', 'max' => 5242880],
    'webp' => ['kind' => 'image', 'mime' => 'image/webp', 'max' => 5242880],
    'gif'  => ['kind' => 'image', 'mime' => 'image/gif', 'max' => 5242880],
];
const CDCF_REVIEW_ATTACHMENTS_PER_ENTRY = 10;

function cdcf_review_configured_storage_dir(): string {
    return defined('CDCF_REVIEW_STORAGE_DIR') ? (string) CDCF_REVIEW_STORAGE_DIR : '';
}

/** @return array{path: string, fallback: bool} */
function cdcf_review_storage_dir(): array {
    $configured = cdcf_review_configured_storage_dir();
    if ($configured !== '') {
        return ['path' => untrailingslashit($configured), 'fallback' => false];
    }
    $uploads = wp_upload_dir(null, false);
    return ['path' => untrailingslashit($uploads['basedir']) . '/cdcf-review', 'fallback' => true];
}

/** @return string|WP_Error The storage directory, created if missing. */
function cdcf_review_ensure_storage_dir() {
    $dir = cdcf_review_storage_dir();
    if (!wp_mkdir_p($dir['path'])) {
        return new WP_Error('storage_unavailable', 'The attachment storage directory cannot be created.', ['status' => 500]);
    }
    if ($dir['fallback']) {
        if (!file_exists($dir['path'] . '/.htaccess')) {
            file_put_contents($dir['path'] . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($dir['path'] . '/index.php')) {
            file_put_contents($dir['path'] . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }
    return $dir['path'];
}

/** @return array{kind: string, mime: string, size: int}|WP_Error */
function cdcf_review_validate_upload(string $path, string $original_name) {
    $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $type = CDCF_REVIEW_ATTACHMENT_TYPES[$ext] ?? null;
    if ($type === null) {
        return new WP_Error('unsupported_type', 'Only Markdown (.md) and PNG, JPEG, WebP or GIF images can be attached.', ['status' => 400]);
    }
    $size = (int) @filesize($path);
    if ($size <= 0) {
        return new WP_Error('empty_file', 'The file is empty.', ['status' => 400]);
    }
    if ($size > $type['max']) {
        return new WP_Error('file_too_large', 'The file is too large.', ['status' => 413]);
    }
    $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($type['kind'] === 'markdown') {
        if (!str_starts_with($sniffed, 'text/')) {
            return new WP_Error('type_mismatch', 'The file content does not match its extension.', ['status' => 400]);
        }
        if (!mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
            return new WP_Error('invalid_utf8', 'Markdown files must be UTF-8 text.', ['status' => 400]);
        }
    } elseif ($sniffed !== $type['mime']) {
        return new WP_Error('type_mismatch', 'The file content does not match its extension.', ['status' => 400]);
    }
    return ['kind' => $type['kind'], 'mime' => $type['mime'], 'size' => $size];
}

function cdcf_review_is_uploaded_file(string $path): bool {
    return is_uploaded_file($path);
}

/** @return string|WP_Error The new storage key. */
function cdcf_review_store_file(string $source_path) {
    $dir = cdcf_review_ensure_storage_dir();
    if (is_wp_error($dir)) {
        return $dir;
    }
    $key  = bin2hex(random_bytes(32));
    $dest = "{$dir}/{$key}";
    if (!@rename($source_path, $dest)) {
        if (!@copy($source_path, $dest)) {
            return new WP_Error('store_failed', 'The attachment could not be saved.', ['status' => 500]);
        }
        @unlink($source_path);
    }
    @chmod($dest, 0640);
    return $key;
}

function cdcf_review_normalize_attachment(array $row): array {
    foreach (['id', 'entry_id', 'size'] as $col) {
        $row[$col] = (int) $row[$col];
    }
    return $row;
}

function cdcf_review_insert_attachment(int $entry_id, array $meta, string $original_name, string $storage_key): int {
    global $wpdb;
    $ok = $wpdb->insert(cdcf_review_table('attachments'), [
        'entry_id'      => $entry_id,
        'kind'          => $meta['kind'],
        'mime'          => $meta['mime'],
        'original_name' => $original_name,
        'storage_key'   => $storage_key,
        'size'          => (int) $meta['size'],
        'created_at'    => cdcf_review_now(),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}

function cdcf_review_get_attachment(int $id): ?array {
    global $wpdb;
    $table = cdcf_review_table('attachments');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    return is_array($row) ? cdcf_review_normalize_attachment($row) : null;
}

function cdcf_review_count_attachments(int $entry_id): int {
    global $wpdb;
    $table = cdcf_review_table('attachments');
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE entry_id = %d", $entry_id));
}

/** @return array<int, list<array>> entry id => attachment rows, oldest first */
function cdcf_review_attachments_for_entries(array $entry_ids): array {
    if (!$entry_ids) {
        return [];
    }
    global $wpdb;
    $table = cdcf_review_table('attachments');
    $rows = $wpdb->get_results(
        "SELECT * FROM {$table} WHERE entry_id IN (" . cdcf_review_id_list($entry_ids) . ') ORDER BY id ASC',
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $row) {
        $row = cdcf_review_normalize_attachment($row);
        $out[$row['entry_id']][] = $row;
    }
    return $out;
}

function cdcf_review_attachment_path(array $attachment): string {
    return cdcf_review_storage_dir()['path'] . '/' . $attachment['storage_key'];
}

/** @return array{id: int, kind: string, mime: string, name: string, size: int} */
function cdcf_review_present_attachment(array $attachment): array {
    return [
        'id'   => (int) $attachment['id'],
        'kind' => (string) $attachment['kind'],
        'mime' => (string) $attachment['mime'],
        'name' => (string) $attachment['original_name'],
        'size' => (int) $attachment['size'],
    ];
}

function cdcf_review_markdown_filename(string $title): string {
    $slug = sanitize_title($title);
    return ($slug !== '' ? $slug : 'notes') . '.md';
}
