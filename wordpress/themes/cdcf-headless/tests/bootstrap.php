<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Brain Monkey lazy-loads Patchwork on the first Monkey\setUp() call,
// but Patchwork can only instrument files loaded AFTER it. Load it
// here so it instruments includes/handlers/relationship.php below.
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';

// Define ABSPATH so the handler file (which guards with
// `defined('ABSPATH') || exit;`) loads cleanly under PHPUnit.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Define minimal WP constants/classes the production code touches at
// load time, so requiring the handler file doesn't blow up before
// Brain Monkey gets a chance to stub.
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

// Path used by the disposable-domains handler. Redirect to the test
// tmp dir so the handler's file_put_contents / rename happen in
// scratch space rather than overwriting the real blocklist file. The
// path is process-scoped via getmypid() so parallel PHPUnit runs (CI
// matrices, paratest, multiple devs on the same machine) and the
// existing #[RunInSeparateProcess] test's forked child don't collide
// on the same tmp file.
if (!defined('CDCF_DISPOSABLE_DOMAINS_FILE')) {
    define(
        'CDCF_DISPOSABLE_DOMAINS_FILE',
        sys_get_temp_dir() . '/cdcf-test-disposable-domains-' . getmypid() . '.txt'
    );
}

require_once __DIR__ . '/../includes/handlers/relationship.php';
require_once __DIR__ . '/../includes/handlers/team-member.php';
require_once __DIR__ . '/../includes/handlers/community-channel.php';
require_once __DIR__ . '/../includes/handlers/local-group.php';
require_once __DIR__ . '/../includes/handlers/academic-collaboration.php';
require_once __DIR__ . '/../includes/handlers/update-disposable-domains.php';
require_once __DIR__ . '/../includes/handlers/translate.php';

// Shared base class for the three Community-page handler tests. PHPUnit
// doesn't autoload test files via PSR-4, so concrete subclasses can only
// find their parent if it's been required up-front.
require_once __DIR__ . '/CommunityHandlerTestBase.php';
