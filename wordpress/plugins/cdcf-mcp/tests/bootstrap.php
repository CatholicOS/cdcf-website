<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Load Patchwork before the production code so Brain Monkey can
// instrument it (mirrors the cdcf-redis-translations test setup).
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WP test doubles the production code touches at load time or
// constructs directly (rather than via a stubbed function).
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

// WP_REST_Request double: cdcf_mcp_dispatch() constructs this with a
// (method, route) pair and sets params; tests read them back to assert
// what was dispatched.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess {
        private array $params = [];
        public string $method;
        public string $route;

        public function __construct(string $method = 'GET', string $route = '') {
            $this->method = $method;
            $this->route  = $route;
        }

        public function get_method(): string {
            return $this->method;
        }

        public function get_route(): string {
            return $this->route;
        }

        public function set_param(string $k, mixed $v): void {
            $this->params[$k] = $v;
        }

        public function get_param(string $k): mixed {
            return $this->params[$k] ?? null;
        }

        public function get_params(): array {
            return $this->params;
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

// WP_REST_Response double exposing the subset cdcf_mcp_dispatch() reads
// off the rest_do_request() return value.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public mixed $data;
        public int $status;
        private bool $error;

        public function __construct(mixed $data = null, int $status = 200, bool $error = false) {
            $this->data   = $data;
            $this->status = $status;
            $this->error  = $error;
        }

        public function is_error(): bool {
            return $this->error;
        }

        public function as_error(): mixed {
            return $this->data;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

require_once __DIR__ . '/../includes/abilities.php';
