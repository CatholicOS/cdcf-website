<?php
/**
 * Council review — submitter email templates, one per submission language.
 * Plain text. Placeholders: {title}, {link}, {question}, {outcome}, {summary}.
 */

defined('ABSPATH') || exit;

/** @return array<string, array<string, array{subject: string, body: string}>> */
function cdcf_review_email_strings(): array {
    $sig = "\n\nCatholic Digital Commons Foundation";
    return [
        'en' => [
            'confirmation' => [
                'subject' => 'We received your project: {title}',
                'body'    => "Thank you for submitting \"{title}\" to the Catholic Digital Commons Foundation.\n\nOur review committee will look at it and may write to you with questions. You can follow the review, and answer any questions, on this page:\n\n{link}\n\nKeep this link private: anyone who has it can see the status of your submission. Each email we send contains a new link, and only the most recent one works." . $sig,
            ],
            'question' => [
                'subject' => 'A question about your project: {title}',
                'body'    => "The review committee has a question about \"{title}\":\n\n{question}\n\nPlease answer on your submission's status page:\n\n{link}\n\nThis link replaces any earlier one; only the most recent link works." . $sig,
            ],
            'link' => [
                'subject' => 'Your link to follow your project: {title}',
                'body'    => "Here is a new link to the status page for \"{title}\":\n\n{link}\n\nIt replaces any earlier link. If you did not ask for it, you can ignore this email." . $sig,
            ],
            'decision' => [
                'subject' => 'Decision on your project: {title}',
                'body'    => "The review of \"{title}\" is complete.\n\nDecision: {outcome}\n\n{summary}\n\nYou can see the decision on your submission's status page:\n\n{link}\n\nThis link replaces any earlier one and works for 90 days." . $sig,
            ],
        ],
        'it' => [
            'confirmation' => [
                'subject' => 'Abbiamo ricevuto il tuo progetto: {title}',
                'body'    => "Grazie per aver proposto \"{title}\" alla Catholic Digital Commons Foundation.\n\nIl nostro comitato di revisione lo esaminerà e potrebbe scriverti per farti delle domande. Puoi seguire la revisione, e rispondere alle domande, da questa pagina:\n\n{link}\n\nTieni questo link riservato: chiunque lo abbia può vedere lo stato della tua proposta. Ogni email che inviamo contiene un nuovo link, e funziona solo il più recente." . $sig,
            ],
            'question' => [
                'subject' => 'Una domanda sul tuo progetto: {title}',
                'body'    => "Il comitato di revisione ha una domanda su \"{title}\":\n\n{question}\n\nRispondi dalla pagina di stato della tua proposta:\n\n{link}\n\nQuesto link sostituisce quelli precedenti; funziona solo il più recente." . $sig,
            ],
            'link' => [
                'subject' => 'Il tuo link per seguire il progetto: {title}',
                'body'    => "Ecco un nuovo link alla pagina di stato di \"{title}\":\n\n{link}\n\nSostituisce i link precedenti. Se non l'hai richiesto, puoi ignorare questa email." . $sig,
            ],
            'decision' => [
                'subject' => 'Decisione sul tuo progetto: {title}',
                'body'    => "La revisione di \"{title}\" è conclusa.\n\nDecisione: {outcome}\n\n{summary}\n\nPuoi vedere la decisione nella pagina di stato della tua proposta:\n\n{link}\n\nQuesto link sostituisce quelli precedenti e resta valido per 90 giorni." . $sig,
            ],
        ],
        'es' => [
            'confirmation' => [
                'subject' => 'Hemos recibido tu proyecto: {title}',
                'body'    => "Gracias por presentar \"{title}\" a la Catholic Digital Commons Foundation.\n\nNuestro comité de revisión lo examinará y puede escribirte con preguntas. Puedes seguir la revisión, y responder a las preguntas, en esta página:\n\n{link}\n\nMantén este enlace en privado: cualquiera que lo tenga puede ver el estado de tu propuesta. Cada correo que enviamos contiene un enlace nuevo y solo funciona el más reciente." . $sig,
            ],
            'question' => [
                'subject' => 'Una pregunta sobre tu proyecto: {title}',
                'body'    => "El comité de revisión tiene una pregunta sobre \"{title}\":\n\n{question}\n\nResponde en la página de estado de tu propuesta:\n\n{link}\n\nEste enlace sustituye a los anteriores; solo funciona el más reciente." . $sig,
            ],
            'link' => [
                'subject' => 'Tu enlace para seguir tu proyecto: {title}',
                'body'    => "Aquí tienes un nuevo enlace a la página de estado de \"{title}\":\n\n{link}\n\nSustituye a los enlaces anteriores. Si no lo has pedido, puedes ignorar este correo." . $sig,
            ],
            'decision' => [
                'subject' => 'Decisión sobre tu proyecto: {title}',
                'body'    => "La revisión de \"{title}\" ha concluido.\n\nDecisión: {outcome}\n\n{summary}\n\nPuedes ver la decisión en la página de estado de tu propuesta:\n\n{link}\n\nEste enlace sustituye a los anteriores y es válido durante 90 días." . $sig,
            ],
        ],
        'fr' => [
            'confirmation' => [
                'subject' => 'Nous avons reçu votre projet : {title}',
                'body'    => "Merci d'avoir proposé « {title} » à la Catholic Digital Commons Foundation.\n\nNotre comité d'examen va l'étudier et pourra vous écrire pour vous poser des questions. Vous pouvez suivre l'examen, et répondre aux questions, sur cette page :\n\n{link}\n\nGardez ce lien confidentiel : toute personne qui le possède peut voir l'état de votre proposition. Chaque e-mail que nous envoyons contient un nouveau lien, et seul le plus récent fonctionne." . $sig,
            ],
            'question' => [
                'subject' => 'Une question sur votre projet : {title}',
                'body'    => "Le comité d'examen a une question sur « {title} » :\n\n{question}\n\nVeuillez répondre sur la page de suivi de votre proposition :\n\n{link}\n\nCe lien remplace les précédents ; seul le plus récent fonctionne." . $sig,
            ],
            'link' => [
                'subject' => 'Votre lien pour suivre votre projet : {title}',
                'body'    => "Voici un nouveau lien vers la page de suivi de « {title} » :\n\n{link}\n\nIl remplace les liens précédents. Si vous ne l'avez pas demandé, vous pouvez ignorer cet e-mail." . $sig,
            ],
            'decision' => [
                'subject' => 'Décision concernant votre projet : {title}',
                'body'    => "L'examen de « {title} » est terminé.\n\nDécision : {outcome}\n\n{summary}\n\nVous pouvez consulter la décision sur la page de suivi de votre proposition :\n\n{link}\n\nCe lien remplace les précédents et reste valable 90 jours." . $sig,
            ],
        ],
        'pt' => [
            'confirmation' => [
                'subject' => 'Recebemos o seu projeto: {title}',
                'body'    => "Obrigado por submeter \"{title}\" à Catholic Digital Commons Foundation.\n\nO nosso comité de revisão vai analisá-lo e poderá escrever-lhe com perguntas. Pode acompanhar a revisão, e responder às perguntas, nesta página:\n\n{link}\n\nMantenha este link privado: qualquer pessoa que o tenha pode ver o estado da sua submissão. Cada email que enviamos contém um novo link, e só o mais recente funciona." . $sig,
            ],
            'question' => [
                'subject' => 'Uma pergunta sobre o seu projeto: {title}',
                'body'    => "O comité de revisão tem uma pergunta sobre \"{title}\":\n\n{question}\n\nResponda na página de estado da sua submissão:\n\n{link}\n\nEste link substitui os anteriores; só o mais recente funciona." . $sig,
            ],
            'link' => [
                'subject' => 'O seu link para acompanhar o projeto: {title}',
                'body'    => "Aqui está um novo link para a página de estado de \"{title}\":\n\n{link}\n\nSubstitui os links anteriores. Se não o pediu, pode ignorar este email." . $sig,
            ],
            'decision' => [
                'subject' => 'Decisão sobre o seu projeto: {title}',
                'body'    => "A avaliação de \"{title}\" está concluída.\n\nDecisão: {outcome}\n\n{summary}\n\nPode ver a decisão na página de estado da sua proposta:\n\n{link}\n\nEste link substitui os anteriores e é válido durante 90 dias." . $sig,
            ],
        ],
        'de' => [
            'confirmation' => [
                'subject' => 'Wir haben Ihr Projekt erhalten: {title}',
                'body'    => "Vielen Dank, dass Sie „{title}“ bei der Catholic Digital Commons Foundation eingereicht haben.\n\nUnser Prüfungsausschuss wird es begutachten und sich möglicherweise mit Fragen an Sie wenden. Auf dieser Seite können Sie die Prüfung verfolgen und Fragen beantworten:\n\n{link}\n\nBehandeln Sie diesen Link vertraulich: Wer ihn hat, kann den Status Ihrer Einreichung sehen. Jede E-Mail von uns enthält einen neuen Link, und nur der neueste funktioniert." . $sig,
            ],
            'question' => [
                'subject' => 'Eine Frage zu Ihrem Projekt: {title}',
                'body'    => "Der Prüfungsausschuss hat eine Frage zu „{title}“:\n\n{question}\n\nBitte antworten Sie auf der Statusseite Ihrer Einreichung:\n\n{link}\n\nDieser Link ersetzt alle früheren; nur der neueste funktioniert." . $sig,
            ],
            'link' => [
                'subject' => 'Ihr Link zur Verfolgung Ihres Projekts: {title}',
                'body'    => "Hier ist ein neuer Link zur Statusseite von „{title}“:\n\n{link}\n\nEr ersetzt alle früheren Links. Wenn Sie ihn nicht angefordert haben, können Sie diese E-Mail ignorieren." . $sig,
            ],
            'decision' => [
                'subject' => 'Entscheidung zu Ihrem Projekt: {title}',
                'body'    => "Die Prüfung von „{title}“ ist abgeschlossen.\n\nEntscheidung: {outcome}\n\n{summary}\n\nDie Entscheidung finden Sie auf der Statusseite Ihres Vorschlags:\n\n{link}\n\nDieser Link ersetzt alle früheren und ist 90 Tage gültig." . $sig,
            ],
        ],
    ];
}

