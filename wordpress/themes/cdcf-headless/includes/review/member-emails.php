<?php
/**
 * Council review — email to members on key events (spec: Notifications).
 * The bell shows every event; email goes only to the recipients below,
 * one message each, through the Redis queue when it is available. A
 * queued job holds only the user and event ids: the address and the text
 * are worked out when it runs, after re-checking membership.
 */

defined('ABSPATH') || exit;

/** Emailed to every member except the actor. */
const CDCF_REVIEW_MEMBER_EMAIL_ALL = ['submission_received', 'submitter_answered', 'decision_recorded', 'admin_override'];

const CDCF_REVIEW_LANGUAGES = ['en', 'it', 'es', 'fr', 'pt', 'de'];

/** cdcf_review_event_recorded listener. */
function cdcf_review_email_members_on_event($event_id, $project_id, $type, $actor_user_id, $entry_id = null, $data = []): void {
    unset($project_id);
    if (is_array($data) && !empty($data['backfill'])) {
        // The DB v2 migration's record of earlier publishes: bell only.
        return;
    }
    $recipients = cdcf_review_member_email_recipients(
        (string) $type,
        $actor_user_id === null ? null : (int) $actor_user_id,
        $entry_id === null ? null : (int) $entry_id
    );
    foreach ($recipients as $uid) {
        // Sent inline when the queue is unavailable: a mailer that throws
        // must not fail the request that recorded the event (it has already
        // been applied) nor stop the other recipients' emails.
        try {
            cdcf_review_send_member_email($uid, (int) $event_id);
        } catch (\Throwable $e) {
            // A fixed message: exception text can carry addresses or SMTP details.
            error_log('[cdcf-review] member email raised an error: ' . (string) $type);
        }
    }
}

/** @return list<int> */
function cdcf_review_member_email_recipients(string $type, ?int $actor, ?int $entry_id): array {
    if (in_array($type, CDCF_REVIEW_MEMBER_EMAIL_ALL, true)) {
        return array_values(array_filter(cdcf_review_member_user_ids(), static fn(int $uid) => $uid !== $actor));
    }
    if ($type === 'reply_posted' && $entry_id !== null) {
        $author = cdcf_review_reply_parent_author($entry_id);
        return $author !== null && $author !== $actor && cdcf_review_can($author, 'view') ? [$author] : [];
    }
    return [];
}

/** @return 'queued'|'sent'|'failed'|'skipped' */
function cdcf_review_send_member_email(int $user_id, int $event_id): string {
    if (cdcf_review_enqueue_email(['member' => ['user_id' => $user_id, 'event_id' => $event_id]])) {
        return 'queued';
    }
    return cdcf_review_deliver_member_email($user_id, $event_id);
}

/**
 * Build and send one member email now. 'skipped' (not a failure, so the
 * queue does not retry) when the event is gone, its type is not emailed,
 * the project is gone, or the user is no longer a member or has no valid
 * address.
 *
 * @return 'sent'|'failed'|'skipped'
 */
function cdcf_review_deliver_member_email(int $user_id, int $event_id): string {
    $event = cdcf_review_get_event($event_id);
    $user  = get_userdata($user_id);
    if (!$event || !get_post($event['project_id']) || !$user || !is_email((string) $user->user_email)
        || !cdcf_review_can($user_id, 'view')) {
        return 'skipped';
    }
    $lang  = cdcf_review_user_language($user_id);
    $actor = $event['actor_user_id'] !== null ? get_userdata($event['actor_user_id']) : null;
    $mail  = cdcf_review_render_member_email($event['type'], $lang, [
        // Raw title: get_the_title() would HTML-escape it for a text email.
        'title'   => (string) get_post_field('post_title', $event['project_id']),
        'actor'   => $actor ? (string) $actor->display_name : cdcf_review_member_email_someone($lang),
        'outcome' => (string) ($event['data']['decision'] ?? ''),
        'link'    => cdcf_review_workspace_url(
            $event['project_id'],
            $lang,
            $event['type'] === 'reply_posted' ? $event['entry_id'] : null
        ),
    ]);
    if ($mail === null) {
        return 'skipped';
    }
    if (!wp_mail((string) $user->user_email, $mail['subject'], $mail['body'])) {
        // Never log the address.
        error_log('[cdcf-review] member email could not be sent: ' . $event['type']);
        return 'failed';
    }
    return 'sent';
}

function cdcf_review_user_language(int $user_id): string {
    $lang = substr((string) get_user_locale($user_id), 0, 2);
    return in_array($lang, CDCF_REVIEW_LANGUAGES, true) ? $lang : 'en';
}

/** The workspace on the frontend; English has no locale prefix. */
function cdcf_review_workspace_url(int $project_id, string $lang, ?int $entry_id = null): string {
    $frontend = defined('CDCF_FRONTEND_URL') ? (string) CDCF_FRONTEND_URL : 'http://localhost:3000';
    return rtrim($frontend, '/') . ($lang === 'en' ? '' : '/' . $lang) . '/review/' . $project_id
        . ($entry_id ? '#entry-' . $entry_id : '');
}
