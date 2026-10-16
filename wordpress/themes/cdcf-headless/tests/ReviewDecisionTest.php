<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReviewDecisionTest extends TestCase
{
    private array $meta = [];
    private array $posts = [];
    private bool $failPublish = false;
    private $onUpdate = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];
        $this->posts = [];
        $this->failPublish = false;
        $this->onUpdate = null;

        Functions\when('get_post_meta')->alias(fn(int $id, string $k, bool $s = false) => $this->meta[$id][$k] ?? '');
        Functions\when('metadata_exists')->alias(fn($t, $id, $k) => array_key_exists($k, $this->meta[$id] ?? []));
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v): bool {
            $this->meta[$id][$k] = $v;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $k): bool {
            unset($this->meta[$id][$k]);
            return true;
        });
        Functions\when('wp_slash')->returnArg();
        Functions\when('get_post_type')->alias(fn(int $id) => $this->posts[$id]['post_type'] ?? false);
        Functions\when('get_post_status')->alias(fn(int $id) => $this->posts[$id]['post_status'] ?? false);
        Functions\when('set_post_type')->alias(fn(int $id, string $t) => ($this->posts[$id]['post_type'] = $t) ? 1 : 0);
        Functions\when('wp_update_post')->alias(function (array $args, bool $wp_error = false) {
            if ($this->onUpdate) {
                ($this->onUpdate)();
            }
            if ($this->failPublish) {
                return new WP_Error('db', 'nope');
            }
            $this->posts[$args['ID']]['post_status'] = $args['post_status'];
            return $args['ID'];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_cdcf_publishes_the_project(): void
    {
        $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
        $this->assertTrue(cdcf_review_apply_outcome(40, 'cdcf'));
        $this->assertSame('publish', $this->posts[40]['post_status']);
        $this->assertSame('project', $this->posts[40]['post_type']);
    }

    public function test_reject_moves_the_project_to_draft_not_trash(): void
    {
        $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
        $this->assertTrue(cdcf_review_apply_outcome(40, 'reject'));
        $this->assertSame('draft', $this->posts[40]['post_status']);
    }

    public function test_community_converts_fields_type_and_publishes(): void
    {
        $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
        $this->meta[40] = [
            'project_url' => 'https://example.org', '_project_url' => 'field_project_url',
            'project_category' => 'Liturgy', '_project_category' => 'field_project_category',
            'project_repo_url' => 'https://github.com/a/b', '_project_repo_url' => 'field_project_repo_url',
            'project_status' => 'incubating', '_project_status' => 'field_project_status',
            'project_license' => 'MIT', '_project_license' => 'field_project_license',
            'project_leads' => [3], '_project_leads' => 'field_project_leads',
            '_submission_submitter_email' => 'sam@example.org',
        ];

        $this->assertTrue(cdcf_review_apply_outcome(40, 'community'));

        $this->assertSame('community_project', $this->posts[40]['post_type']);
        $this->assertSame('publish', $this->posts[40]['post_status']);
        $m = $this->meta[40];
        $this->assertSame('https://github.com/a/b', $m['project_github_url']);
        $this->assertSame('field_community_project_github_url', $m['_project_github_url']);
        $this->assertSame('field_community_project_url', $m['_project_url']);
        $this->assertSame('field_community_project_category', $m['_project_category']);
        $this->assertSame('https://example.org', $m['project_url']);
        $this->assertSame('Liturgy', $m['project_category']);
        foreach (['project_status', 'project_license', 'project_leads', 'project_repo_url'] as $f) {
            $this->assertArrayNotHasKey($f, $m);
            $this->assertArrayNotHasKey('_' . $f, $m);
        }
        $this->assertSame('1', $m['_cdcf_converted_from_submission']);
        $this->assertSame('sam@example.org', $m['_submission_submitter_email']);
    }

    public function test_failed_conversion_restores_the_project(): void
    {
        $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
        $before = [
            'project_url' => 'https://example.org', '_project_url' => 'field_project_url',
            'project_repo_url' => 'https://github.com/a/b', '_project_repo_url' => 'field_project_repo_url',
            'project_leads' => [3], '_project_leads' => 'field_project_leads',
        ];
        $this->meta[40] = $before;
        $this->failPublish = true;

        $result = cdcf_review_apply_outcome(40, 'community');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('conversion_failed', $result->get_error_code());
        $this->assertSame(500, $result->get_error_data()['status']);
        $this->assertSame('project', $this->posts[40]['post_type']);
        $this->assertSame('pending', $this->posts[40]['post_status']);
        $expected = $before;
        $actual = $this->meta[40];
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_rollback_restores_backslashes_through_update_post_meta_unslashing(): void
    {
        // update_post_meta() unslashes what it is given, like core.
        $unslash = static function ($v) use (&$unslash) {
            return is_array($v) ? array_map($unslash, $v) : (is_string($v) ? stripslashes($v) : $v);
        };
        $slash = static function ($v) use (&$slash) {
            return is_array($v) ? array_map($slash, $v) : (is_string($v) ? addslashes($v) : $v);
        };
        Functions\when('update_post_meta')->alias(function (int $id, string $k, $v) use ($unslash): bool {
            $this->meta[$id][$k] = $unslash($v);
            return true;
        });
        Functions\when('wp_slash')->alias($slash);
        $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
        $this->meta[40] = [
            'project_url' => 'https://example.org/a\\b', '_project_url' => 'field_project_url',
            'project_leads' => ['C:\\x'], '_project_leads' => 'field_project_leads',
        ];
        $this->failPublish = true;

        $this->assertInstanceOf(WP_Error::class, cdcf_review_apply_outcome(40, 'community'));

        $this->assertSame('https://example.org/a\\b', $this->meta[40]['project_url']);
        $this->assertSame(['C:\\x'], $this->meta[40]['project_leads']);
    }

    public function test_failed_publish_reports_an_error(): void
    {
        $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
        $this->failPublish = true;
        $result = cdcf_review_apply_outcome(40, 'cdcf');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('outcome_failed', $result->get_error_code());
    }

    public function test_the_guard_is_on_only_while_the_outcome_runs(): void
    {
        $this->posts[40] = ['post_type' => 'project', 'post_status' => 'pending'];
        $seen = null;
        $this->onUpdate = function () use (&$seen) {
            $seen = cdcf_review_deciding();
        };
        cdcf_review_apply_outcome(40, 'cdcf');
        $this->assertTrue($seen);
        $this->assertFalse(cdcf_review_deciding());
        $this->failPublish = true;
        cdcf_review_apply_outcome(40, 'cdcf');
        $this->assertFalse(cdcf_review_deciding());
    }

    public function test_record_decision_writes_meta_stage_and_token_expiry(): void
    {
        $before = time();
        cdcf_review_record_decision(40, 'community', 7, 'Better as a community project', 'Thank you!');
        $m = $this->meta[40];
        $this->assertSame('community', $m['_cdcf_review_decision']);
        $this->assertSame(7, (int) $m['_cdcf_review_decided_by']);
        $this->assertGreaterThanOrEqual($before, (int) $m['_cdcf_review_decided_at']);
        $this->assertSame('Better as a community project', $m['_cdcf_review_rationale']);
        $this->assertSame('Thank you!', $m['_cdcf_review_summary']);
        $this->assertSame('decided', $m[CDCF_REVIEW_STAGE_META]);
        $this->assertSame((int) $m['_cdcf_review_decided_at'] + CDCF_REVIEW_DECISION_TOKEN_TTL, (int) $m[CDCF_REVIEW_TOKEN_EXPIRES_META]);
        $this->assertArrayNotHasKey('_cdcf_review_admin_override', $m);
    }

    public function test_present_decision_names_the_decider(): void
    {
        Functions\when('get_userdata')->justReturn((object) ['display_name' => 'Fr. A']);
        cdcf_review_record_decision(40, 'cdcf', null, '', '', true);
        $d = cdcf_review_present_decision(40);
        $this->assertSame('cdcf', $d['decision']);
        $this->assertNull($d['decided_by']);
        $this->assertTrue($d['admin_override']);
        cdcf_review_record_decision(40, 'reject', 7, 'r', 's');
        $this->assertSame(['id' => 7, 'name' => 'Fr. A'], cdcf_review_present_decision(40)['decided_by']);
        $this->assertFalse(cdcf_review_present_decision(40)['admin_override']);
    }
}
