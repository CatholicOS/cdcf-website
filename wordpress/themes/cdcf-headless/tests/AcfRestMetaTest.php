<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * ACF fields written through REST post meta must also get ACF's
 * `_<name>` → field-key link. Without it get_field_objects() does not see
 * the field, so the AI translation skips it and the translated page comes
 * back with only its title.
 */
final class AcfRestMetaTest extends TestCase
{
    /** Reset Brain Monkey before each test. */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    /** Tear Brain Monkey and Mockery down after each test. */
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Two local field groups: one by page template, one by post type. */
    private function stubGroups(): void
    {
        Functions\when('acf_get_field_groups')->justReturn([
            [
                'key'      => 'group_donate_page',
                'location' => [
                    [['param' => 'page_template', 'operator' => '==', 'value' => 'templates/donate.php']],
                ],
            ],
            [
                'key'      => 'group_project',
                'location' => [
                    [['param' => 'post_type', 'operator' => '==', 'value' => 'project']],
                ],
            ],
        ]);
        Functions\when('acf_get_fields')->alias(function (string $group) {
            if ($group === 'group_donate_page') {
                return [
                    ['key' => 'field_donate_appeal_body', 'name' => 'donate_appeal_body', 'type' => 'wysiwyg', 'show_in_rest' => true],
                    ['key' => 'field_donate_hidden', 'name' => 'donate_hidden', 'type' => 'text'],
                ];
            }
            return [
                ['key' => 'field_project_url', 'name' => 'project_url', 'type' => 'url', 'show_in_rest' => true],
                ['key' => 'field_project_leads', 'name' => 'project_leads', 'type' => 'relationship', 'show_in_rest' => true],
            ];
        });
    }

    /** A REST request whose body set the given meta. */
    private function request(array $meta): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $request->set_param('meta', $meta);
        return $request;
    }

    /** A post with the given id. */
    private function post(int $id): WP_Post
    {
        $post     = new WP_Post();
        $post->ID = $id;
        return $post;
    }

    /** Only REST-visible scalar fields are bridged, keyed by post type. */
    public function test_collects_rest_visible_scalar_fields_by_post_type(): void
    {
        $this->stubGroups();

        $this->assertSame(
            [
                'page'    => ['donate_appeal_body' => 'field_donate_appeal_body'],
                'project' => ['project_url' => 'field_project_url'],
            ],
            cdcf_acf_rest_meta_fields()
        );
    }

    /** Each bridged field written in the request gets its field-key link. */
    public function test_writes_the_field_key_link_for_each_meta_field_written(): void
    {
        $written = [];
        Functions\when('update_post_meta')->alias(
            function ($id, $key, $value) use (&$written) {
                $written[] = [$id, $key, $value];
                return true;
            }
        );

        cdcf_acf_rest_write_field_links(
            $this->post(1664),
            $this->request(['donate_appeal_body' => '<p>Hi</p>', 'not_acf' => 'x']),
            ['donate_appeal_body' => 'field_donate_appeal_body']
        );

        $this->assertSame(
            [[1664, '_donate_appeal_body', 'field_donate_appeal_body']],
            $written
        );
    }

    /** Each bridged field is registered as string meta for its post type. */
    public function test_registers_each_bridged_field_as_rest_meta(): void
    {
        $this->stubGroups();
        Functions\when('add_action')->justReturn(true);
        $registered = [];
        Functions\when('register_post_meta')->alias(
            function ($pt, $name, $args) use (&$registered) {
                $registered[] = [$pt, $name, $args['type'], $args['single'], $args['show_in_rest']];
                return true;
            }
        );

        cdcf_register_acf_rest_meta();

        $this->assertSame(
            [
                ['page', 'donate_appeal_body', 'string', true, true],
                ['project', 'project_url', 'string', true, true],
            ],
            $registered
        );
    }

    /** Writing bridged meta over REST needs edit_posts. */
    public function test_registered_meta_requires_edit_posts(): void
    {
        $this->stubGroups();
        Functions\when('add_action')->justReturn(true);
        $callbacks = [];
        Functions\when('register_post_meta')->alias(
            function ($pt, $name, $args) use (&$callbacks) {
                $callbacks[] = $args['auth_callback'];
                return true;
            }
        );
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_posts')
            ->andReturn(false);

        cdcf_register_acf_rest_meta();

        $this->assertFalse($callbacks[0]());
    }

    /** The hook for each post type writes that type's field links. */
    public function test_hooks_the_link_writer_after_each_rest_insert(): void
    {
        $this->stubGroups();
        Functions\when('register_post_meta')->justReturn(true);
        $hooks = [];
        Functions\when('add_action')->alias(
            function ($hook, $callback, $priority, $args) use (&$hooks) {
                $hooks[$hook] = [$callback, $priority, $args];
                return true;
            }
        );
        $written = [];
        Functions\when('update_post_meta')->alias(
            function ($id, $key, $value) use (&$written) {
                $written[] = [$id, $key, $value];
                return true;
            }
        );

        cdcf_register_acf_rest_meta();

        $this->assertSame(['rest_after_insert_page', 'rest_after_insert_project'], array_keys($hooks));
        $this->assertSame([10, 2], array_slice($hooks['rest_after_insert_page'], 1));

        ($hooks['rest_after_insert_page'][0])(
            $this->post(1664),
            $this->request(['donate_appeal_body' => '<p>Hi</p>', 'project_url' => 'x'])
        );

        // Only the page's own fields: project_url belongs to another type.
        $this->assertSame(
            [[1664, '_donate_appeal_body', 'field_donate_appeal_body']],
            $written
        );
    }

    /** A group with no post type or page template location is skipped. */
    public function test_skips_a_group_with_no_post_location(): void
    {
        Functions\when('acf_get_field_groups')->justReturn([
            [
                'key'      => 'group_user',
                'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'all']]],
            ],
        ]);
        Functions\expect('acf_get_fields')->never();

        $this->assertSame([], cdcf_acf_rest_meta_fields());
    }

    /** A request without meta (a title-only update, say) writes nothing. */
    public function test_writes_nothing_when_the_request_sets_no_meta(): void
    {
        Functions\expect('update_post_meta')->never();

        cdcf_acf_rest_write_field_links(
            $this->post(1664),
            new WP_REST_Request(),
            ['donate_appeal_body' => 'field_donate_appeal_body']
        );

        $this->addToAssertionCount(1);
    }
}
