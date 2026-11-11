<?php
/**
 * REST route handler for /cdcf/v1/donation-cancellation.
 *
 * Called server-to-server by the Next.js Stripe webhook handler when a
 * monthly gift starts, is cancelled, reactivated, paused or resumed, or ends
 * after failed payments. It sends up to two emails:
 *
 *   - a confirmation to the donor, in their language, when they cancelled
 *     (not for failed payments: Stripe already emails the donor about those);
 *   - a notice to the Foundation, in English, with the gift and how long it
 *     has run, plus, for a cancellation, the last day and the reason and
 *     comment the donor gave in the Customer Portal.
 *
 * Signed and idempotent like /donation-acknowledgment, with one guard per
 * email, so a retry after one fails resends only that one. A test-mode event
 * sends both to CDCF_DONATION_TEST_INBOX, or nothing when it is unset.
 */

defined('ABSPATH') || exit;

/** Monthly-gift changes that are cancellations: they carry a last day and a reason. */
const CDCF_DONATION_CANCELLATION_KINDS = ['scheduled', 'immediate', 'payment_failed'];

/** Every monthly-gift change this endpoint reports. */
const CDCF_DONATION_NOTICE_KINDS = [
    'scheduled', 'immediate', 'payment_failed', 'started', 'reactivated', 'paused', 'resumed',
];

/**
 * Where the Foundation's donation notices go: CDCF_DONATION_NOTIFY_INBOX,
 * or the site's admin email when it is unset.
 */
function cdcf_donation_notify_inbox(): string {
    if (defined('CDCF_DONATION_NOTIFY_INBOX') && CDCF_DONATION_NOTIFY_INBOX !== '') {
        return (string) CDCF_DONATION_NOTIFY_INBOX;
    }
    return (string) get_option('admin_email');
}

/** Per-locale copy of the donor's cancellation confirmation. */
function cdcf_donation_cancellation_strings(string $locale): array {
    $strings = [
        'en' => [
            'subject'   => 'Your monthly gift has been cancelled',
            'scheduled' => 'Your monthly gift of %1$s%2$s will not renew. You will not be charged after %3$s.',
            'immediate' => 'Your monthly gift of %1$s%2$s has been cancelled. You will not be charged again.',
            'project'   => ' for %s',
            'thanks'    => 'Thank you for every gift you have given. Should you wish to give again, you will always be welcome.',
        ],
        'it' => [
            'subject'   => 'Il tuo dono mensile è stato annullato',
            'scheduled' => 'Il tuo dono mensile di %1$s%2$s non verrà rinnovato. Non riceverai addebiti dopo il %3$s.',
            'immediate' => 'Il tuo dono mensile di %1$s%2$s è stato annullato. Non riceverai altri addebiti.',
            'project'   => ' per %s',
            'thanks'    => 'Grazie per ogni dono che hai fatto. Se un giorno vorrai donare di nuovo, sarà sempre un piacere riaverti con noi.',
        ],
        'es' => [
            'subject'   => 'Su donativo mensual ha sido cancelado',
            'scheduled' => 'Su donativo mensual de %1$s%2$s no se renovará. No se le cobrará después del %3$s.',
            'immediate' => 'Su donativo mensual de %1$s%2$s ha sido cancelado. No se le volverá a cobrar.',
            'project'   => ' para %s',
            'thanks'    => 'Gracias por cada donativo que ha realizado. Si algún día desea volver a donar, siempre nos alegrará contar de nuevo con usted.',
        ],
        'fr' => [
            'subject'   => 'Votre don mensuel a été annulé',
            'scheduled' => 'Votre don mensuel de %1$s%2$s ne sera pas renouvelé. Aucun prélèvement ne sera effectué après le %3$s.',
            'immediate' => 'Votre don mensuel de %1$s%2$s a été annulé. Aucun autre prélèvement ne sera effectué.',
            'project'   => ' pour %s',
            'thanks'    => 'Merci pour chacun de vos dons. Si vous souhaitez donner à nouveau, nous serons toujours heureux de vous retrouver.',
        ],
        'pt' => [
            'subject'   => 'A sua doação mensal foi cancelada',
            'scheduled' => 'A sua doação mensal de %1$s%2$s não será renovada. Não haverá cobranças após %3$s.',
            'immediate' => 'A sua doação mensal de %1$s%2$s foi cancelada. Não haverá mais cobranças.',
            'project'   => ' para %s',
            'thanks'    => 'Obrigado por cada doação que fez. Se um dia quiser voltar a doar, teremos sempre muito gosto em contar novamente consigo.',
        ],
        'de' => [
            'subject'   => 'Ihre monatliche Spende wurde gekündigt',
            'scheduled' => 'Ihre monatliche Spende von %1$s%2$s wird nicht verlängert. Nach dem %3$s erfolgt keine Abbuchung mehr.',
            'immediate' => 'Ihre monatliche Spende von %1$s%2$s wurde gekündigt. Es erfolgen keine weiteren Abbuchungen.',
            'project'   => ' für %s',
            'thanks'    => 'Vielen Dank für jede Ihrer Spenden. Wenn Sie wieder spenden möchten, sind Sie jederzeit herzlich willkommen.',
        ],
    ];

    return $strings[$locale] ?? $strings['en'];
}

