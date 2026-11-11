<?php
/**
 * REST route handler for /cdcf/v1/donation-acknowledgment.
 *
 * Called server-to-server by the Next.js Stripe webhook handler, which
 * has already verified Stripe's signature. This file owns three things
 * the webhook deliberately does not:
 *
 *   1. Idempotency. Stripe retries a failed webhook for up to three days,
 *      so the guard lives here, at the side-effect boundary, rather than
 *      at the Next.js edge.
 *   2. The test-mode branch. Staging shares the PRODUCTION WordPress
 *      backend, so a staging webhook reaches this handler. Without the
 *      livemode check it would mail real donors from a test payment.
 *   3. All six locales of copy, including the English substantiation
 *      sentence that must never be translated.
 */

defined('ABSPATH') || exit;

/**
 * The key Next.js signs each donation mail request with. Read from the
 * CDCF_DONATION_MAIL_SECRET constant in wp-config.php or the environment
 * variable of the same name; empty when neither is set.
 */
function cdcf_donation_mail_secret(): string {
    return defined('CDCF_DONATION_MAIL_SECRET')
        ? (string) CDCF_DONATION_MAIL_SECRET
        : (string) (getenv('CDCF_DONATION_MAIL_SECRET') ?: '');
}

/**
 * Shared permission callback for both donation endpoints.
 *
 * These endpoints send email in the Foundation's name, so edit_posts alone,
 * which every author and contributor holds, is not enough: the raw body must
 * also carry an HMAC-SHA256 signature (X-CDCF-Signature) under
 * CDCF_DONATION_MAIL_SECRET, which only the Next.js server knows. With no
 * secret configured, every request is refused.
 */
function cdcf_donation_permission_check(WP_REST_Request $request): bool {
    if (!current_user_can('edit_posts')) {
        return false;
    }
    $secret    = cdcf_donation_mail_secret();
    $signature = (string) $request->get_header('X-CDCF-Signature');
    if ($secret === '' || $signature === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $request->get_body(), $secret), $signature);
}

/**
 * Whether a portal link is safe to mail: empty, or an https URL on Stripe's
 * billing.stripe.com host, where Customer Portal sessions live.
 */
function cdcf_donation_is_portal_url(string $url): bool {
    if ($url === '') {
        return true;
    }
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower($parts['host'] ?? '') === 'billing.stripe.com';
}

/** The error returned when a request carries a link outside Stripe's portal. */
function cdcf_donation_invalid_portal_url(): WP_Error {
    return new WP_Error(
        'invalid_portal_url',
        'The portal link must be a billing.stripe.com URL.',
        ['status' => 400]
    );
}

/**
 * Per-locale email copy. The substantiation sentence is deliberately
 * absent — it is appended in English regardless of locale.
 */
function cdcf_donation_email_strings(string $locale): array {
    $strings = [
        'en' => [
            'subject'   => 'Thank you for your gift to the Catholic Digital Commons Foundation',
            'intro'     => 'Thank you for your generous support. The details of your gift are below.',
            'amount'    => 'Amount',
            'date'      => 'Date',
            'frequency' => 'Frequency',
            'once'      => 'One-time gift',
            'monthly'   => 'Monthly gift',
            'project'   => 'Designated toward',
            'manage'    => 'You can update or cancel your monthly gift at any time here:',
        ],
        'it' => [
            'subject'   => 'Grazie per il tuo dono alla Catholic Digital Commons Foundation',
            'intro'     => 'Grazie per il tuo generoso sostegno. Di seguito i dettagli del tuo dono.',
            'amount'    => 'Importo',
            'date'      => 'Data',
            'frequency' => 'Frequenza',
            'once'      => 'Dono una tantum',
            'monthly'   => 'Dono mensile',
            'project'   => 'Destinato a',
            'manage'    => 'Puoi modificare o annullare il tuo dono mensile in qualsiasi momento qui:',
        ],
        'es' => [
            'subject'   => 'Gracias por su donativo a la Catholic Digital Commons Foundation',
            'intro'     => 'Gracias por su generoso apoyo. A continuación encontrará los detalles de su donativo.',
            'amount'    => 'Importe',
            'date'      => 'Fecha',
            'frequency' => 'Frecuencia',
            'once'      => 'Donativo único',
            'monthly'   => 'Donativo mensual',
            'project'   => 'Destinado a',
            'manage'    => 'Puede modificar o cancelar su donativo mensual en cualquier momento aquí:',
        ],
        'fr' => [
            'subject'   => 'Merci pour votre don à la Catholic Digital Commons Foundation',
            'intro'     => 'Merci pour votre généreux soutien. Les détails de votre don figurent ci-dessous.',
            'amount'    => 'Montant',
            'date'      => 'Date',
            'frequency' => 'Fréquence',
            'once'      => 'Don ponctuel',
            'monthly'   => 'Don mensuel',
            'project'   => 'Affecté à',
            'manage'    => 'Vous pouvez modifier ou annuler votre don mensuel à tout moment ici :',
        ],
        'pt' => [
            'subject'   => 'Obrigado pela sua doação à Catholic Digital Commons Foundation',
            'intro'     => 'Obrigado pelo seu generoso apoio. Os detalhes da sua doação estão abaixo.',
            'amount'    => 'Valor',
            'date'      => 'Data',
            'frequency' => 'Frequência',
            'once'      => 'Doação única',
            'monthly'   => 'Doação mensal',
            'project'   => 'Destinada a',
            'manage'    => 'Pode alterar ou cancelar a sua doação mensal a qualquer momento aqui:',
        ],
        'de' => [
            'subject'   => 'Vielen Dank für Ihre Spende an die Catholic Digital Commons Foundation',
            'intro'     => 'Vielen Dank für Ihre großzügige Unterstützung. Die Angaben zu Ihrer Spende finden Sie unten.',
            'amount'    => 'Betrag',
            'date'      => 'Datum',
            'frequency' => 'Häufigkeit',
            'once'      => 'Einmalige Spende',
            'monthly'   => 'Monatliche Spende',
            'project'   => 'Bestimmt für',
            'manage'    => 'Sie können Ihre monatliche Spende jederzeit hier ändern oder kündigen:',
        ],
    ];

    return $strings[$locale] ?? $strings['en'];
}

