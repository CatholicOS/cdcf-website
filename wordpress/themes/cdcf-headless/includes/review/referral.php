<?php
/**
 * Council review — referral identity and submitter accessors.
 *
 * A community referral is a community_project created by
 * POST /cdcf/v1/refer-community-project; it records the referrer under
 * _referral_submitter_* instead of the _submission_submitter_* keys a
 * public project submission uses. These helpers let the review code
 * treat "the person to contact" uniformly.
 */

defined('ABSPATH') || exit;

function cdcf_review_is_referral(int $project_id): bool
{
    return get_post_type($project_id) === 'community_project'
        && (string) get_post_meta($project_id, '_referral_submitter_email', true) !== '';
}

function cdcf_review_kind(int $project_id): string
{
    return cdcf_review_is_referral($project_id) ? 'referral' : 'submission';
}

function cdcf_review_submitter_email(int $project_id): string
{
    $email = (string) get_post_meta($project_id, '_submission_submitter_email', true);

    return $email !== ''
        ? $email
        : (string) get_post_meta($project_id, '_referral_submitter_email', true);
}

function cdcf_review_submitter_name(int $project_id): string
{
    $name = (string) get_post_meta($project_id, '_submission_submitter_name', true);

    return $name !== ''
        ? $name
        : (string) get_post_meta($project_id, '_referral_submitter_name', true);
}
