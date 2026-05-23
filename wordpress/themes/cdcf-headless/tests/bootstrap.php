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

// WP's time-unit constants the abuse-check helpers use in
// set_transient TTL arguments.
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS'))   define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS'))    define('DAY_IN_SECONDS', 86400);

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
if (!class_exists('WP_Query')) {
    class WP_Query {
        public bool $main_query = true;
        public array $vars = [];

        public function is_main_query(): bool {
            return $this->main_query;
        }

        public function get(string $key, $default = null): mixed {
            return $this->vars[$key] ?? $default;
        }

        public function set(string $key, $value): void {
            $this->vars[$key] = $value;
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

// The flush-opcache handler passes CDCF_FUNCTIONS_FILE to
// opcache_invalidate(). Define a dummy value so the
// "opcache-available" branch can be exercised in tests; the call
// itself is stubbed via Brain Monkey, so the value never reaches a
// real opcache call.
if (!defined('CDCF_FUNCTIONS_FILE')) {
    define('CDCF_FUNCTIONS_FILE', '/tmp/cdcf-test-functions.php');
}

// Constants the translation orchestrator consults — defined in
// functions.php in production but declared here for the unit tests
// since functions.php itself isn't loaded under PHPUnit.
if (!defined('CDCF_TRANSLATABLE_ACF_TYPES')) {
    define('CDCF_TRANSLATABLE_ACF_TYPES', ['text', 'textarea', 'wysiwyg']);
}
if (!defined('CDCF_LOCALE_NAMES')) {
    define('CDCF_LOCALE_NAMES', [
        'en' => 'English', 'it' => 'Italian', 'es' => 'Spanish',
        'fr' => 'French',  'pt' => 'Portuguese', 'de' => 'German',
    ]);
}

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/translation.php';
require_once __DIR__ . '/../includes/handlers/relationship.php';
require_once __DIR__ . '/../includes/handlers/team-member.php';
require_once __DIR__ . '/../includes/handlers/community-channel.php';
require_once __DIR__ . '/../includes/handlers/local-group.php';
require_once __DIR__ . '/../includes/handlers/academic-collaboration.php';
require_once __DIR__ . '/../includes/handlers/update-disposable-domains.php';
require_once __DIR__ . '/../includes/handlers/translate.php';
require_once __DIR__ . '/../includes/handlers/deploy-translation.php';
require_once __DIR__ . '/../includes/handlers/link-translations.php';
require_once __DIR__ . '/../includes/handlers/project-status.php';
require_once __DIR__ . '/../includes/handlers/flush-opcache.php';
require_once __DIR__ . '/../includes/handlers/send-verification-code.php';
require_once __DIR__ . '/../includes/handlers/refer-local-group.php';
require_once __DIR__ . '/../includes/handlers/refer-community-project.php';
require_once __DIR__ . '/../includes/handlers/submit-project-send-code.php';
require_once __DIR__ . '/../includes/handlers/submit-project.php';
require_once __DIR__ . '/../includes/admin/team-member-council.php';
require_once __DIR__ . '/../includes/admin/polylang-default-seed.php';

// Shared base classes. PHPUnit doesn't autoload test files via PSR-4,
// so concrete subclasses can only find their parent if it's been
// required up-front.
require_once __DIR__ . '/CommunityHandlerTestBase.php';
require_once __DIR__ . '/SendCodeHandlerTestBase.php';
require_once __DIR__ . '/SubmissionHandlerTestBase.php';
