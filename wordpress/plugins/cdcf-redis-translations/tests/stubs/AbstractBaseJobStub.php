<?php

declare(strict_types=1);

// Test-only stub for Soderlind\RedisQueue\Jobs\Abstract_Base_Job,
// which CDCF_Translation_Job extends. The real class lives in the
// redis-queue plugin and isn't available to this composer tree.

namespace Soderlind\RedisQueue\Jobs;

if (!class_exists(Abstract_Base_Job::class)) {
    abstract class Abstract_Base_Job
    {
        protected array $payload;

        protected int $retry_attempts = 3;

        protected array $retry_backoff = [60, 300, 900];

        public function __construct(array $payload = [])
        {
            $this->payload = $payload;
        }

        public function get_payload_value(string $key): mixed
        {
            return $this->payload[$key] ?? null;
        }

        public function get_payload(): array
        {
            return $this->payload;
        }

        protected function success(array $data = []): array
        {
            return ['success' => true, 'data' => $data];
        }

        // Mirrors upstream's limit check and backoff lookup; the filters
        // and non-retryable exception list are left out.
        public function should_retry($exception, $attempt)
        {
            return $attempt < $this->retry_attempts;
        }

        public function get_retry_delay($attempt)
        {
            return $this->retry_backoff[$attempt - 1] ?? min(2 ** $attempt * 60, 3600);
        }

        abstract public function get_job_type();

        abstract public function execute();
    }
}
