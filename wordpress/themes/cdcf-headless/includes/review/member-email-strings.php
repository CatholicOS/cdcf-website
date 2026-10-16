<?php
/**
 * Council review — emails to council and Board members, in the member's
 * language. Plain text, no review content: the link leads to the
 * workspace, which needs sign-in. Placeholders: {title}, {actor},
 * {outcome}, {link}.
 */

defined('ABSPATH') || exit;

/** @return array<string, array<string, array{subject: string, body: string}>> */
function cdcf_review_member_email_strings(): array {
    $footer = [
        'en' => "\n\nYou receive this email because you are a member of a council or the Board of the Catholic Digital Commons Foundation.",
        'it' => "\n\nRicevi questa email perché fai parte di un consiglio o del Consiglio di amministrazione della Catholic Digital Commons Foundation.",
        'es' => "\n\nRecibes este correo porque eres miembro de un consejo o de la Junta Directiva de la Catholic Digital Commons Foundation.",
        'fr' => "\n\nVous recevez cet e-mail parce que vous êtes membre d'un conseil ou du Conseil d'administration de la Catholic Digital Commons Foundation.",
        'pt' => "\n\nRecebe este email porque é membro de um conselho ou da Direção da Catholic Digital Commons Foundation.",
        'de' => "\n\nSie erhalten diese E-Mail, weil Sie Mitglied eines Rates oder des Vorstands der Catholic Digital Commons Foundation sind.",
    ];
    $strings = [
        'en' => [
            'submission_received' => ['New project to review: {title}', "A new project, \"{title}\", has been submitted for council review.\n\nOpen it in the review workspace:\n\n{link}"],
            'submitter_answered'  => ['The submitter answered: {title}', "The submitter of \"{title}\" has answered the review committee's question.\n\nRead the answer in the review workspace:\n\n{link}"],
            'decision_recorded'   => ['Decision recorded: {title}', "{actor} recorded the decision on \"{title}\": {outcome}.\n\nThe review is now closed. See the decision in the review workspace:\n\n{link}"],
            'admin_override'      => ['Published by an administrator: {title}', "{actor} published \"{title}\" in WordPress while it was under review, so the review is closed. Recorded outcome: {outcome}.\n\n{link}"],
            'reply_posted'        => ['New reply to your review: {title}', "{actor} replied to your review of \"{title}\".\n\nRead the reply in the review workspace:\n\n{link}"],
        ],
        'it' => [
            'submission_received' => ['Nuovo progetto da esaminare: {title}', "È stato proposto un nuovo progetto, \"{title}\", per la revisione dei consigli.\n\nAprilo nell'area di revisione:\n\n{link}"],
            'submitter_answered'  => ['Il proponente ha risposto: {title}', "Il proponente di \"{title}\" ha risposto alla domanda del comitato di revisione.\n\nLeggi la risposta nell'area di revisione:\n\n{link}"],
            'decision_recorded'   => ['Decisione registrata: {title}', "{actor} ha registrato la decisione su \"{title}\": {outcome}.\n\nLa revisione è ora chiusa. Vedi la decisione nell'area di revisione:\n\n{link}"],
            'admin_override'      => ['Pubblicato da un amministratore: {title}', "{actor} ha pubblicato \"{title}\" in WordPress mentre era in revisione, quindi la revisione è chiusa. Esito registrato: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nuova risposta alla tua revisione: {title}', "{actor} ha risposto alla tua revisione di \"{title}\".\n\nLeggi la risposta nell'area di revisione:\n\n{link}"],
        ],
        'es' => [
            'submission_received' => ['Nuevo proyecto para revisar: {title}', "Se ha presentado un nuevo proyecto, \"{title}\", para la revisión de los consejos.\n\nÁbrelo en el espacio de revisión:\n\n{link}"],
            'submitter_answered'  => ['El proponente ha respondido: {title}', "El proponente de \"{title}\" ha respondido a la pregunta del comité de revisión.\n\nLee la respuesta en el espacio de revisión:\n\n{link}"],
            'decision_recorded'   => ['Decisión registrada: {title}', "{actor} registró la decisión sobre \"{title}\": {outcome}.\n\nLa revisión está cerrada. Consulta la decisión en el espacio de revisión:\n\n{link}"],
            'admin_override'      => ['Publicado por un administrador: {title}', "{actor} publicó \"{title}\" en WordPress mientras estaba en revisión, por lo que la revisión está cerrada. Resultado registrado: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nueva respuesta a tu revisión: {title}', "{actor} respondió a tu revisión de \"{title}\".\n\nLee la respuesta en el espacio de revisión:\n\n{link}"],
        ],
        'fr' => [
            'submission_received' => ['Nouveau projet à évaluer : {title}', "Un nouveau projet, « {title} », a été proposé pour l'évaluation des conseils.\n\nOuvrez-le dans l'espace d'évaluation :\n\n{link}"],
            'submitter_answered'  => ['Le proposant a répondu : {title}', "Le proposant de « {title} » a répondu à la question du comité d'évaluation.\n\nLisez la réponse dans l'espace d'évaluation :\n\n{link}"],
            'decision_recorded'   => ['Décision enregistrée : {title}', "{actor} a enregistré la décision sur « {title} » : {outcome}.\n\nL'évaluation est désormais close. Consultez la décision dans l'espace d'évaluation :\n\n{link}"],
            'admin_override'      => ['Publié par un administrateur : {title}', "{actor} a publié « {title} » dans WordPress pendant son évaluation ; l'évaluation est donc close. Résultat enregistré : {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nouvelle réponse à votre évaluation : {title}', "{actor} a répondu à votre évaluation de « {title} ».\n\nLisez la réponse dans l'espace d'évaluation :\n\n{link}"],
        ],
        'pt' => [
            'submission_received' => ['Novo projeto para rever: {title}', "Foi proposto um novo projeto, \"{title}\", para a revisão dos conselhos.\n\nAbra-o no espaço de revisão:\n\n{link}"],
            'submitter_answered'  => ['O proponente respondeu: {title}', "O proponente de \"{title}\" respondeu à pergunta do comité de revisão.\n\nLeia a resposta no espaço de revisão:\n\n{link}"],
            'decision_recorded'   => ['Decisão registada: {title}', "{actor} registou a decisão sobre \"{title}\": {outcome}.\n\nA revisão está agora encerrada. Consulte a decisão no espaço de revisão:\n\n{link}"],
            'admin_override'      => ['Publicado por um administrador: {title}', "{actor} publicou \"{title}\" no WordPress enquanto estava em revisão, pelo que a revisão está encerrada. Resultado registado: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Nova resposta à sua revisão: {title}', "{actor} respondeu à sua revisão de \"{title}\".\n\nLeia a resposta no espaço de revisão:\n\n{link}"],
        ],
        'de' => [
            'submission_received' => ['Neues Projekt zur Prüfung: {title}', "Ein neues Projekt, „{title}“, wurde zur Prüfung durch die Räte eingereicht.\n\nÖffnen Sie es im Prüfbereich:\n\n{link}"],
            'submitter_answered'  => ['Die einreichende Person hat geantwortet: {title}', "Die einreichende Person von „{title}“ hat die Frage des Prüfungsausschusses beantwortet.\n\nLesen Sie die Antwort im Prüfbereich:\n\n{link}"],
            'decision_recorded'   => ['Entscheidung erfasst: {title}', "{actor} hat die Entscheidung zu „{title}“ erfasst: {outcome}.\n\nDie Prüfung ist damit abgeschlossen. Die Entscheidung finden Sie im Prüfbereich:\n\n{link}"],
            'admin_override'      => ['In WordPress veröffentlicht: {title}', "{actor} hat „{title}“ während der Prüfung in WordPress veröffentlicht; die Prüfung ist damit abgeschlossen. Erfasstes Ergebnis: {outcome}.\n\n{link}"],
            'reply_posted'        => ['Neue Antwort auf Ihre Bewertung: {title}', "{actor} hat auf Ihre Bewertung von „{title}“ geantwortet.\n\nLesen Sie die Antwort im Prüfbereich:\n\n{link}"],
        ],
    ];
    $out = [];
    foreach ($strings as $lang => $templates) {
        foreach ($templates as $type => [$subject, $body]) {
            $out[$lang][$type] = ['subject' => $subject, 'body' => $body . $footer[$lang]];
        }
    }
    return $out;
}

/** Stands in for an actor whose account no longer exists. */
function cdcf_review_member_email_someone(string $lang): string {
    $labels = ['en' => 'Someone', 'it' => 'Qualcuno', 'es' => 'Alguien', 'fr' => "Quelqu'un", 'pt' => 'Alguém', 'de' => 'Jemand'];
    return $labels[$lang] ?? $labels['en'];
}

/** @return array{subject: string, body: string}|null Null for an event type that is not emailed. */
function cdcf_review_render_member_email(string $type, string $lang, array $vars): ?array {
    $all = cdcf_review_member_email_strings();
    $raw = $all[$lang][$type] ?? $all['en'][$type] ?? null;
    if ($raw === null) {
        return null;
    }
    $labels  = cdcf_review_outcome_labels()[$lang] ?? cdcf_review_outcome_labels()['en'];
    $outcome = (string) ($vars['outcome'] ?? '');
    $map     = ['{outcome}' => $labels[$outcome] ?? $outcome];
    foreach (['title', 'actor', 'link'] as $key) {
        $map['{' . $key . '}'] = (string) ($vars[$key] ?? '');
    }
    return ['subject' => strtr($raw['subject'], $map), 'body' => strtr($raw['body'], $map)];
}