/** Same labels as SubmissionStatus.outcome in messages/*.json (a test keeps them in step). */
function cdcf_review_outcome_labels(): array {
    return [
        'en' => ['cdcf' => 'Accepted as a CDCF project', 'community' => 'Accepted as a community project', 'reject' => 'Not accepted'],
        'it' => ['cdcf' => 'Accettato come progetto CDCF', 'community' => 'Accettato come progetto della comunità', 'reject' => 'Non accettato'],
        'es' => ['cdcf' => 'Aceptado como proyecto de la CDCF', 'community' => 'Aceptado como proyecto comunitario', 'reject' => 'No aceptado'],
        'fr' => ['cdcf' => 'Accepté comme projet de la CDCF', 'community' => 'Accepté comme projet communautaire', 'reject' => 'Non retenu'],
        'pt' => ['cdcf' => 'Aceite como projeto CDCF', 'community' => 'Aceite como projeto da comunidade', 'reject' => 'Não aceite'],
        'de' => ['cdcf' => 'Als CDCF-Projekt angenommen', 'community' => 'Als Community-Projekt angenommen', 'reject' => 'Nicht angenommen'],
    ];
}

/** @return array{subject: string, body: string} */
function cdcf_review_render_email(string $template, string $lang, array $vars): array {
    $all    = cdcf_review_email_strings();
    $raw    = $all[$lang][$template] ?? $all['en'][$template];
    $labels = cdcf_review_outcome_labels()[$lang] ?? cdcf_review_outcome_labels()['en'];
    $map    = [];
    foreach (['title', 'link', 'question', 'summary'] as $key) {
        $map['{' . $key . '}'] = (string) ($vars[$key] ?? '');
    }
    $outcome          = (string) ($vars['outcome'] ?? '');
    $map['{outcome}'] = $labels[$outcome] ?? $outcome;
    return ['subject' => strtr($raw['subject'], $map), 'body' => strtr($raw['body'], $map)];
}
