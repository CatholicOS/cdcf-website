<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the /cdcf/v1/team-member handler.
 *
 * Branch matrix:
 *   - guard: invalid council, missing Polylang, missing ACF, wp_insert_post failure
 *   - link: no council (project-only), academic_council (collab_governance),
 *           other councils (About page relationship field)
 *   - edge: academic_council without collab_post_id, no About page found
 */
final class TeamMemberHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Stub the side-effect-free helpers the handler reaches for on the
     * happy path. CRITICAL ordering: every function we want Brain Monkey
     * to eval-declare must be stubbed BEFORE function_exists is wholesale
     * overridden — Brain Monkey's FunctionStub constructor short-circuits
     * when function_exists() returns true for the target name, leaving
     * the symbol undefined at call time. See the matching comment in
     * RelationshipHandlerTest.
     */
    private function stubCommonFunctions(): void
    {
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('pll_set_post_language')->justReturn(true);
        Functions\when('pll_save_post_translations')->justReturn(true);
        Functions\when('cdcf_enqueue_translation')->justReturn('redis');
    }

    /**
     * Wholesale-true function_exists — call AFTER all other stubs are
     * in place so their eval-declarations have already run.
     */
    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    /**
     * Return a wp_insert_post stub that hands out monotonically rising
     * IDs starting from the given base, so the EN post and its five
     * translation drafts each get distinct IDs.
     */
    private function stubInsertingPostsFrom(int $base): void
    {
        $counter = $base - 1;
        Functions\when('wp_insert_post')->alias(function () use (&$counter): int {
            return ++$counter;
        });
    }

    private function makeRequest(array $params): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'title'              => 'Jane Doe',
            'content'            => '<p>Bio</p>',
            'member_title'       => '',
            'member_role'        => '',
            'member_linkedin_url' => '',
            'member_github_url'  => '',
            'council'            => '',
            'featured_image_id'  => 0,
            'collab_post_id'     => 0,
        ];
        foreach (array_merge($defaults, $params) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    // ─── Guard clauses ────────────────────────────────────────────

    public function test_rejects_unknown_council(): void
    {
        $req = $this->makeRequest(['council' => 'bogus_council']);

        $response = cdcf_rest_create_team_member($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('invalid_council', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_polylang_inactive(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'pll_set_post_language'
        );

        $req = $this->makeRequest([]);

        $response = cdcf_rest_create_team_member($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('polylang_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_acf_inactive(): void
    {
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'update_field'
        );

        $req = $this->makeRequest([]);

        $response = cdcf_rest_create_team_member($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('acf_missing', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_wp_insert_post_fails(): void
    {
        $this->stubCommonFunctions();
        Functions\when('wp_insert_post')->justReturn(0);
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest([]);

        $response = cdcf_rest_create_team_member($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
        $this->assertSame(500, $response->get_error_data()['status']);
    }

    public function test_returns_500_when_wp_insert_post_returns_wp_error(): void
    {
        $this->stubCommonFunctions();
        Functions\when('wp_insert_post')->justReturn(new WP_Error('db_insert', 'DB down'));
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest([]);

        $response = cdcf_rest_create_team_member($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('insert_failed', $response->get_error_code());
    }

    // ─── No council (project-only member) ─────────────────────────

    public function test_no_council_creates_posts_without_linking(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(100);
        // Project-only members must NOT touch any relationship field.
        Functions\expect('update_field')->never();
        Functions\expect('get_pages')->never();
        Functions\expect('pll_get_post_translations')->never();
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest([]);

        $response = cdcf_rest_create_team_member($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(202, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame(100, $data['en_post_id']);
        $this->assertSame(
            ['en' => 100, 'it' => 101, 'es' => 102, 'fr' => 103, 'pt' => 104, 'de' => 105],
            $data['translations']
        );
        $this->assertSame('', $data['council']);
        $this->assertSame([], $data['errors']);
    }

    // ─── About-page councils (board / ecclesial / technical) ──────

    public function test_team_members_council_links_to_about_page_per_language(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(200);

        // One About page per language. EN is found first by language match.
        Functions\when('get_pages')->justReturn([(object) ['ID' => 50]]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 50, 'it' => 51, 'es' => 52, 'fr' => 53, 'pt' => 54, 'de' => 55,
        ]);
        // Existing relationship is empty — handler must initialise to [].
        Functions\when('get_field')->justReturn(false);

        $linked = [];
        Functions\when('update_field')->alias(
            function (string $field, array $value, int $about_id) use (&$linked): bool {
                $linked[] = [$field, $value, $about_id];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest(['council' => 'team_members']);

        $response = cdcf_rest_create_team_member($req);

        $this->assertInstanceOf(WP_REST_Response::class, $response);

        // One update_field call per language, linking the matching
        // member ID into the matching About-page translation.
        $this->assertSame(
            [
                ['team_members', [200], 50],
                ['team_members', [201], 51],
                ['team_members', [202], 52],
                ['team_members', [203], 53],
                ['team_members', [204], 54],
                ['team_members', [205], 55],
            ],
            $linked
        );
        $this->assertSame([], $response->get_data()['errors']);
    }

    public function test_about_council_appends_to_existing_relationship_without_duplicating(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(300);

        Functions\when('get_pages')->justReturn([(object) ['ID' => 60]]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 60, 'it' => 61, 'es' => 62, 'fr' => 63, 'pt' => 64, 'de' => 65,
        ]);
        // Pre-existing council members on EN; the handler should append.
        Functions\when('get_field')->alias(
            static fn(string $field, int $about_id) => $about_id === 60 ? [999] : []
        );

        $linked = [];
        Functions\when('update_field')->alias(
            function (string $field, array $value, int $about_id) use (&$linked): bool {
                $linked[] = [$field, $value, $about_id];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest(['council' => 'ecclesial_council']);

        cdcf_rest_create_team_member($req);

        // EN got appended (999 preserved + new 300); other langs started empty.
        $this->assertSame(['ecclesial_council', [999, 300], 60], $linked[0]);
        $this->assertSame(['ecclesial_council', [301], 61], $linked[1]);
    }

    public function test_records_error_when_no_about_page_exists(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(400);
        Functions\when('get_pages')->justReturn([]);
        Functions\expect('update_field')->never();
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest(['council' => 'technical_council']);

        $response = cdcf_rest_create_team_member($req);

        $this->assertContains(
            'No About page found with templates/about.php template.',
            $response->get_data()['errors']
        );
    }

    // ─── Academic council branch ──────────────────────────────────

    public function test_academic_council_links_to_collab_governance(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(500);

        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 700, 'it' => 701, 'es' => 702, 'fr' => 703, 'pt' => 704, 'de' => 705,
        ]);
        Functions\when('get_field')->justReturn([]);

        $linked = [];
        Functions\when('update_field')->alias(
            function (string $field, array $value, int $collab_id) use (&$linked): bool {
                $linked[] = [$field, $value, $collab_id];
                return true;
            }
        );
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest([
            'council'        => 'academic_council',
            'collab_post_id' => 700,
        ]);

        cdcf_rest_create_team_member($req);

        // All updates go to collab_governance on the matching collab
        // translation — never on the About page.
        foreach ($linked as $call) {
            $this->assertSame('collab_governance', $call[0]);
        }
        $this->assertSame([500], $linked[0][1]);  // EN member → EN collab
        $this->assertSame(700, $linked[0][2]);
    }

    public function test_academic_council_without_collab_post_id_records_error(): void
    {
        $this->stubCommonFunctions();
        $this->stubInsertingPostsFrom(600);
        // Posts still get created, but no relationship is touched.
        Functions\expect('update_field')->never();
        Functions\expect('pll_get_post_translations')->never();
        $this->allowAllFunctionsToExist();

        $req = $this->makeRequest([
            'council'        => 'academic_council',
            'collab_post_id' => 0,
        ]);

        $response = cdcf_rest_create_team_member($req);

        $this->assertContains(
            'academic_council requires collab_post_id parameter.',
            $response->get_data()['errors']
        );
    }
}
