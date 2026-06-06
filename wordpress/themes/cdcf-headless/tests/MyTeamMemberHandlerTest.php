<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/handlers/my-team-member.php — Phase 3 of the
 * cdcf-bio-edit-zitadel plan. Covers:
 *
 *   - cdcf_my_team_member_resolve_link()      ACF shape variants
 *   - cdcf_my_team_member_collect_group()     Polylang shape variants
 *   - cdcf_my_team_member_url_host_ok()       linkedin/github allowlist
 *   - cdcf_rest_my_team_member_permission()   401 / 403 / accept paths
 *   - cdcf_rest_get_my_team_member()          discovery happy + edge
 *   - cdcf_rest_update_my_team_member()       per-language edit + fan-out
 *                                             + ownership invariant
 *                                             + URL validation
 *
 * The handler delegates persistence (wp_update_post, update_field,
 * cdcf_enqueue_post_translation) so those are stubbed and asserted via
 * captured closures rather than against real WP.
 */
final class MyTeamMemberHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Used by cdcf_my_team_member_url_host_ok in every URL-allowlist
        // test (including the direct-helper ones that don't call
        // stubCommon). Maps to the PHP built-in.
        Functions\when('wp_parse_url')->alias(
            static fn(string $url, int $component = -1) => $component === -1
                ? parse_url($url)
                : parse_url($url, $component)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /** Common WP/REST stubs the handler body consults. */
    private function stubCommon(int $user_id = 7): void
    {
        Functions\when('is_user_logged_in')->justReturn($user_id > 0);
        Functions\when('get_current_user_id')->justReturn($user_id);
        Functions\when('is_wp_error')->alias(static fn($v): bool => $v instanceof WP_Error);
        Functions\when('rest_ensure_response')->returnArg(1);
        // Default get_post returns a valid team_member; PATCH tests that
        // care about the target-post-validity branch override this.
        Functions\when('get_post')->alias(
            fn(int $id) => $this->fakePost($id)
        );
    }

    private function fakePost(int $id, string $lang_slug = 'en', array $overrides = []): stdClass
    {
        $post = new stdClass();
        $post->ID          = $id;
        $post->post_title  = "Bio in {$lang_slug}";
        $post->post_status = 'publish';
        $post->post_type   = 'team_member';
        foreach ($overrides as $k => $v) {
            $post->$k = $v;
        }
        return $post;
    }

    // ─── resolve_link ────────────────────────────────────────────────

    public function test_resolve_link_returns_int_for_scalar_post_id(): void
    {
        Functions\when('get_field')->justReturn(702);

        $this->assertSame(702, cdcf_my_team_member_resolve_link(7));
    }

    public function test_resolve_link_returns_first_id_for_array(): void
    {
        // ACF relationship fields with cardinality > 1 return an array.
        // We accept the shape and use the first entry.
        Functions\when('get_field')->justReturn([702, 999]);

        $this->assertSame(702, cdcf_my_team_member_resolve_link(7));
    }

    public function test_resolve_link_returns_wp_post_id(): void
    {
        $post = new WP_Post();
        $post->ID = 702;
        Functions\when('get_field')->justReturn($post);

        $this->assertSame(702, cdcf_my_team_member_resolve_link(7));
    }

    public function test_resolve_link_returns_zero_when_no_link(): void
    {
        Functions\when('get_field')->justReturn(false);

        $this->assertSame(0, cdcf_my_team_member_resolve_link(7));
    }

    public function test_resolve_link_returns_zero_for_anonymous_user(): void
    {
        // No get_field stub: the function must short-circuit on user_id=0
        // before consulting ACF.
        $this->assertSame(0, cdcf_my_team_member_resolve_link(0));
    }

    // ─── collect_group ───────────────────────────────────────────────

    public function test_collect_group_returns_polylang_translations(): void
    {
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
            'it' => 704,
        ]);

        $this->assertSame(
            ['en' => 702, 'de' => 703, 'it' => 704],
            cdcf_my_team_member_collect_group(702)
        );
    }

    public function test_collect_group_falls_back_to_post_lang_when_empty(): void
    {
        // No translations recorded — fall back to a 1-element group
        // built from pll_get_post_language.
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post_language')->justReturn('de');

        $this->assertSame(
            ['de' => 702],
            cdcf_my_team_member_collect_group(702)
        );
    }

    public function test_collect_group_returns_empty_for_invalid_input(): void
    {
        $this->assertSame([], cdcf_my_team_member_collect_group(0));
    }

    // ─── URL host allowlist ──────────────────────────────────────────

    public function test_url_host_ok_accepts_empty_for_clear(): void
    {
        $this->assertTrue(cdcf_my_team_member_url_host_ok('', 'linkedin.com'));
    }

    public function test_url_host_ok_accepts_exact_host(): void
    {
        $this->assertTrue(cdcf_my_team_member_url_host_ok(
            'https://linkedin.com/in/me',
            'linkedin.com'
        ));
    }

    public function test_url_host_ok_accepts_subdomain(): void
    {
        $this->assertTrue(cdcf_my_team_member_url_host_ok(
            'https://www.linkedin.com/in/me',
            'linkedin.com'
        ));
        $this->assertTrue(cdcf_my_team_member_url_host_ok(
            'https://it.linkedin.com/in/me',
            'linkedin.com'
        ));
    }

    public function test_url_host_ok_rejects_unrelated_host(): void
    {
        $this->assertFalse(cdcf_my_team_member_url_host_ok(
            'https://evil.example.org/in/me',
            'linkedin.com'
        ));
    }

    public function test_url_host_ok_rejects_host_with_suffix_lookalike(): void
    {
        // "linkedin.com.evil.org" must NOT match because suffix match
        // requires the dot-prefixed boundary.
        $this->assertFalse(cdcf_my_team_member_url_host_ok(
            'https://linkedin.com.evil.org/in/me',
            'linkedin.com'
        ));
    }

    public function test_url_host_ok_rejects_malformed_url(): void
    {
        $this->assertFalse(cdcf_my_team_member_url_host_ok(
            'not a url',
            'linkedin.com'
        ));
    }

    // ─── permission_callback ─────────────────────────────────────────

    public function test_permission_returns_401_when_anonymous(): void
    {
        $this->stubCommon(0);

        $result = cdcf_rest_my_team_member_permission(new WP_REST_Request());
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_not_logged_in', $result->get_error_code());
    }

    public function test_permission_returns_403_when_no_link(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(false);

        $result = cdcf_rest_my_team_member_permission(new WP_REST_Request());
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_no_team_member_link', $result->get_error_code());
    }

    public function test_permission_returns_true_when_linked(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);

        $this->assertTrue(cdcf_rest_my_team_member_permission(new WP_REST_Request()));
    }

    // ─── GET happy path ──────────────────────────────────────────────

    public function test_get_returns_team_member_id_and_available_languages(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
        ]);
        Functions\when('get_post')->alias(
            fn(int $id) => $id === 702
                ? $this->fakePost(702, 'en', ['post_title' => 'EN Bio'])
                : $this->fakePost(703, 'de', ['post_title' => 'DE Bio'])
        );

        $response = cdcf_rest_get_my_team_member(new WP_REST_Request());

        $this->assertSame(702, $response['team_member_id']);
        $this->assertCount(2, $response['available_languages']);
        $this->assertSame('en', $response['available_languages'][0]['slug']);
        $this->assertSame(702, $response['available_languages'][0]['post_id']);
        $this->assertSame('EN Bio', $response['available_languages'][0]['title']);
        $this->assertSame('de', $response['available_languages'][1]['slug']);
    }

    public function test_get_skips_non_team_member_posts_in_group(): void
    {
        // Defensive against polluted Polylang group (e.g. a non-CPT post
        // somehow linked into the same translation term).
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 9999, // stale/wrong-CPT
        ]);
        Functions\when('get_post')->alias(
            fn(int $id) => $id === 702
                ? $this->fakePost(702, 'en')
                : $this->fakePost(9999, 'de', ['post_type' => 'post'])
        );

        $response = cdcf_rest_get_my_team_member(new WP_REST_Request());

        $this->assertCount(1, $response['available_languages']);
        $this->assertSame('en', $response['available_languages'][0]['slug']);
    }

    // ─── GET /{lang} (per-language read) ─────────────────────────────

    public function test_get_lang_returns_post_content_and_acf_fields(): void
    {
        // Happy path: linked user fetches the DE version of their bio.
        // Returns the flat shape the editor consumes — id, title,
        // content as strings, ACF fields as flat keys. Crucially, no
        // edit_post capability check fires anywhere — the ownership
        // signal is the link + Polylang-group membership alone.
        $this->stubCommon(7);
        Functions\when('get_field')->alias(
            function (string $field, $key) {
                if ($field === 'author_team_member') return 703;
                if ($field === 'member_title') return 'Theologe';
                if ($field === 'member_linkedin_url') return 'https://linkedin.com/in/me';
                if ($field === 'member_github_url') return '';
                return null;
            }
        );
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
        ]);
        Functions\when('get_post')->alias(
            fn(int $id) => $this->fakePost($id, $id === 703 ? 'de' : 'en', [
                'post_title'   => 'Mein Name',
                'post_content' => '<p>Hallo.</p>',
            ])
        );

        $req = new WP_REST_Request();
        $req['lang'] = 'de';
        $response = cdcf_rest_get_my_team_member_lang($req);

        $this->assertSame(703, $response['id']);
        $this->assertSame('Mein Name', $response['title']);
        $this->assertSame('<p>Hallo.</p>', $response['content']);
        $this->assertSame('Theologe', $response['member_title']);
        $this->assertSame('https://linkedin.com/in/me', $response['member_linkedin_url']);
        $this->assertSame('', $response['member_github_url']);
    }

    public function test_get_lang_rejects_lang_not_in_group(): void
    {
        // User asks for the (nonexistent) Italian version of a bio
        // that only has en + de — return a 404, not a 500.
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
        ]);

        $req = new WP_REST_Request();
        $req['lang'] = 'it';
        $response = cdcf_rest_get_my_team_member_lang($req);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_no_translation_for_lang', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    // ─── PATCH happy + edge paths ────────────────────────────────────

    /** Build a WP_REST_Request stand-in with the given params + URL var. */
    private function buildPatchRequest(string $lang, array $params = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $req['lang'] = $lang;
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function test_patch_updates_target_post_and_queues_other_langs(): void
    {
        // User edits German; en/it/es/fr/pt all get a re-translate job
        // pointing at the (now-updated) German post.
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(703);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
            'it' => 704,
            'es' => 705,
            'fr' => 706,
            'pt' => 707,
        ]);

        $captured_update = null;
        Functions\when('wp_update_post')->alias(
            function (array $args, bool $strict = false) use (&$captured_update) {
                unset($strict);
                $captured_update = $args;
                return $args['ID'];
            }
        );
        $captured_fields = [];
        Functions\when('update_field')->alias(
            function (string $field, $value, int $post_id) use (&$captured_fields): bool {
                $captured_fields[$field] = ['value' => $value, 'post_id' => $post_id];
                return true;
            }
        );
        $enqueue_calls = [];
        Functions\when('cdcf_enqueue_post_translation')->alias(
            function (int $source, string $target_lang, int $target_post_id) use (&$enqueue_calls): array {
                $enqueue_calls[] = compact('source', 'target_lang', 'target_post_id');
                return ['post_id' => $target_post_id, 'queue' => 'redis', 'errors' => []];
            }
        );

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('de', [
            'content'             => '<p>Aktualisierte Biografie.</p>',
            'member_title'        => 'Theologe',
            'member_linkedin_url' => 'https://www.linkedin.com/in/me',
            'member_github_url'   => '',
        ]));

        $this->assertSame(703, $response['post_id']);
        $this->assertSame(['en', 'it', 'es', 'fr', 'pt'], $response['queued']);
        $this->assertSame([], $response['errors']);

        // wp_update_post hit the DE post with the new content.
        $this->assertSame(703, $captured_update['ID']);
        $this->assertSame('<p>Aktualisierte Biografie.</p>', $captured_update['post_content']);
        // All three ACF fields written to the DE post.
        $this->assertSame('Theologe', $captured_fields['member_title']['value']);
        $this->assertSame(703, $captured_fields['member_title']['post_id']);
        $this->assertSame('', $captured_fields['member_github_url']['value']);
        // Fan-out: 5 enqueue calls, source = DE post, no DE in targets.
        $this->assertCount(5, $enqueue_calls);
        foreach ($enqueue_calls as $call) {
            $this->assertSame(703, $call['source']);
            $this->assertNotSame('de', $call['target_lang']);
        }
    }

    public function test_patch_skips_content_update_when_not_provided(): void
    {
        // User only changes member_title — bio HTML must not be touched
        // (otherwise a no-content PATCH would wipe it).
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn(['en' => 702]);

        $update_count = 0;
        Functions\when('wp_update_post')->alias(
            function () use (&$update_count): int {
                $update_count++;
                return 702;
            }
        );
        Functions\when('update_field')->justReturn(true);
        Functions\when('cdcf_enqueue_post_translation')->justReturn(['post_id' => 0]);

        cdcf_rest_update_my_team_member($this->buildPatchRequest('en', [
            'member_title' => 'New Title',
        ]));

        $this->assertSame(0, $update_count);
    }

    public function test_patch_rejects_lang_not_in_group(): void
    {
        // The team_member only has en + de translations, but user asks
        // to edit the (nonexistent) Italian version.
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
        ]);
        Functions\expect('wp_update_post')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('it'));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_no_translation_for_lang', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_patch_rejects_invalid_linkedin_host(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn(['en' => 702]);
        Functions\expect('wp_update_post')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('en', [
            'member_linkedin_url' => 'https://evil.example.org/in/me',
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_invalid_url', $response->get_error_code());
        $this->assertStringContainsString('LinkedIn', $response->get_error_message());
    }

    public function test_patch_rejects_invalid_github_host(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn(['en' => 702]);
        Functions\expect('wp_update_post')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('en', [
            'member_github_url' => 'https://gitlab.com/me',
        ]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_invalid_url', $response->get_error_code());
        $this->assertStringContainsString('GitHub', $response->get_error_message());
    }

    public function test_patch_surfaces_wp_update_post_error(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn(['en' => 702]);
        $err = new WP_Error('db_update_failed', 'something exploded', ['status' => 500]);
        Functions\when('wp_update_post')->justReturn($err);
        Functions\expect('update_field')->never();
        Functions\expect('cdcf_enqueue_post_translation')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('en', [
            'content' => '<p>x</p>',
        ]));

        $this->assertSame($err, $response);
    }

    public function test_patch_records_enqueue_errors_without_aborting(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
            'it' => 704,
        ]);
        Functions\when('wp_update_post')->justReturn(702);
        Functions\when('update_field')->justReturn(true);
        Functions\when('cdcf_enqueue_post_translation')->alias(
            // it succeeds, de fails — caller sees the failure in `errors`
            // and the success in `queued`. No exception bubbles.
            fn(int $s, string $lang) => $lang === 'de'
                ? new WP_Error('translation_failed', 'OpenAI down')
                : ['post_id' => 0]
        );

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('en', [
            'content' => '<p>x</p>',
        ]));

        $this->assertSame(['it'], $response['queued']);
        $this->assertCount(1, $response['errors']);
        $this->assertStringContainsString('de:', $response['errors'][0]);
    }

    public function test_patch_rejects_when_target_post_missing_or_wrong_type(): void
    {
        // Polylang group claims an EN entry but get_post returns false
        // (post deleted) or a non-team_member CPT — the handler must
        // 404 rather than wp_update_post against a stale id.
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 9999, // stale
        ]);
        Functions\when('get_post')->alias(
            fn(int $id) => $id === 9999
                ? null
                : $this->fakePost($id, 'en')
        );
        Functions\expect('wp_update_post')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('de'));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_no_translation_for_lang', $response->get_error_code());
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_patch_rejects_when_target_post_is_wrong_cpt(): void
    {
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
        ]);
        Functions\when('get_post')->alias(
            fn(int $id) => $this->fakePost($id, 'en', ['post_type' => 'post'])
        );
        Functions\expect('wp_update_post')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('en'));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_no_translation_for_lang', $response->get_error_code());
    }

    public function test_patch_no_op_skips_fan_out(): void
    {
        // A PATCH with no mutable field supplied (no content, no ACF
        // fields) must NOT enqueue 5 OpenAI re-translation jobs that
        // would produce identical output. Returns the same envelope
        // shape but with empty queued / errors.
        $this->stubCommon(7);
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 702,
            'de' => 703,
            'it' => 704,
        ]);
        Functions\expect('wp_update_post')->never();
        Functions\expect('update_field')->never();
        Functions\expect('cdcf_enqueue_post_translation')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('en'));

        $this->assertSame(702, $response['post_id']);
        $this->assertSame([], $response['queued']);
        $this->assertSame([], $response['errors']);
    }

    /**
     * Ownership invariant. The handler must reject when the requesting
     * user's author_team_member link points at a post that is NOT in
     * the resolved Polylang group of the {lang} target. This guards
     * against the case where collect_group is somehow stale relative
     * to the link (e.g. post moved between Polylang groups).
     */
    public function test_patch_rejects_when_link_outside_resolved_group(): void
    {
        $this->stubCommon(7);
        // User links to post 702, but pll_get_post_translations of 702
        // returns a group that doesn't contain 702 (simulated stale
        // group; can happen if the group term mutated).
        Functions\when('get_field')->justReturn(702);
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 999,  // some other post
            'de' => 998,
        ]);
        Functions\expect('wp_update_post')->never();

        $response = cdcf_rest_update_my_team_member($this->buildPatchRequest('en'));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_forbidden', $response->get_error_code());
    }
}
