<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Brain Monkey lazy-loads Patchwork on the first Monkey\setUp() call,
// but Patchwork can only instrument files loaded AFTER it. Load it
// here so it instruments includes/handlers.php below.
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';

// Brain Monkey teardown is per-test in setUp/tearDown; nothing to do here.

// Define ABSPATH so handlers.php (which guards with `defined('ABSPATH') || exit;`)
// loads cleanly under PHPUnit.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Define minimal WP constants/classes the production code touches at
// load time, so requiring handlers.php doesn't blow up before Brain
// Monkey gets a chance to stub.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public mixed $data;
        public int $status;

        public function __construct(mixed $data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code;
        public string $message;
        public array $data;

        public function __construct(string $code = '', string $message = '', array $data = []) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(): array {
            return $this->data;
        }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess {
        private array $params = [];

        public function set_param(string $k, mixed $v): void {
            $this->params[$k] = $v;
        }

        public function get_param(string $k): mixed {
            return $this->params[$k] ?? null;
        }

        public function offsetExists($k): bool {
            return isset($this->params[$k]);
        }

        public function offsetGet($k): mixed {
            return $this->params[$k] ?? null;
        }

        public function offsetSet($k, $v): void {
            $this->params[$k] = $v;
        }

        public function offsetUnset($k): void {
            unset($this->params[$k]);
        }
    }
}

require_once __DIR__ . '/../includes/handlers.php';

// Stub the upstream Abstract_Base_Job that CDCF_Translation_Job extends.
// Must be loaded BEFORE class-translation-job.php so the class can resolve
// its parent at class-load time.
require_once __DIR__ . '/stubs/AbstractBaseJobStub.php';

// class-translation-job.php calls add_filter() at file scope to register
// the deserializer. Provide a no-op stub at load time so the require
// doesn't blow up before Brain Monkey can intercept calls per-test.
if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        return true;
    }
}

require_once __DIR__ . '/../includes/class-translation-job.php';
