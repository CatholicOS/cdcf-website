<?php

/**
 * Enqueue a translation job via Redis Queue (preferred) or WP Cron (fallback).
 *
 * @param int    $post_id     Target post ID.
 * @param int    $source_id   Source post ID to translate from.
 * @param string $target_lang Target language slug (e.g. 'it', 'fr').
 * @return string 'redis' or 'wp-cron' indicating which queue was used.
 */
function cdcf_enqueue_translation(int $post_id, int $source_id, string $target_lang): string {
    if (function_exists('redis_queue')) {
        try {
            $job = new CDCF_Translation_Job([
                'post_id'     => $post_id,
                'source_id'   => $source_id,
                'target_lang' => $target_lang,
            ]);
            redis_queue()->queue_manager->enqueue($job);
            return 'redis';
        } catch (\Throwable $e) {
            error_log('cdcf_enqueue_translation: Redis enqueue failed, falling back to WP Cron – ' . $e->getMessage());
        }
    }

    wp_schedule_single_event(time(), 'cdcf_async_translate', [$post_id, $source_id, $target_lang]);
    spawn_cron();
    return 'wp-cron';
}
