<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Per-handler config for /cdcf/v1/local-group.
 * Shared branch coverage is in CommunityHandlerTestBase.
 */
final class LocalGroupHandlerTest extends CommunityHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_create_local_group($request);
    }

    protected function getRelationshipField(): string
    {
        return 'local_groups';
    }

    protected function getInsertFailureMessage(): string
    {
        return 'Failed to create English local group post.';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'title'             => 'Rome Chapter',
            'group_description' => 'Rome local CDCF chapter',
            'group_url'         => 'https://example.com/rome',
            'group_location'    => '',
        ];
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_writes_optional_group_location_when_provided(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(2000);
        $this->stubCommunityPageHappy(50, [
            'en' => 50, 'it' => 51, 'es' => 52, 'fr' => 53, 'pt' => 54, 'de' => 55,
        ]);

        $locationWrites = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$locationWrites): bool {
                if ($field === 'group_location') {
                    $locationWrites[] = [$value, $post_id];
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest(['group_location' => 'Rome, Italy']));

        $this->assertSame([['Rome, Italy', 2000]], $locationWrites);
    }
}
