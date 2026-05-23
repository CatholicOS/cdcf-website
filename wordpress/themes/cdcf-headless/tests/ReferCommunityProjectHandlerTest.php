<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Per-handler config + handler-specific assertions for
 * /cdcf/v1/refer-community-project. Shared coverage in
 * SubmissionHandlerTestBase.
 */
final class ReferCommunityProjectHandlerTest extends SubmissionHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_refer_community_project($request);
    }

    protected function getIpTransientPrefix(): string
    {
        return 'cdcf_refer_cp_';
    }

    protected function getExpectedPostType(): string
    {
        return 'community_project';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'project_name'      => 'Sample Community Project',
            'description'       => 'A real community project for review.',
            'category'          => '',
            'project_url'       => '',
            'github_url'        => '',
            'tags'              => [],
            'submitter_name'    => 'Jane Doe',
            'submitter_email'   => 'user@example.com',
            'verification_code' => '123456',
        ];
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_writes_optional_acf_fields_only_when_provided(): void
    {
        $this->stubCommonFunctions();
        $written = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$written): bool {
                $written[$field] = $value;
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest([
            'category'    => 'web',
            'project_url' => 'https://example.org',
            // github_url left blank
        ]));

        $this->assertSame('web', $written['project_category']);
        $this->assertSame('https://example.org', $written['project_url']);
        $this->assertArrayNotHasKey('project_github_url', $written);
    }

    public function test_assigns_project_tags_taxonomy(): void
    {
        $this->stubCommonFunctions();
        $assigned = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $post_id, array $tags, string $taxonomy) use (&$assigned): array {
                $assigned = [$post_id, $tags, $taxonomy];
                return $tags;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest([
            'tags' => ['python', 'cms', 'liturgy'],
        ]));

        $this->assertSame([800, ['python', 'cms', 'liturgy'], 'project_tag'], $assigned);
    }

    public function test_skips_tag_assignment_when_tags_empty(): void
    {
        $this->stubCommonFunctions();
        Functions\expect('wp_set_object_terms')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest(['tags' => []]));

        // PHPUnit strict mode doesn't count Mockery never-expectations
        // as assertions; pin the response shape explicitly.
        $this->assertSame(['success' => true, 'post_id' => 800], $response);
    }
}