/**
 * POST /cdcf/v1/donation-acknowledgment: mail the donor (or, in test mode,
 * the QA inbox) one acknowledgment per Stripe event.
 *
 * @return WP_REST_Response|array|WP_Error
 */
function cdcf_rest_donation_acknowledgment(WP_REST_Request $request) {
    if (!cdcf_donation_is_portal_url((string) $request['portal_url'])) {
        return cdcf_donation_invalid_portal_url();
    }

    $event_id  = (string) $request['event_id'];
    $guard_key = 'cdcf_stripe_evt_' . hash('sha256', $event_id);

    // Stripe retries for up to three days. Without this, one transient
    // outage becomes a stack of duplicate thank-yous.
    if (get_transient($guard_key)) {
        return rest_ensure_response(['success' => true, 'duplicate' => true]);
    }
    set_transient($guard_key, 1, 3 * DAY_IN_SECONDS);

    $recipient = (string) $request['email'];

    // Staging shares the production WordPress backend, so a test-mode
    // event must never reach the real donor address.
    if (!$request['livemode']) {
        $test_inbox = defined('CDCF_DONATION_TEST_INBOX') ? CDCF_DONATION_TEST_INBOX : '';
        if ($test_inbox === '') {
            return rest_ensure_response([
                'success' => true,
                'skipped' => 'test_mode_no_inbox',
            ]);
        }
        $recipient = $test_inbox;
    }

    $s      = cdcf_donation_email_strings((string) $request['locale']);
    $amount = '$' . number_format(((int) $request['amount_cents']) / 100, 2);
    // ISO day in the site timezone: no translated month names, and never a
    // day off (date_i18n would treat this UTC timestamp as local time).
    $date   = wp_date('Y-m-d', (int) $request['occurred_at']);
    $freq   = $request['frequency'] === 'monthly' ? $s['monthly'] : $s['once'];

    $lines = [
        $s['intro'],
        '',
        $s['amount'] . ': ' . $amount,
        $s['date'] . ': ' . $date,
        $s['frequency'] . ': ' . $freq,
    ];

    $project_title = (string) $request['project_title'];
    if ($project_title !== '') {
        $lines[] = $s['project'] . ': ' . $project_title;
    }

    $portal_url = (string) $request['portal_url'];
    if ($request['frequency'] === 'monthly' && $portal_url !== '') {
        $lines[] = '';
        $lines[] = $s['manage'];
        $lines[] = $portal_url;
    }

    $lines[] = '';
    $lines[] = '—';
    $lines[] = 'Catholic Digital Commons Foundation';
    if (defined('CDCF_FOUNDATION_ADDRESS')) {
        $lines[] = CDCF_FOUNDATION_ADDRESS;
    }
    if (defined('CDCF_FOUNDATION_EIN')) {
        $lines[] = 'EIN: ' . CDCF_FOUNDATION_EIN;
    }
    $lines[] = '';
    // Never translated: this substantiates a US tax deduction for a US
    // taxpayer, and machine-translated legal boilerplate adds risk for
    // no benefit.
    $lines[] = 'No goods or services were provided in exchange for this contribution.';

    $sent = wp_mail($recipient, $s['subject'], implode("\n", $lines));

    if (!$sent) {
        // Clear the guard so Stripe's retry is actually processed rather
        // than swallowed as a duplicate.
        delete_transient($guard_key);
        return new WP_Error(
            'mail_failed',
            'Failed to send the donation acknowledgment.',
            ['status' => 500]
        );
    }

    return rest_ensure_response(['success' => true]);
}
