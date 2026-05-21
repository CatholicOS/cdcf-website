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
}
