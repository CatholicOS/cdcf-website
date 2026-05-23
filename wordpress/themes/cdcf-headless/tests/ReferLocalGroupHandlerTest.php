<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Per-handler config + handler-specific assertions for
 * /cdcf/v1/refer-local-group. Shared coverage in SubmissionHandlerTestBase.
 */
final class ReferLocalGroupHandlerTest extends SubmissionHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_refer_local_group($request);
    }

    protected function getIpTransientPrefix(): string
    {
        return 'cdcf_refer_';
    }

    protected function getExpectedPostType(): string
    {
        return 'local_group';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'group_name'        => 'Rome Chapter',
            'description'       => 'A real local group looking to join.',
            'url'               => 'https://example.com/rome',
            'location'          => '',
            'submitter_name'    => 'Jane Doe',
            'submitter_email'   => 'user@example.com',
            'verification_code' => '123456',
        ];
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_writes_group_description_and_url_as_acf_fields(): void
    {
        $this->stubCommonFunctions();
        $writes = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$writes): bool {
                $writes[] = [$field, $value, $post_id];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest());

        // The two required fields always written; optional location
        // omitted because the default request leaves it blank.
        $fields = array_column($writes, 0);
        $this->assertContains('group_description', $fields);
        $this->assertContains('group_url', $fields);
        $this->assertNotContains('group_location', $fields);
    }

    public function test_writes_optional_group_location_when_provided(): void
    {
        $this->stubCommonFunctions();
        $locationWrite = null;
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$locationWrite): bool {
                if ($field === 'group_location') {
                    $locationWrite = [$value, $post_id];
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest(['location' => 'Rome, Italy']));

        $this->assertSame(['Rome, Italy', 800], $locationWrite);
    }

    public function test_stores_submitter_info_as_private_post_meta(): void
    {
        $this->stubCommonFunctions();
        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, $value) use (&$metaWrites): bool {
                $metaWrites[] = [$post_id, $key, $value];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest());

        $this->assertSame(
            [
                [800, '_referral_submitter_name',  'Jane Doe'],
                [800, '_referral_submitter_email', 'user@example.com'],
            ],
            $metaWrites
        );
    }
}
