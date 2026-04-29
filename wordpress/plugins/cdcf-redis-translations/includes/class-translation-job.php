<?php

use Soderlind\RedisQueue\Jobs\Abstract_Base_Job;

class CDCF_Translation_Job extends Abstract_Base_Job {

    protected int $timeout = 180;
    protected int $retry_attempts = 2;
    protected array $retry_backoff = [30, 120];

    public function get_job_type() {
        return 'cdcf_translation';
    }

    public function execute() {
        $post_id     = $this->get_payload_value('post_id');
        $source_id   = $this->get_payload_value('source_id');
        $target_lang = $this->get_payload_value('target_lang');

        if (!$post_id || !$source_id || !$target_lang) {
            throw new \RuntimeException('Missing required payload fields: post_id, source_id, target_lang');
        }

        if (!function_exists('cdcf_process_translation')) {
            throw new \RuntimeException('cdcf_process_translation() not available — is cdcf-headless theme active?');
        }

        $result = cdcf_process_translation($post_id, $source_id, $target_lang);

        // Surface WP_Error so redis-queue's retry_attempts/retry_backoff engages.
        // Without throwing, OpenAI timeouts silently mark the job successful and
        // the translation post is never updated.
        if (is_wp_error($result) === true) {
            throw new \RuntimeException(sprintf(
                'cdcf_process_translation failed for post %d (%s): %s',
                (int) $post_id,
                esc_html((string) $target_lang),
                esc_html($result->get_error_message())
            ));
        }

        return $this->success([
            'post_id'     => $post_id,
            'target_lang' => $target_lang,
        ]);
    }
}

// Register the job type so redis-queue can deserialize it.
add_filter('redis_queue_create_job', function ($job, $type, $payload) {
    if ($type === 'cdcf_translation') {
        return new CDCF_Translation_Job($payload);
    }
    return $job;
}, 10, 3);
