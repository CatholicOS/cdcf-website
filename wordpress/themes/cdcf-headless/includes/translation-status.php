<?php
/**
 * Translation-status meta layer.
 *
 * Records the lifecycle of a queued translation on the target post so the
 * meta-box UI can poll for completion (rather than leaving the badge stuck
 * at "Queued" until the user reloads — the original behaviour after PR #156
 * flipped the meta-box from synchronous OpenAI calls to enqueue-and-return).
 *
 * Lifecycle written by callers:
 *   enqueued  → cdcf_enqueue_post_translation()      (translate.php)
 *   processing/completed/failed → cdcf_process_translation()  (translation.php)
 *
 * Exposed read-side via GET /cdcf/v1/translation-status (translation-status.php).
 *
 * Meta keys are leading-underscore so they don't surface in the Custom
 * Fields metabox and are excluded from the default REST `meta` response.
 */

defined('ABSPATH') || exit;

const CDCF_TRANSLATION_STATUS_META_KEY       = '_cdcf_translation_status';
const CDCF_TRANSLATION_COMPLETED_AT_META_KEY = '_cdcf_translation_completed_at';
const CDCF_TRANSLATION_ERROR_META_KEY        = '_cdcf_translation_error';

/**
 * Mark the post as queued for translation. Clears any prior error/completion
 * so a re-translation shows a clean state to the polling UI.
 */
function cdcf_translation_status_set_enqueued(int $post_id): void {
    if ($post_id <= 0) {
        return;
    }
    update_post_meta($post_id, CDCF_TRANSLATION_STATUS_META_KEY, 'enqueued');
    delete_post_meta($post_id, CDCF_TRANSLATION_COMPLETED_AT_META_KEY);
    delete_post_meta($post_id, CDCF_TRANSLATION_ERROR_META_KEY);
}

/**
 * Mark the post as currently being translated by the worker.
 */
function cdcf_translation_status_set_processing(int $post_id): void {
    if ($post_id <= 0) {
        return;
    }
    update_post_meta($post_id, CDCF_TRANSLATION_STATUS_META_KEY, 'processing');
    delete_post_meta($post_id, CDCF_TRANSLATION_ERROR_META_KEY);
}

/**
 * Mark the translation as successfully completed and stamp the time.
 */
function cdcf_translation_status_set_completed(int $post_id): void {
    if ($post_id <= 0) {
        return;
    }
    update_post_meta($post_id, CDCF_TRANSLATION_STATUS_META_KEY, 'completed');
    update_post_meta($post_id, CDCF_TRANSLATION_COMPLETED_AT_META_KEY, time());
    delete_post_meta($post_id, CDCF_TRANSLATION_ERROR_META_KEY);
}

/**
 * Mark the translation as failed and record a short error message so the UI
 * can surface it. Message is truncated to keep meta rows bounded.
 */
function cdcf_translation_status_set_failed(int $post_id, string $error): void {
    if ($post_id <= 0) {
        return;
    }
    update_post_meta($post_id, CDCF_TRANSLATION_STATUS_META_KEY, 'failed');
    update_post_meta(
        $post_id,
        CDCF_TRANSLATION_ERROR_META_KEY,
        mb_substr($error, 0, 500)
    );
}

/**
 * Read the current translation status for a post. Returns a normalized array
 * the REST endpoint can hand straight back to the UI.
 *
 * @return array{status:string,completed_at:int,error:string}
 */
function cdcf_translation_status_get(int $post_id): array {
    $status       = (string) get_post_meta($post_id, CDCF_TRANSLATION_STATUS_META_KEY, true);
    $completed_at = (int)    get_post_meta($post_id, CDCF_TRANSLATION_COMPLETED_AT_META_KEY, true);
    $error        = (string) get_post_meta($post_id, CDCF_TRANSLATION_ERROR_META_KEY, true);

    return [
        // "unknown" means: this post predates the status-meta feature or was
        // never enqueued through cdcf_enqueue_post_translation(). The UI
        // treats it the same as "completed" (no spinner) so legacy posts
        // don't get stuck on a "Queued" badge forever.
        'status'       => $status !== '' ? $status : 'unknown',
        'completed_at' => $completed_at,
        'error'        => $error,
    ];
}