/**
 * The Customer Portal's cancellation reasons in words. A custom feedback
 * option, or anything Stripe adds later, is shown as sent.
 */
function cdcf_donation_feedback_label(string $feedback): string {
    $labels = [
        'too_expensive'    => 'Too expensive',
        'missing_features' => 'Needs more features',
        'switched_service' => 'Found an alternative',
        'unused'           => 'No longer needed',
        'customer_service' => 'Customer service',
        'too_complex'      => 'Ease of use',
        'low_quality'      => 'Quality',
        'other'            => 'Other',
    ];
    if ($feedback === '') {
        return 'none given';
    }
    return $labels[$feedback] ?? $feedback;
}

/** The donor's confirmation, as [subject, body]. */
function cdcf_donation_cancellation_donor_mail(WP_REST_Request $request): array {
    $s       = cdcf_donation_cancellation_strings((string) $request['locale']);
    $amount  = '$' . number_format(((int) $request['amount_cents']) / 100, 2);
    $title   = (string) $request['project_title'];
    $project = $title !== '' ? sprintf($s['project'], $title) : '';
    $ends    = wp_date('Y-m-d', (int) $request['ends_at']);
    $line    = $request['kind'] === 'scheduled'
        ? sprintf($s['scheduled'], $amount, $project, $ends)
        : sprintf($s['immediate'], $amount, $project);

    $body = implode("\n", [
        $line,
        '',
        $s['thanks'],
        '',
        '—',
        'Catholic Digital Commons',
    ]);
    return [$s['subject'], $body];
}

