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
