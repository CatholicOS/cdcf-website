<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Per-handler config for /cdcf/v1/academic-collaboration.
 * Shared branch coverage is in CommunityHandlerTestBase. This handler
 * has more optional fields than the other two (department, location,
 * website_url) and also accepts a featured_image_id, so it carries
 * extra per-field coverage tests beyond the shared trio.
 */
final class AcademicCollaborationHandlerTest extends CommunityHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_create_academic_collaboration($request);
    }

    protected function getRelationshipField(): string
    {
        return 'academic_collaborations';
    }

    protected function getInsertFailureMessage(): string
    {
        return 'Failed to create English academic collaboration post.';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'title'              => 'University of Notre Dame',
            'collab_description' => 'Academic partnership',
            'collab_university'  => 'University of Notre Dame',
            'collab_department'  => '',
            'collab_location'    => '',
            'collab_website_url' => '',
            'featured_image_id'  => 0,
        ];
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_writes_all_optional_collab_fields_when_provided(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(3000);
        $this->stubCommunityPageHappy(50, [
            'en' => 50, 'it' => 51, 'es' => 52, 'fr' => 53, 'pt' => 54, 'de' => 55,
        ]);

        $optionalWrites = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$optionalWrites): bool {
                if (in_array($field, ['collab_department', 'collab_location', 'collab_website_url'], true)) {
                    $optionalWrites[$field] = [$value, $post_id];
                }
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $this->invokeHandler($this->makeRequest([
            'collab_department'  => 'McGrath Institute',
            'collab_location'    => 'Notre Dame, IN',
            'collab_website_url' => 'https://nd.edu/cdcf',
        ]));

        // All three optional fields written exactly once, on the EN post.
        $this->assertSame(
            [
                'collab_department'  => ['McGrath Institute', 3000],
                'collab_location'    => ['Notre Dame, IN', 3000],
                'collab_website_url' => ['https://nd.edu/cdcf', 3000],
            ],
            $optionalWrites
        );
    }

    public function test_sets_featured_image_when_provided(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(4000);
        $this->stubCommunityPageHappy(50, [
            'en' => 50, 'it' => 51, 'es' => 52, 'fr' => 53, 'pt' => 54, 'de' => 55,
        ]);
        Functions\when('update_field')->justReturn(true);
        Functions\expect('set_post_thumbnail')->once()->with(4000, 9999);
        $this->allowAllFunctionsToExist();

        $response = $this->invokeHandler($this->makeRequest(['featured_image_id' => 9999]));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(4000, $response->get_data()['en_post_id']);
    }
}
