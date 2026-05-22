<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

/**
 * Per-handler config for cdcf_rest_send_verification_code (the
 * shared handler behind /refer-local-group/send-code and
 * /refer-community-project/send-code). Shared branch coverage lives
 * in SendCodeHandlerTestBase.
 */
final class SendVerificationCodeHandlerTest extends SendCodeHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_send_verification_code($request);
    }

    protected function getIpTransientPrefix(): string
    {
        return 'cdcf_verify_';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'submitter_email' => 'user@example.com',
            'description'     => 'A real local group looking to join.',
            'group_name'      => 'Rome Chapter',
            'honeypot'        => '',
            'elapsed_ms'      => 5000,
        ];
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    /**
     * Regression test for the project_name fallback: the same handler
     * serves /refer-community-project/send-code, which posts
     * `project_name` rather than `group_name`. The spam scorer must
     * see the full submission text in both cases.
     */
    public function test_spam_scorer_uses_project_name_when_group_name_absent(): void
    {
        $this->stubCommonFunctions();

        $spamInput = null;
        Functions\when('cdcf_is_spam_content')->alias(
            function (string $text) use (&$spamInput): bool {
                $spamInput = $text;
                return false;
            }
        );
        Functions\when('function_exists')->alias(static fn(string $name): bool => true);

        $req = new WP_REST_Request();
        // Mimics /refer-community-project/send-code: project_name set,
        // group_name absent.
        $req->set_param('submitter_email', 'user@example.com');
        $req->set_param('description', 'Project description text.');
        $req->set_param('project_name', 'My Open Source Project');
        $req->set_param('elapsed_ms', 5000);

        $this->invokeHandler($req);

        $this->assertSame(
            'Project description text. My Open Source Project',
            $spamInput
        );
    }
}
