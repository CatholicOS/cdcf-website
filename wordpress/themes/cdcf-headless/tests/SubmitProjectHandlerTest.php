<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Per-handler config + handler-specific assertions for
 * /cdcf/v1/submit-project. Shared coverage in SubmissionHandlerTestBase.
 */
final class SubmitProjectHandlerTest extends SubmissionHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_submit_project($request);
    }

    protected function getIpTransientPrefix(): string
    {
        return 'cdcf_projsub_';
    }

    protected function getExpectedPostType(): string
    {
        return 'project';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'project_name'      => 'Sample OSS Project',
            'description'       => 'A genuine open-source project for review.',
            'category'          => '',
            'url'               => 'https://example.org/oss',
            'repo_urls'         => [],
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

    public function test_always_writes_project_url_and_status_incubating(): void
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

        $this->invokeHandler($this->makeRequest());

        $this->assertSame('https://example.org/oss', $written['project_url']);
        $this->assertSame('incubating', $written['project_status']);
    }

    public function test_first_repo_url_becomes_project_repo_url(): void
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
            'repo_urls' => [
                'https://github.com/cdcf/primary',
                'https://github.com/cdcf/secondary',
            ],
        ]));

        // Only the first URL becomes the ACF field; the full list goes
        // into private meta (see the next test).
        $this->assertSame('https://github.com/cdcf/primary', $written['project_repo_url']);
    }

    public function test_full_repo_urls_list_stored_as_json_private_meta(): void
    {
        $this->stubCommonFunctions();
        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value) use (&$metaWrites): bool {
                if ($key === '_submission_repo_urls') {
                    $metaWrites[] = $value;
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest([
            'repo_urls' => [
                'https://github.com/cdcf/primary',
                'https://github.com/cdcf/secondary',
            ],
        ]));

        $this->assertSame(
            [json_encode(['https://github.com/cdcf/primary', 'https://github.com/cdcf/secondary'])],
            $metaWrites
        );
    }

    public function test_skips_repo_writes_when_repo_urls_empty(): void
    {
        $this->stubCommonFunctions();
        $repoUrlWritten = false;
        Functions\when('update_field')->alias(
            function (string $field) use (&$repoUrlWritten): bool {
                if ($field === 'project_repo_url') {
                    $repoUrlWritten = true;
                }
                return true;
            }
        );
        $metaRepoWritten = false;
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key) use (&$metaRepoWritten): bool {
                if ($key === '_submission_repo_urls') {
                    $metaRepoWritten = true;
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest(['repo_urls' => []]));

        $this->assertFalse($repoUrlWritten, 'project_repo_url should not be set with no repos');
        $this->assertFalse($metaRepoWritten, '_submission_repo_urls meta should not be set with no repos');
    }
}
