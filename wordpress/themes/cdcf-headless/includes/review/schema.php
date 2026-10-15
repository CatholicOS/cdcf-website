<?php
/**
 * Council review of submitted projects — storage schema.
 *
 * Deliberations live in custom tables (not comments, not a CPT) so no
 * core, WPGraphQL or Polylang surface can expose them, including after
 * the project is published. See
 * docs/superpowers/specs/2026-10-15-project-review-design.md.
 *
 *   cdcf_review_entries        reviews, replies, discussion, submitter Q&A
 *   cdcf_review_attachments    files attached to entries (used from Phase 2)
 *   cdcf_review_events         activity log behind the bell and emails
 *   cdcf_review_notifications  one row per (event, recipient) with read state
 *
 * One review per reviewer per project is enforced by the unique key on
 * (project_id, review_slot): review_slot carries the author id on
 * `review` rows and NULL on every other kind (MySQL allows many NULLs).
 */

defined('ABSPATH') || exit;

const CDCF_REVIEW_DB_VERSION  = '1';
const CDCF_REVIEW_STAGE_META  = '_cdcf_review_stage';
const CDCF_REVIEW_STAGES      = ['in_review', 'awaiting_submitter', 'decided'];
const CDCF_REVIEW_VOTES       = ['cdcf', 'community', 'reject'];
const CDCF_REVIEW_BODY_MAX    = 50000;

function cdcf_review_table(string $name): string {
    global $wpdb;
    return $wpdb->prefix . 'cdcf_review_' . $name;
}

/**
 * dbDelta-compatible CREATE TABLE statements (two spaces after
 * PRIMARY KEY, one column per line, no backticks).
 *
 * @return list<string>
 */
function cdcf_review_schema_sql(string $charset_collate): array {
    $entries       = cdcf_review_table('entries');
    $attachments   = cdcf_review_table('attachments');
    $events        = cdcf_review_table('events');
    $notifications = cdcf_review_table('notifications');

    return [
        "CREATE TABLE {$entries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  author_user_id bigint(20) unsigned DEFAULT NULL,
  review_slot bigint(20) unsigned DEFAULT NULL,
  body_md longtext NOT NULL,
  vote varchar(20) DEFAULT NULL,
  created_at datetime(6) NOT NULL,
  edited_at datetime(6) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id),
  UNIQUE KEY one_review (project_id,review_slot)
) {$charset_collate};",
        "CREATE TABLE {$attachments} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  kind varchar(10) NOT NULL,
  mime varchar(50) NOT NULL,
  original_name varchar(255) NOT NULL,
  storage_key char(64) NOT NULL,
  size int(10) unsigned NOT NULL,
  created_at datetime(6) NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id)
) {$charset_collate};",
        "CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  type varchar(30) NOT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  entry_id bigint(20) unsigned DEFAULT NULL,
  data text,
  created_at datetime(6) NOT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id)
) {$charset_collate};",
        "CREATE TABLE {$notifications} (
  event_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  read_at datetime(6) DEFAULT NULL,
  PRIMARY KEY  (event_id,user_id),
  KEY user_unread (user_id,read_at)
) {$charset_collate};",
    ];
}

/**
 * Create or upgrade the tables when the stored schema version differs,
 * then backfill the review stage onto submissions that predate this
 * feature. Hooked on admin_init and rest_api_init; the version option
 * is autoloaded, so the common path costs no query.
 */
function cdcf_review_maybe_upgrade(): void {
    if (get_option('cdcf_review_db_version') === CDCF_REVIEW_DB_VERSION) {
        return;
    }
    global $wpdb;
    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    foreach (cdcf_review_schema_sql($wpdb->get_charset_collate()) as $sql) {
        dbDelta($sql);
    }
    cdcf_review_backfill_stage();
    update_option('cdcf_review_db_version', CDCF_REVIEW_DB_VERSION);
}

/**
 * Put every pending public submission that has no review stage yet into
 * `in_review`. No email is sent; the submitter gets a status link with
 * the first committee email (Phase 3).
 */
function cdcf_review_backfill_stage(): int {
    $ids = get_posts([
        'post_type'      => 'project',
        'post_status'    => 'pending',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'lang'           => '', // all languages; Polylang would filter to the current one
        'meta_query'     => [
            ['key' => '_submission_submitter_email', 'compare' => 'EXISTS'],
            ['key' => CDCF_REVIEW_STAGE_META, 'compare' => 'NOT EXISTS'],
        ],
    ]);
    foreach ($ids as $id) {
        update_post_meta((int) $id, CDCF_REVIEW_STAGE_META, 'in_review');
    }
    return count($ids);
}

function cdcf_review_now(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
}
