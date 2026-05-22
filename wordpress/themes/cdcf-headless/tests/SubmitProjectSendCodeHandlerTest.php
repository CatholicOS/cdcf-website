<?php

declare(strict_types=1);

/**
 * Per-handler config for cdcf_rest_submit_project_send_code (the
 * /submit-project/send-code helper). Identical shape to the referral
 * helper except for the IP transient prefix and the spam-scoring
 * fields. Shared branch coverage lives in SendCodeHandlerTestBase.
 */
final class SubmitProjectSendCodeHandlerTest extends SendCodeHandlerTestBase
{
    protected function invokeHandler(WP_REST_Request $request): mixed
    {
        return cdcf_rest_submit_project_send_code($request);
    }

    protected function getIpTransientPrefix(): string
    {
        return 'cdcf_projv_';
    }

    protected function makeRequest(array $overrides = []): WP_REST_Request
    {
        $req = new WP_REST_Request();
        $defaults = [
            'submitter_email' => 'user@example.com',
            'description'     => 'A genuine open-source project for review.',
            'project_name'    => 'Sample OSS Project',
            'honeypot'        => '',
            'elapsed_ms'      => 5000,
        ];
        foreach (array_merge($defaults, $overrides) as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }
}
