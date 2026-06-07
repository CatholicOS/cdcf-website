<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the translation-status meta layer
 * (includes/translation-status.php) and its REST endpoint
 * GET /cdcf/v1/translation-status (includes/handlers/translation-status.php).
 *
 * The helper is consulted by:
 *   - cdcf_enqueue_post_translation() (sets 'enqueued')
 *   - cdcf_process_translation()      (sets 'processing'/'completed'/'failed')
 *
 * And read by the meta-box JS via the GET endpoint so the "Queued" badge
 * can flip to "Done" or "Failed" without a page reload.
 */
final class TranslationStatusTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $metaStore = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->metaStore = [];

        // Minimal post_meta store backed by an array so the tests can
        // assert what the helpers actually wrote without booting WP.
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value): bool {
                $this->metaStore[(string) $post_id][$key] = $value;
                return true;
            }
        );
        Functions\when('delete_post_meta')->alias(
            function (int $post_id, string $key): bool {
                unset($this->metaStore[(string) $post_id][$key]);
                return true;
            }
        );
        Functions\when('get_post_meta')->alias(
            function (int $post_id, string $key, bool $single = true) {
                return $this->metaStore[(string) $post_id][$key] ?? '';
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ─── Helper layer ────────────────────────────────────────────────

    public function test_set_enqueued_writes_status_and_clears_prior_state(): void
    {
        // Pre-seed a stale completed+error state from a previous attempt
        // so we can prove the helper wipes them on a fresh enqueue (the
        // UI relies on this to avoid showing a stale error on retry).
        $this->metaStore['42'] = [
            CDCF_TRANSLATION_STATUS_META_KEY       => 'failed',
            CDCF_TRANSLATION_COMPLETED_AT_META_KEY => 1234567,
            CDCF_TRANSLATION_ERROR_META_KEY        => 'old failure',
        ];

        cdcf_translation_status_set_enqueued(42);

        $this->assertSame('enqueued', $this->metaStore['42'][CDCF_TRANSLATION_STATUS_META_KEY]);
        $this->assertArrayNotHasKey(CDCF_TRANSLATION_COMPLETED_AT_META_KEY, $this->metaStore['42']);
        $this->assertArrayNotHasKey(CDCF_TRANSLATION_ERROR_META_KEY, $this->metaStore['42']);
    }

    public function test_set_processing_clears_error_but_keeps_completed_at(): void
    {
        $this->metaStore['42'] = [
            CDCF_TRANSLATION_COMPLETED_AT_META_KEY => 1234567,
            CDCF_TRANSLATION_ERROR_META_KEY        => 'old failure',
        ];

        cdcf_translation_status_set_processing(42);

        $this->assertSame('processing', $this->metaStore['42'][CDCF_TRANSLATION_STATUS_META_KEY]);
        // completed_at intentionally preserved — the UI shows "last
        // successful translation at …" alongside a re-translation in
        // progress, so wiping it on the processing transition would
        // briefly blank that label.
        $this->assertSame(1234567, $this->metaStore['42'][CDCF_TRANSLATION_COMPLETED_AT_META_KEY]);
        $this->assertArrayNotHasKey(CDCF_TRANSLATION_ERROR_META_KEY, $this->metaStore['42']);
    }

    public function test_set_completed_writes_status_and_stamps_time(): void
    {
        $before = time();
        cdcf_translation_status_set_completed(42);
        $after = time();

        $this->assertSame('completed', $this->metaStore['42'][CDCF_TRANSLATION_STATUS_META_KEY]);
        $stamp = $this->metaStore['42'][CDCF_TRANSLATION_COMPLETED_AT_META_KEY];
        $this->assertIsInt($stamp);
        $this->assertGreaterThanOrEqual($before, $stamp);
        $this->assertLessThanOrEqual($after, $stamp);
    }

    public function test_set_failed_writes_status_and_truncates_long_errors(): void
    {
        $longError = str_repeat('x', 800);
        cdcf_translation_status_set_failed(42, $longError);

        $this->assertSame('failed', $this->metaStore['42'][CDCF_TRANSLATION_STATUS_META_KEY]);
        $this->assertSame(500, mb_strlen($this->metaStore['42'][CDCF_TRANSLATION_ERROR_META_KEY]));
    }

    public function test_helpers_noop_on_invalid_post_id(): void
    {
        // 0 / negative IDs would otherwise write meta on post_id 0, which
        // WordPress treats as "default user options" — a footgun.
        cdcf_translation_status_set_enqueued(0);
        cdcf_translation_status_set_processing(-5);
        cdcf_translation_status_set_completed(0);
        cdcf_translation_status_set_failed(0, 'noop');

        $this->assertSame([], $this->metaStore);
    }

    public function test_get_returns_unknown_when_no_meta(): void
    {
        $status = cdcf_translation_status_get(42);

        // "unknown" — not "enqueued" — so the UI treats legacy posts that
        // never went through the new pipeline as "done" rather than as
        // perpetually-queued.
        $this->assertSame('unknown', $status['status']);
        $this->assertSame(0, $status['completed_at']);
        $this->assertSame('', $status['error']);
    }

    // ─── REST endpoint ───────────────────────────────────────────────

    public function test_endpoint_returns_400_when_post_id_is_zero(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 0]);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 0);

        $result = cdcf_rest_translation_status($req);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_post_id', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function test_endpoint_returns_404_when_post_does_not_exist(): void
    {
        Functions\when('get_post')->justReturn(null);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 999);

        $result = cdcf_rest_translation_status($req);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
        $this->assertSame(404, $result->get_error_data()['status']);
    }

    public function test_endpoint_returns_completed_payload_with_timestamp(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        // Drive the helper, then read through the endpoint — proves the
        // status meta written by the worker round-trips through the
        // REST response shape the UI expects.
        cdcf_translation_status_set_completed(42);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 42);

        $response = cdcf_rest_translation_status($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertSame(42, $data['post_id']);
        $this->assertSame('completed', $data['status']);
        $this->assertGreaterThan(0, $data['completed_at']);
        $this->assertSame('', $data['error']);
    }

    public function test_endpoint_returns_failed_payload_with_error_message(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        cdcf_translation_status_set_failed(42, 'OpenAI rate-limited');

        $req = new WP_REST_Request();
        $req->set_param('post_id', 42);

        $response = cdcf_rest_translation_status($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertSame('failed', $data['status']);
        $this->assertSame('OpenAI rate-limited', $data['error']);
        $this->assertSame(0, $data['completed_at']);
    }

    public function test_endpoint_returns_unknown_for_post_without_meta(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 42]);

        $req = new WP_REST_Request();
        $req->set_param('post_id', 42);

        $response = cdcf_rest_translation_status($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame('unknown', $response->get_data()['status']);
    }
}