/** The Foundation's notice, in English, as [subject, body]. */
function cdcf_donation_cancellation_notice_mail(WP_REST_Request $request): array {
    $amount = '$' . number_format(((int) $request['amount_cents']) / 100, 2);
    $kind   = (string) $request['kind'];

    [$subject_lead, $headline] = [
        'scheduled'      => ['Monthly gift cancelled', 'A donor cancelled their monthly gift. It ends after the period already paid for.'],
        'immediate'      => ['Monthly gift cancelled', 'A monthly gift was cancelled immediately.'],
        'payment_failed' => ['Monthly gift ended after failed payments', 'A monthly gift ended because its payments failed and every retry was declined.'],
        'started'        => ['New monthly gift', 'A new monthly gift has started.'],
        'reactivated'    => ['Monthly gift reactivated', 'A donor withdrew their cancellation: the monthly gift will continue.'],
        'paused'         => ['Monthly gift paused', 'A monthly gift is paused. Stripe resumes it at the end of the pause the donor chose.'],
        'resumed'        => ['Monthly gift resumed', 'A paused monthly gift has resumed.'],
    ][$kind];
    $is_cancellation = in_array($kind, CDCF_DONATION_CANCELLATION_KINDS, true);

    $name  = (string) $request['donor_name'];
    $email = (string) $request['email'];
    $donor = trim($name . ($email !== '' ? " <{$email}>" : ''));

    $title     = (string) $request['project_title_en'];
    $dashboard = 'https://dashboard.stripe.com/'
        . ($request['livemode'] ? '' : 'test/')
        . 'subscriptions/' . rawurlencode((string) $request['subscription_id']);

    $lines = [
        $headline,
        '',
        'Donor: ' . ($donor !== '' ? $donor : 'unknown'),
        'Amount: ' . $amount . ' per month',
        'Designation: ' . ($title !== '' ? $title : 'General fund'),
        'Giving since: ' . wp_date('Y-m-d', (int) $request['started_at']),
    ];
    if ($is_cancellation) {
        $lines[] = 'Last day: ' . wp_date('Y-m-d', (int) $request['ends_at']);
        $lines[] = 'Reason: ' . cdcf_donation_feedback_label((string) $request['feedback']);
        $comment = (string) $request['comment'];
        if ($comment !== '') {
            $lines[] = 'Comment: ' . $comment;
        }
    }
    $lines[] = '';
    $lines[] = 'Stripe: ' . $dashboard;

    return ["{$subject_lead}: {$amount}", implode("\n", $lines)];
}

/**
 * POST /cdcf/v1/donation-cancellation: confirm a cancellation to the donor
 * and tell the Foundation why, once per Stripe event.
 *
 * @return WP_REST_Response|array|WP_Error
 */
function cdcf_rest_donation_cancellation(WP_REST_Request $request) {
    $kind = (string) $request['kind'];
    if (!in_array($kind, CDCF_DONATION_NOTICE_KINDS, true)) {
        return new WP_Error('invalid_kind', 'Unknown cancellation kind.', ['status' => 400]);
    }

    $donor_to  = (string) $request['email'];
    $notice_to = cdcf_donation_notify_inbox();

    // Staging shares the production WordPress backend: test-mode mail must
    // never reach a real donor or the Foundation's inbox.
    if (!$request['livemode']) {
        $test_inbox = cdcf_donation_test_inbox();
        if ($test_inbox === '') {
            return rest_ensure_response(['success' => true, 'skipped' => 'test_mode_no_inbox']);
        }
        $donor_to  = $donor_to !== '' ? $test_inbox : '';
        $notice_to = $test_inbox;
    }

    $mails = [];
    // Only a donor's own cancellation is confirmed to them.
    if (in_array($kind, ['scheduled', 'immediate'], true) && $donor_to !== '') {
        $mails['donor'] = [$donor_to, cdcf_donation_cancellation_donor_mail($request)];
    }
    if ($notice_to !== '') {
        $mails['foundation'] = [$notice_to, cdcf_donation_cancellation_notice_mail($request)];
    }

    $event_id = (string) $request['event_id'];
    $failed   = false;
    foreach ($mails as $which => [$to, [$subject, $body]]) {
        // One guard per email: Stripe retries for three days, and a retry
        // after one email failed must not resend the other.
        $guard_key = 'cdcf_stripe_evt_' . hash('sha256', "{$event_id}:{$which}");
        if (get_transient($guard_key)) {
            continue;
        }
        set_transient($guard_key, 1, 3 * DAY_IN_SECONDS);
        if (!wp_mail($to, $subject, $body)) {
            delete_transient($guard_key);
            $failed = true;
        }
    }

    if ($failed) {
        return new WP_Error(
            'mail_failed',
            'Failed to send a donation cancellation email.',
            ['status' => 500]
        );
    }
    return rest_ensure_response(['success' => true]);
}
