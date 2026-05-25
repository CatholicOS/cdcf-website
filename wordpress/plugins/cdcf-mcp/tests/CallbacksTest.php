<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the ability execute-callbacks. The callbacks fall
 * into two families: thin wrappers that dispatch to an existing cdcf/v1
 * REST route (asserted by capturing the dispatched WP_REST_Request) and
 * direct WordPress operations (asserted via stubbed core functions).
 */
final class CallbacksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_create_board_member_forces_team_members_council(): void
    {
        $captured = null;
        Functions\when('rest_do_request')->alias(function ($req) use (&$captured) {
            $captured = $req;
            return new WP_REST_Response(['en_post_id' => 10, 'council' => 'team_members'], 202);
        });

        $out = cdcf_mcp_cb_create_board_member([
            'title'       => 'Jane Doe',
            'content'     => '<p>Bio</p>',
            'member_role' => '', // empty — must be filtered out, not sent
        ]);

        $this->assertInstanceOf(WP_REST_Request::class, $captured);
        $this->assertSame('POST', $captured->get_method());
        $this->assertSame('/cdcf/v1/team-member', $captured->get_route());

        $params = $captured->get_params();
        $this->assertSame('team_members', $params['council']);
        $this->assertSame('Jane Doe', $params['title']);
        $this->assertSame('<p>Bio</p>', $params['content']);
        $this->assertArrayNotHasKey('member_role', $params);

        $this->assertSame(10, $out['en_post_id']);
    }

    public function test_create_academic_liaison_requires_collab_post_id(): void
    {
        // No rest_do_request stub: the guard must short-circuit before any
        // dispatch, so reaching it would fatal on the undefined function.
        $out = cdcf_mcp_cb_create_academic_liaison([
            'title'   => 'Prof. Smith',
            'content' => '<p>Bio</p>',
        ]);

        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('missing_collab_post_id', $out->get_error_code());
        $this->assertSame(400, $out->get_error_data()['status']);
    }

    public function test_create_academic_liaison_dispatches_with_academic_council(): void
    {
        $captured = null;
        Functions\when('rest_do_request')->alias(function ($req) use (&$captured) {
            $captured = $req;
            return new WP_REST_Response(['en_post_id' => 42], 202);
        });

        cdcf_mcp_cb_create_academic_liaison([
            'title'          => 'Prof. Smith',
            'content'        => '<p>Bio</p>',
            'collab_post_id' => 7,
        ]);

        $params = $captured->get_params();
        $this->assertSame('academic_council', $params['council']);
        $this->assertSame(7, $params['collab_post_id']);
    }

    public function test_create_community_channel_dispatches_to_its_endpoint(): void
    {
        $captured = null;
        Functions\when('rest_do_request')->alias(function ($req) use (&$captured) {
            $captured = $req;
            return new WP_REST_Response(['en_post_id' => 50], 202);
        });

        $out = cdcf_mcp_cb_create_community_channel([
            'title'               => 'CDCF Discord',
            'channel_description' => 'Our community chat',
            'channel_url'         => 'https://discord.gg/cdcf',
            'channel_icon'        => 'discord',
            'group_url'           => 'ignored', // not whitelisted — must not pass through
        ]);

        $this->assertSame('POST', $captured->get_method());
        $this->assertSame('/cdcf/v1/community-channel', $captured->get_route());
        $params = $captured->get_params();
        $this->assertSame('CDCF Discord', $params['title']);
        $this->assertSame('https://discord.gg/cdcf', $params['channel_url']);
        $this->assertSame('discord', $params['channel_icon']);
        $this->assertArrayNotHasKey('group_url', $params);
        $this->assertSame(50, $out['en_post_id']);
    }

    public function test_create_local_group_dispatches_and_filters_empty_fields(): void
    {
        $captured = null;
        Functions\when('rest_do_request')->alias(function ($req) use (&$captured) {
            $captured = $req;
            return new WP_REST_Response(['en_post_id' => 51], 202);
        });

        cdcf_mcp_cb_create_local_group([
            'title'             => 'Rome Chapter',
            'group_description' => 'Local gatherings in Rome',
            'group_url'         => 'https://example.org/rome',
            'group_location'    => '', // empty — filtered out, not sent
        ]);

        $this->assertSame('/cdcf/v1/local-group', $captured->get_route());
        $params = $captured->get_params();
        $this->assertSame('Rome Chapter', $params['title']);
        $this->assertArrayNotHasKey('group_location', $params);
    }

    public function test_add_project_lead_appends_without_duplicating(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('is_wp_error')->alias(static fn($t) => $t instanceof WP_Error);

        $posted = null;
        Functions\when('rest_do_request')->alias(function ($req) use (&$posted) {
            if ($req->get_method() === 'GET') {
                return new WP_REST_Response(['value' => [5]]); // existing lead
            }
            $posted = $req;
            return new WP_REST_Response(['value' => $req->get_params()['value'], 'updated' => true]);
        });

        $out = cdcf_mcp_cb_add_project_lead([
            'project_id' => 3,
            'member_ids' => [5, 7], // 5 already present, 7 is new
        ]);

        $this->assertSame('/cdcf/v1/relationship', $posted->get_route());
        $this->assertSame([5, 7], $posted->get_params()['value']);
        $this->assertSame([5, 7], $out['value']);
    }

    public function test_add_project_lead_validates_input(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        $out = cdcf_mcp_cb_add_project_lead(['project_id' => 0, 'member_ids' => []]);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('invalid_input', $out->get_error_code());
    }

    public function test_delete_member_trashes_all_translations(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['post_type' => 'team_member']);
        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'it' => 11, 'es' => 12]);
        $trashed = [];
        Functions\when('wp_trash_post')->alias(function ($id) use (&$trashed) {
            $trashed[] = (int) $id;
            return (object) ['ID' => $id];
        });

        $out = cdcf_mcp_cb_delete_member(['post_id' => 10]);

        $this->assertSame([10, 11, 12], $out['deleted']);
        $this->assertFalse($out['forced']);
        $this->assertSame([10, 11, 12], $trashed);
    }

    public function test_delete_member_rejects_wrong_post_type(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['post_type' => 'project']);

        $out = cdcf_mcp_cb_delete_member(['post_id' => 99]);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('invalid_post', $out->get_error_code());
    }

    public function test_update_member_relationship_coerces_value_to_ints(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('sanitize_text_field')->returnArg();

        $captured = null;
        Functions\when('rest_do_request')->alias(function ($req) use (&$captured) {
            $captured = $req;
            return new WP_REST_Response(['updated' => true]);
        });

        cdcf_mcp_cb_update_member_relationship([
            'post_id' => '5',
            'field'   => 'technical_council',
            'value'   => ['10', '20', 'x'],
        ]);

        $this->assertSame('/cdcf/v1/relationship', $captured->get_route());
        $this->assertSame([10, 20, 0], $captured->get_params()['value']);
        $this->assertSame('technical_council', $captured->get_params()['field']);
    }

    public function test_create_page_inserts_draft_and_returns_id(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('wp_insert_post')->justReturn(99);
        Functions\when('is_wp_error')->alias(static fn($t) => $t instanceof WP_Error);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('get_post_status')->justReturn('draft');
        Functions\when('get_edit_post_link')->justReturn('http://example.test/edit');

        $out = cdcf_mcp_cb_create_page(['title' => 'Hello', 'content' => '<p>x</p>']);

        $this->assertTrue($out['success']);
        $this->assertSame(99, $out['post_id']);
        $this->assertSame('draft', $out['status']);
    }

    public function test_create_page_requires_title(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        $out = cdcf_mcp_cb_create_page(['content' => '<p>x</p>']);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('missing_title', $out->get_error_code());
    }

    // ─── per-ability coverage: council creators ───────────────────

    public function test_create_ecclesial_council_member_forces_its_council(): void
    {
        $captured = $this->captureDispatch();
        cdcf_mcp_cb_create_ecclesial_council_member(['title' => 'X', 'content' => '<p>b</p>']);
        $this->assertSame('/cdcf/v1/team-member', $captured()->get_route());
        $this->assertSame('ecclesial_council', $captured()->get_params()['council']);
    }

    public function test_create_technical_council_member_forces_its_council(): void
    {
        $captured = $this->captureDispatch();
        cdcf_mcp_cb_create_technical_council_member(['title' => 'X', 'content' => '<p>b</p>']);
        $this->assertSame('technical_council', $captured()->get_params()['council']);
    }

    public function test_create_academic_collaboration_dispatches_and_filters_empties(): void
    {
        $captured = $this->captureDispatch(['en_post_id' => 60]);
        $out = cdcf_mcp_cb_create_academic_collaboration([
            'title'              => 'Notre Dame',
            'collab_description' => 'd',
            'collab_university'  => 'University of Notre Dame',
            'collab_department'  => '', // empty — filtered out
        ]);
        $this->assertSame('/cdcf/v1/academic-collaboration', $captured()->get_route());
        $params = $captured()->get_params();
        $this->assertSame('University of Notre Dame', $params['collab_university']);
        $this->assertArrayNotHasKey('collab_department', $params);
        $this->assertSame(60, $out['en_post_id']);
    }

    // ─── per-ability coverage: content edits ──────────────────────

    public function test_update_member_bio_updates_post_and_acf_fields(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['post_type' => 'team_member', 'ID' => 5]);
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_update_post')->justReturn(5);
        Functions\when('is_wp_error')->alias(static fn($t) => $t instanceof WP_Error);
        $fields = [];
        Functions\when('update_field')->alias(function ($n, $v) use (&$fields) {
            $fields[$n] = $v;
            return true;
        });

        $out = cdcf_mcp_cb_update_member_bio(['post_id' => 5, 'content' => '<p>new</p>', 'member_role' => 'AI Lead']);

        $this->assertTrue($out['success']);
        $this->assertSame('AI Lead', $fields['member_role']);
    }

    public function test_update_member_bio_rejects_wrong_post_type(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['post_type' => 'project', 'ID' => 5]);

        $out = cdcf_mcp_cb_update_member_bio(['post_id' => 5, 'content' => 'x']);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('invalid_post', $out->get_error_code());
    }

    public function test_update_project_description_updates_the_project(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['post_type' => 'project', 'ID' => 8]);
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_update_post')->justReturn(8);
        Functions\when('is_wp_error')->alias(static fn($t) => $t instanceof WP_Error);

        $out = cdcf_mcp_cb_update_project_description(['post_id' => 8, 'content' => '<p>desc</p>']);
        $this->assertTrue($out['success']);
        $this->assertSame(8, $out['post_id']);
    }

    public function test_update_project_status_dispatches_to_endpoint(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('sanitize_text_field')->returnArg();
        $captured = $this->captureDispatch(['updated' => true]);

        cdcf_mcp_cb_update_project_status(['post_id' => 3, 'status' => 'active']);
        $this->assertSame('/cdcf/v1/project-status', $captured()->get_route());
        $this->assertSame('active', $captured()->get_params()['status']);
    }

    public function test_set_project_repos_writes_fields_across_translations(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['post_type' => 'project']);
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('pll_get_post_translations')->justReturn(['en' => 5, 'it' => 6]);
        $writes = [];
        Functions\when('update_field')->alias(function ($n, $v, $id) use (&$writes) {
            $writes[] = [$n, (int) $id];
            return true;
        });

        $out = cdcf_mcp_cb_set_project_repos([
            'project_id'       => 5,
            'project_repo_url' => 'https://github.com/x',
            'project_url'      => 'https://x.org',
        ]);

        $this->assertTrue($out['success']);
        // two fields × two translations
        $this->assertCount(4, $writes);
        $this->assertSame([5, 6], $out['updated_posts']);
    }

    public function test_set_featured_image_sets_the_thumbnail(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['ID' => 5]);
        Functions\when('get_post_type')->justReturn('attachment');
        $thumb = null;
        Functions\when('set_post_thumbnail')->alias(function ($p, $a) use (&$thumb) {
            $thumb = [(int) $p, (int) $a];
            return true;
        });

        $out = cdcf_mcp_cb_set_featured_image(['post_id' => 5, 'attachment_id' => 9]);
        $this->assertTrue($out['success']);
        $this->assertSame([5, 9], $thumb);
    }

    public function test_set_featured_image_rejects_non_attachment(): void
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('get_post')->justReturn((object) ['ID' => 5]);
        Functions\when('get_post_type')->justReturn('post');

        $out = cdcf_mcp_cb_set_featured_image(['post_id' => 5, 'attachment_id' => 9]);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('invalid_attachment', $out->get_error_code());
    }

    public function test_upload_media_rejects_an_invalid_url(): void
    {
        // The sideload path requires wp-admin/includes files unavailable in the
        // unit env; the input guard runs first and is what we assert here.
        Functions\when('esc_url_raw')->justReturn('');
        $out = cdcf_mcp_cb_upload_media(['url' => 'not a url']);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('invalid_url', $out->get_error_code());
    }

    // ─── per-ability coverage: listings + plain post ──────────────

    public function test_list_submitted_projects_queries_the_project_type(): void
    {
        $out = $this->runListing('cdcf_mcp_cb_list_submitted_projects', 'project');
        $this->assertSame('project', $out['queried_type']);
        $this->assertSame(7, $out['result'][0]['ID']);
        $this->assertSame('en', $out['result'][0]['language']);
    }

    public function test_list_submitted_community_projects_queries_that_type(): void
    {
        $out = $this->runListing('cdcf_mcp_cb_list_submitted_community_projects', 'community_project');
        $this->assertSame('community_project', $out['queried_type']);
    }

    public function test_create_post_inserts_a_blog_post(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('is_wp_error')->alias(static fn($t) => $t instanceof WP_Error);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('get_post_status')->justReturn('draft');
        Functions\when('get_edit_post_link')->justReturn('http://e');
        Functions\when('set_post_thumbnail')->justReturn(true);
        $captured = null;
        Functions\when('wp_insert_post')->alias(function ($arr) use (&$captured) {
            $captured = $arr;
            return 77;
        });

        $out = cdcf_mcp_cb_create_post(['title' => 'Post', 'content' => '<p>x</p>', 'featured_image_id' => 5]);

        $this->assertSame('post', $captured['post_type']);
        $this->assertSame(77, $out['post_id']);
    }

    // ─── helpers ──────────────────────────────────────────────────

    /**
     * Stub rest_do_request to capture the dispatched request; returns a getter.
     *
     * @return callable(): WP_REST_Request
     */
    private function captureDispatch(array $response = ['ok' => true]): callable
    {
        $captured = null;
        Functions\when('rest_do_request')->alias(function ($req) use (&$captured, $response) {
            $captured = $req;
            return new WP_REST_Response($response, 202);
        });
        // By-reference so the getter sees the request captured at dispatch time
        // (an arrow fn would close over the initial null by value).
        return function () use (&$captured) {
            return $captured;
        };
    }

    /**
     * Run a listing callback with get_posts/pll stubbed; returns the queried
     * post_type and the callback's result rows.
     *
     * @return array{queried_type: string, result: array<int,array<string,mixed>>}
     */
    private function runListing(string $callback, string $expectedType): array
    {
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('sanitize_key')->returnArg();
        Functions\when('pll_get_post_language')->justReturn('en');
        $queried = null;
        Functions\when('get_posts')->alias(function ($args) use (&$queried) {
            $queried = $args['post_type'];
            return [(object) ['ID' => 7, 'post_title' => 'P', 'post_status' => 'draft', 'post_date' => '2026-01-01']];
        });

        $result = $callback(['limit' => 5]);
        return ['queried_type' => (string) $queried, 'result' => $result];
    }
}
