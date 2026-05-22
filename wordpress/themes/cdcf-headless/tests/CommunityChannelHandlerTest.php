<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Per-handler config for /cdcf/v1/community-channel.
 * Shared branch coverage is in CommunityHandlerTestBase.
 */
final class CommunityChannelHandlerTest extends CommunityHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_create_community_channel($request);
    }

    protected function getRelationshipField(): string
    {
        return 'channels';
    }

    protected function getInsertFailureMessage(): string
    {
        return 'Failed to create English community channel post.';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'title'               => 'Discord Server',
            'channel_description' => 'CDCF community Discord',
            'channel_url'         => 'https://discord.gg/example',
            'channel_icon'        => '',
        ];
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_writes_optional_channel_icon_when_provided(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(1000);
        $this->stubCommunityPageHappy(50, [
            'en' => 50, 'it' => 51, 'es' => 52, 'fr' => 53, 'pt' => 54, 'de' => 55,
        ]);

        $iconWrites = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$iconWrites): bool {
                if ($field === 'channel_icon') {
                    $iconWrites[] = [$value, $post_id];
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest(['channel_icon' => 'discord']));

        // channel_icon is written exactly once, on the EN post (1000).
        $this->assertSame([['discord', 1000]], $iconWrites);
    }
}
