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

    public function test_referral_enters_council_review(): void
    {
        $this->stubCommonFunctions();
        $log = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value) use (&$log): bool {
            $log[] = ['meta', $key, $value];
            return true;
        });
        Functions\when('cdcf_review_record_event')->alias(function (int $pid, string $type, ?int $actor) use (&$log): int {
            $log[] = ['event', $pid, $type, $actor];
            return 1;
        });
        Functions\when('cdcf_review_send_submitter_email')->alias(function (int $pid, string $tpl) use (&$log): string {
            $log[] = ['confirm', $pid, $tpl];
            return 'queued';
        });
        Functions\when('cdcf_review_workspace_url')->justReturn('https://example.org/review/800');
        Functions\when('wp_mail')->alias(function () use (&$log): bool {
            $log[] = ['admin_mail'];
            return true;
        });
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest());

        $this->assertContains(['meta', '_cdcf_review_stage', 'in_review'], $log);
        $kinds = array_column(array_filter($log, fn($e) => $e[0] !== 'meta'), 0);
        $this->assertSame(['event', 'confirm', 'admin_mail'], array_values($kinds));
        $this->assertContains(['event', 800, 'submission_received', null], $log);
        $this->assertContains(['confirm', 800, 'confirmation'], $log);
    }

    public function test_admin_email_points_to_the_review_workspace(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_review_workspace_url')->alias(
            fn(int $id, string $lang) => "https://example.org/{$lang}/review/{$id}"
        );
        $body = '';
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $b) use (&$body): bool {
            $body = $b;
            return true;
        });
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest());

        $this->assertStringContainsString('under council review', $body);
        $this->assertStringContainsString('https://example.org/en/review/800', $body);
        $this->assertStringNotContainsString('post.php?post=', $body);
    }

    public function test_spam_referral_never_enters_review(): void
    {
        $this->stubCommonFunctions();
        Functions\when('cdcf_is_spam_content')->justReturn(true);
        Functions\expect('cdcf_review_record_event')->never();
        Functions\expect('cdcf_review_send_submitter_email')->never();
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest());

        $this->assertSame(['success' => true, 'post_id' => 0], $response);
    }
}
