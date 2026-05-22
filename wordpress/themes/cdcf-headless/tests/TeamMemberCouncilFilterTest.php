<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Team Members → council admin hooks:
 *   - cdcf_filter_team_member_council_query() (pre_get_posts callback)
 *   - cdcf_highlight_team_member_council_submenu() (submenu_file filter)
 *   - cdcf_get_about_page_id_for_admin_lang() (helper)
 *
 * Brain Monkey ordering: every stub Brain Monkey needs to eval-declare
 * must be set up BEFORE function_exists is wholesale overridden. The
 * tests below either avoid the override entirely (when only specific
 * functions need stubbing) or set it up last via allowAllFunctionsToExist.
 */
final class TeamMemberCouncilFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        $_GET = [];
        parent::tearDown();
    }

    private function allowAllFunctionsToExist(): void
    {
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);
    }

    private function makeQuery(array $vars = [], bool $isMain = true): WP_Query
    {
        $q = new WP_Query();
        $q->vars = $vars;
        $q->main_query = $isMain;
        return $q;
    }

    // ─── admin_menu submenu registration ──────────────────────────

    public function test_submenu_registration_adds_three_council_entries_under_team_member(): void
    {
        // Pass-through translator so the labels survive intact.
        Functions\when('__')->returnArg(1);

        $calls = [];
        Functions\when('add_submenu_page')->alias(
            function (string $parent, string $page_title, string $menu_title, string $cap, string $slug)
            use (&$calls) {
                $calls[] = [$parent, $menu_title, $cap, $slug];
                return 'hook_' . $slug;
            }
        );

        cdcf_register_team_member_council_submenus();

        $this->assertSame(
            [
                ['edit.php?post_type=team_member', 'Board of Directors',          'edit_posts', 'edit.php?post_type=team_member&cdcf_council=board'],
                ['edit.php?post_type=team_member', 'Ecclesial Advisory Council',  'edit_posts', 'edit.php?post_type=team_member&cdcf_council=ecclesial'],
                ['edit.php?post_type=team_member', 'Technical Advisory Council',  'edit_posts', 'edit.php?post_type=team_member&cdcf_council=technical'],
            ],
            $calls
        );
    }

    // ─── pre_get_posts callback: early-return guards ──────────────

    public function test_filter_skips_when_not_in_admin(): void
    {
        Functions\when('is_admin')->justReturn(false);
        $_GET['cdcf_council'] = 'board';
        $q = $this->makeQuery(['post_type' => 'team_member']);

        cdcf_filter_team_member_council_query($q);

        $this->assertNull($q->get('post__in'));
    }

    public function test_filter_skips_when_not_main_query(): void
    {
        Functions\when('is_admin')->justReturn(true);
        $_GET['cdcf_council'] = 'board';
        $q = $this->makeQuery(['post_type' => 'team_member'], isMain: false);

        cdcf_filter_team_member_council_query($q);

        $this->assertNull($q->get('post__in'));
    }

    public function test_filter_skips_when_post_type_is_not_team_member(): void
    {
        Functions\when('is_admin')->justReturn(true);
        $_GET['cdcf_council'] = 'board';
        $q = $this->makeQuery(['post_type' => 'post']);

        cdcf_filter_team_member_council_query($q);

        $this->assertNull($q->get('post__in'));
    }

    public function test_filter_skips_when_council_param_is_missing(): void
    {
        Functions\when('is_admin')->justReturn(true);
        // No cdcf_council in $_GET.
        $q = $this->makeQuery(['post_type' => 'team_member']);

        cdcf_filter_team_member_council_query($q);

        $this->assertNull($q->get('post__in'));
    }

    public function test_filter_skips_when_council_param_is_unknown(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('sanitize_key')->returnArg(1);
        $_GET['cdcf_council'] = 'nonsense';
        $q = $this->makeQuery(['post_type' => 'team_member']);

        cdcf_filter_team_member_council_query($q);

        $this->assertNull($q->get('post__in'));
    }

    public function test_filter_skips_when_acf_inactive(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('function_exists')->alias(
            static fn(string $name): bool => $name !== 'get_field'
        );
        $_GET['cdcf_council'] = 'board';
        $q = $this->makeQuery(['post_type' => 'team_member']);

        cdcf_filter_team_member_council_query($q);

        $this->assertNull($q->get('post__in'));
    }

    // ─── pre_get_posts callback: no-data / happy paths ────────────

    public function test_filter_sets_empty_post_in_when_no_about_page_exists(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('get_pages')->justReturn([]);
        $this->allowAllFunctionsToExist();
        $_GET['cdcf_council'] = 'ecclesial';
        $q = $this->makeQuery(['post_type' => 'team_member']);

        cdcf_filter_team_member_council_query($q);

        // An impossible post__in of [0] keeps the list empty rather than
        // accidentally showing every team_member when the About page is
        // missing.
        $this->assertSame([0], $q->get('post__in'));
    }

    public function test_filter_sets_empty_post_in_when_relationship_field_is_empty(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('get_pages')->justReturn([(object) ['ID' => 50]]);
        Functions\when('pll_current_language')->justReturn('en');
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('get_field')->justReturn([]);
        $this->allowAllFunctionsToExist();
        $_GET['cdcf_council'] = 'technical';
        $q = $this->makeQuery(['post_type' => 'team_member']);

        cdcf_filter_team_member_council_query($q);

        $this->assertSame([0], $q->get('post__in'));
    }

    public function test_filter_restricts_post_in_to_relationship_ids_with_preserved_order(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('get_pages')->justReturn([(object) ['ID' => 50]]);
        Functions\when('pll_current_language')->justReturn('en');
        Functions\when('pll_get_post_language')->justReturn('en');
        // Returned as strings to confirm the array_map('intval', ...) coercion.
        Functions\when('get_field')->justReturn(['101', '102', '103']);
        $this->allowAllFunctionsToExist();
        $_GET['cdcf_council'] = 'technical';
        $q = $this->makeQuery(['post_type' => 'team_member']);

        cdcf_filter_team_member_council_query($q);

        $this->assertSame([101, 102, 103], $q->get('post__in'));
        $this->assertSame('post__in', $q->get('orderby'));
    }

    public function test_filter_resolves_correct_acf_field_per_council_slug(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('get_pages')->justReturn([(object) ['ID' => 50]]);
        Functions\when('pll_current_language')->justReturn('en');
        Functions\when('pll_get_post_language')->justReturn('en');

        $fieldRequested = null;
        Functions\when('get_field')->alias(
            function (string $field, int $about_id, bool $format) use (&$fieldRequested): array {
                $fieldRequested = $field;
                return [200];
            }
        );
        $this->allowAllFunctionsToExist();

        $_GET['cdcf_council'] = 'board';
        cdcf_filter_team_member_council_query($this->makeQuery(['post_type' => 'team_member']));
        $this->assertSame('team_members', $fieldRequested);

        $_GET['cdcf_council'] = 'ecclesial';
        cdcf_filter_team_member_council_query($this->makeQuery(['post_type' => 'team_member']));
        $this->assertSame('ecclesial_council', $fieldRequested);

        $_GET['cdcf_council'] = 'technical';
        cdcf_filter_team_member_council_query($this->makeQuery(['post_type' => 'team_member']));
        $this->assertSame('technical_council', $fieldRequested);
    }

    // ─── submenu_file filter ──────────────────────────────────────

    public function test_submenu_filter_returns_input_when_post_type_is_not_team_member(): void
    {
        $_GET['post_type'] = 'post';
        $_GET['cdcf_council'] = 'board';

        $this->assertSame(
            'edit.php',
            cdcf_highlight_team_member_council_submenu('edit.php')
        );
    }

    public function test_submenu_filter_returns_input_when_council_is_invalid(): void
    {
        $_GET['post_type'] = 'team_member';
        $_GET['cdcf_council'] = 'bogus';
        Functions\when('sanitize_key')->returnArg(1);

        $this->assertSame(
            'edit.php?post_type=team_member',
            cdcf_highlight_team_member_council_submenu('edit.php?post_type=team_member')
        );
    }

    public function test_submenu_filter_returns_custom_slug_for_valid_council(): void
    {
        $_GET['post_type'] = 'team_member';
        $_GET['cdcf_council'] = 'technical';
        Functions\when('sanitize_key')->returnArg(1);

        $this->assertSame(
            'edit.php?post_type=team_member&cdcf_council=technical',
            cdcf_highlight_team_member_council_submenu('edit.php?post_type=team_member')
        );
    }

    // ─── cdcf_get_about_page_id_for_admin_lang() helper ───────────

    public function test_about_page_lookup_returns_zero_when_no_about_page_exists(): void
    {
        Functions\when('get_pages')->justReturn([]);

        $this->assertSame(0, cdcf_get_about_page_id_for_admin_lang());
    }

    public function test_about_page_lookup_prefers_current_admin_language(): void
    {
        $pages = [
            (object) ['ID' => 10],
            (object) ['ID' => 20],
            (object) ['ID' => 30],
        ];
        Functions\when('get_pages')->justReturn($pages);
        Functions\when('pll_current_language')->justReturn('it');
        Functions\when('pll_get_post_language')->alias(
            static fn(int $id): string => match ($id) {
                10 => 'en',
                20 => 'it',
                30 => 'es',
                default => 'en',
            }
        );
        $this->allowAllFunctionsToExist();

        $this->assertSame(20, cdcf_get_about_page_id_for_admin_lang());
    }

    public function test_about_page_lookup_falls_back_to_english_when_admin_lang_not_found(): void
    {
        $pages = [
            (object) ['ID' => 10],
            (object) ['ID' => 20],
        ];
        Functions\when('get_pages')->justReturn($pages);
        Functions\when('pll_current_language')->justReturn('de');  // no German page exists
        Functions\when('pll_get_post_language')->alias(
            static fn(int $id): string => match ($id) {
                10 => 'en',
                20 => 'it',
                default => 'en',
            }
        );
        $this->allowAllFunctionsToExist();

        $this->assertSame(10, cdcf_get_about_page_id_for_admin_lang());
    }

    public function test_about_page_lookup_falls_back_to_first_page_when_neither_lang_matches(): void
    {
        // Pages exist but none reports as the current admin lang OR English —
        // the helper falls back to the first page in the result set so the
        // caller still has something to work with.
        $pages = [
            (object) ['ID' => 70],
            (object) ['ID' => 71],
        ];
        Functions\when('get_pages')->justReturn($pages);
        Functions\when('pll_current_language')->justReturn('de');
        Functions\when('pll_get_post_language')->justReturn('fr');
        $this->allowAllFunctionsToExist();

        $this->assertSame(70, cdcf_get_about_page_id_for_admin_lang());
    }
}
