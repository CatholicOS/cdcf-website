<?php
/**
 * Test-only stub for Soderlind\RedisQueue\Jobs\Abstract_Base_Job, which
 * the review email job extends. Mirrors the plugin's own stub at
 * wordpress/plugins/cdcf-redis-translations/tests/stubs/AbstractBaseJobStub.php.
 */

namespace Soderlind\RedisQueue\Jobs;

if (!class_exists(Abstract_Base_Job::class)) {
    abstract class Abstract_Base_Job
    {
        public function __construct(protected array $payload = []) {}

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
