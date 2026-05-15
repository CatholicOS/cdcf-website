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

        abstract public function get_job_type();

        abstract public function execute();
    }
}
