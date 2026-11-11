<?php
/**
 * REST route handler for /cdcf/v1/donation-portal-link.
 *
 * Mails a donor a link into Stripe's hosted Customer Portal so they can
 * update or cancel a monthly gift without an account on this site.
 *
 * An empty portal_url means the caller found no Stripe customer for that
 * address. That is not an error: the endpoint reports success either way
 * so its response cannot be used to probe who has donated.
 */

defined('ABSPATH') || exit;

/** Per-locale subject and body for the portal-link email; English fallback. */
function cdcf_donation_portal_email_strings(string $locale): array {
    $strings = [
        'en' => [
            'subject' => 'Manage your monthly gift',
            'intro'   => 'Use the link below to update or cancel your monthly gift to the Catholic Digital Commons Foundation. It expires shortly, so open it soon.',
        ],
        'it' => [
            'subject' => 'Gestisci il tuo dono mensile',
            'intro'   => 'Usa il link qui sotto per modificare o annullare il tuo dono mensile alla Catholic Digital Commons Foundation. Il link scade a breve, quindi aprilo presto.',
        ],
        'es' => [
            'subject' => 'Gestione su donativo mensual',
            'intro'   => 'Use el enlace siguiente para modificar o cancelar su donativo mensual a la Catholic Digital Commons Foundation. Caduca en breve, ábralo pronto.',
        ],
        'fr' => [
            'subject' => 'Gérer votre don mensuel',
            'intro'   => 'Utilisez le lien ci-dessous pour modifier ou annuler votre don mensuel à la Catholic Digital Commons Foundation. Il expire bientôt, ouvrez-le rapidement.',
        ],
        'pt' => [
            'subject' => 'Gerir a sua doação mensal',
            'intro'   => 'Use a ligação abaixo para alterar ou cancelar a sua doação mensal à Catholic Digital Commons Foundation. Expira em breve, abra-a rapidamente.',
        ],
        'de' => [
            'subject' => 'Ihre monatliche Spende verwalten',
            'intro'   => 'Über den folgenden Link können Sie Ihre monatliche Spende an die Catholic Digital Commons Foundation ändern oder kündigen. Der Link läuft bald ab, öffnen Sie ihn zeitnah.',
        ],
    ];

    return $strings[$locale] ?? $strings['en'];
}

/**
 * POST /cdcf/v1/donation-portal-link: mail a donor their Customer Portal
 * link, or do nothing (and still succeed) when no customer was found.
 *
 * @return WP_REST_Response|array|WP_Error
 */
function cdcf_rest_donation_portal_link(WP_REST_Request $request) {
    $portal_url = (string) $request['portal_url'];
    if (!cdcf_donation_is_portal_url($portal_url)) {
        return cdcf_donation_invalid_portal_url();
    }

    // No customer matched. Report success without sending, so the
    // response is identical either way.
    if ($portal_url === '') {
        return rest_ensure_response(['success' => true]);
    }

    $s    = cdcf_donation_portal_email_strings((string) $request['locale']);
    $body = $s['intro'] . "\n\n" . $portal_url . "\n";

    $sent = wp_mail((string) $request['email'], $s['subject'], $body);

    if (!$sent) {
        return new WP_Error(
            'mail_failed',
            'Failed to send the portal link.',
            ['status' => 500]
        );
    }

    return rest_ensure_response(['success' => true]);
}
