<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/project-status handler.
 */
final class ProjectStatusHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    private function makeRequest(array $params): WP_REST_Request
    {
        $req = new WP_REST_Request();
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_returns_500_when_acf_inactive(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'update_field'
        );

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'active'])
        );

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('acf_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_400_when_status_not_in_allowlist(): void
    {
        Functions\when('update_field')->justReturn(true);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'launched'])
        );

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_status', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
        $this->assertStringContainsString('incubating, active, archived', $response->get_error_message());
    }

    public function test_returns_404_when_post_does_not_exist(): void
    {
        Functions\when('update_field')->justReturn(true);
        Functions\when('get_post')->justReturn(null);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 99999, 'status' => 'active'])
        );

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_post', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_returns_404_when_post_is_not_a_project(): void
    {
        Functions\when('update_field')->justReturn(true);
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'page']);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'active'])
        );

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_post', $response->get_error_code());
    }

    public function test_updates_only_given_post_when_polylang_inactive(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'project']);
        // Returning the previous status (anything != target) forces the
        // update_field branch rather than the "unchanged" short-circuit.
        Functions\when('get_field')->justReturn('incubating');

        $writes = [];
        Functions\when('update_field')->alias(
            function (string $field, string $value, int $post_id) use (&$writes): bool {
                $writes[] = [$field, $value, $post_id];
                return true;
            }
        );

        // update_field exists, pll_get_post_translations does NOT.
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_get_post_translations'
        );

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'active'])
        );

        $this->assertSame([['project_status', 'active', 10]], $writes);
        $this->assertSame([10], $response['updated_posts']);
        $this->assertSame([], $response['unchanged_posts']);
        $this->assertSame([], $response['failed_posts']);
        $this->assertTrue($response['success']);
    }

    public function test_updates_every_translation_when_polylang_active(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'project']);
        Functions\when('get_field')->justReturn('incubating');
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 10, 'it' => 11, 'es' => 12, 'fr' => 13, 'pt' => 14, 'de' => 15,
        ]);

        $writes = [];
        Functions\when('update_field')->alias(
            function (string $field, string $value, int $post_id) use (&$writes): bool {
                $writes[] = $post_id;
                return true;
            }
        );

        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'archived'])
        );

        $this->assertSame([10, 11, 12, 13, 14, 15], $writes);
        $this->assertSame('archived', $response['status']);
        $this->assertSame([10, 11, 12, 13, 14, 15], $response['updated_posts']);
        $this->assertTrue($response['success']);
    }

    public function test_appends_post_id_when_polylang_returns_no_translation_for_it(): void
    {
        // Defensive branch: pll_get_post_translations may legitimately
        // not include the given post_id (e.g. post not yet linked). The
        // handler must still update the original post.
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'project']);
        Functions\when('get_field')->justReturn('incubating');
        Functions\when('pll_get_post_translations')->justReturn(['it' => 11, 'es' => 12]);

        $writes = [];
        Functions\when('update_field')->alias(
            function (string $field, string $value, int $post_id) use (&$writes): bool {
                $writes[] = $post_id;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'incubating'])
        );

        // get_field returns 'incubating' (the target) for every pid → every
        // post short-circuits into unchanged_posts; no update_field calls.
        $this->assertSame([], $writes);
        $this->assertSame([11, 12, 10], $response['unchanged_posts']);
        $this->assertTrue($response['success']);
    }

    // ─── Write-failure / unchanged branches (issue #109) ──────────────

    public function test_already_at_target_status_goes_into_unchanged_posts(): void
    {
        // get_field returns the target status for every pid → no
        // update_field call should happen; every pid lands in unchanged.
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'project']);
        Functions\when('get_field')->justReturn('active');
        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'it' => 11]);
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'active'])
        );

        $this->assertSame([], $response['updated_posts']);
        $this->assertSame([10, 11], $response['unchanged_posts']);
        $this->assertSame([], $response['failed_posts']);
        // No failures → success is still true even though nothing was written.
        $this->assertTrue($response['success']);
    }

    public function test_update_field_returning_false_records_failed_post(): void
    {
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'project']);
        Functions\when('get_field')->justReturn('incubating');
        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'it' => 11]);
        // Every update_field call returns false: simulates a real ACF
        // persistence failure (DB lock, post-meta misconfiguration, etc.).
        Functions\when('update_field')->justReturn(false);
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'active'])
        );

        $this->assertFalse($response['success']);
        $this->assertSame([], $response['updated_posts']);
        $this->assertSame([], $response['unchanged_posts']);
        $this->assertCount(2, $response['failed_posts']);
        $this->assertSame(
            ['post_id' => 10, 'reason' => 'update_field returned false'],
            $response['failed_posts'][0]
        );
        $this->assertSame(11, $response['failed_posts'][1]['post_id']);
    }

    public function test_mixed_outcomes_partition_posts_correctly(): void
    {
        // Three posts: one already at target (→ unchanged), one writes
        // successfully (→ updated), one fails (→ failed). The structured
        // response lets the client see all three independently.
        Functions\when('rest_ensure_response')->returnArg(1);
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'project']);
        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'it' => 11, 'es' => 12]);
        Functions\when('get_field')->alias(
            static fn(string $field, int $pid): string => $pid === 11 ? 'active' : 'incubating'
        );
        Functions\when('update_field')->alias(
            static fn(string $field, string $value, int $pid): bool => $pid !== 12
        );
        $this->allowAllFunctionsToExist();

        $response = cdcf_rest_update_project_status(
            $this->makeRequest(['post_id' => 10, 'status' => 'active'])
        );

        $this->assertSame([10], $response['updated_posts']);
        $this->assertSame([11], $response['unchanged_posts']);
        $this->assertSame(
            [['post_id' => 12, 'reason' => 'update_field returned false']],
            $response['failed_posts']
        );
        // Mixed results: one failure → success is false (clients can still
        // see what succeeded by inspecting updated_posts).
        $this->assertFalse($response['success']);
    }
}
