<?php

declare(strict_types=1);

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
}
