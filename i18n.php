<?php
/**
 * i18n.php — Traductions des messages de validation, de confirmation et
 * des e-mails envoyés par inscription.php / contact.php (fr/de/it).
 * La langue est déterminée par le champ caché "lang" posé par chaque
 * page HTML (index.html = fr, index.de.html = de, index.it.html = it).
 */

const TRANSLATIONS = [
    'fr' => [
        'method_not_allowed' => 'Méthode non autorisée.',
        'err_type_membre' => 'Veuillez sélectionner un type de membre valide.',
        'err_nom_complet' => "Merci d'indiquer votre nom complet.",
        'err_entreprise' => "Le nom de l'entreprise est trop long.",
        'err_email' => "Merci d'indiquer une adresse e-mail valide.",
        'err_telephone' => 'Le numéro de téléphone est trop long.',
        'err_canton' => 'Merci de sélectionner un canton dans la liste.',
        'err_consentement' => "L'acceptation du traitement des données est obligatoire.",
        'err_nom' => 'Merci d\'indiquer votre nom.',
        'err_message' => 'Merci de saisir un message (5000 caractères maximum).',
        'errors_generic' => 'Merci de corriger les champs indiqués ci-dessous.',
        'db_error' => "Une erreur technique est survenue. Merci de réessayer dans quelques instants ou de nous contacter par téléphone.",
        'send_fail' => "Impossible d'envoyer votre message pour le moment. Merci de réessayer ou de nous appeler directement.",
        'success_inscription' => "Merci, votre demande a bien été enregistrée, nous revenons vers vous rapidement.",
        'success_contact' => 'Merci, votre message a bien été envoyé. Nous vous répondons rapidement.',
        'type_organisateur' => 'Organisateur / Festival',
        'type_prestataire' => 'Prestataire / Entreprise',
        'type_travailleur' => 'Travailleur',
        'notif_new_request' => "Nouvelle demande d'adhésion OrTra",
        'contact_notif_subject' => 'Nouveau message de contact',
        'confirm_subject' => "Votre demande d'adhésion à l'OrTra Suisse de l'Événementiel",
        'confirm_greeting' => 'Bonjour',
        'confirm_body1' => "Nous avons bien reçu votre demande d'adhésion à l'OrTra Suisse de l'Événementiel en tant que",
        'confirm_body2' => 'Notre équipe revient vers vous rapidement pour la suite du processus.',
        'confirm_signature' => "Cordialement,<br>L'équipe de l'OrTra Suisse de l'Événementiel",
    ],
    'de' => [
        'method_not_allowed' => 'Methode nicht erlaubt.',
        'err_type_membre' => 'Bitte wählen Sie einen gültigen Mitgliedstyp aus.',
        'err_nom_complet' => 'Bitte geben Sie Ihren vollständigen Namen an.',
        'err_entreprise' => 'Der Firmenname ist zu lang.',
        'err_email' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
        'err_telephone' => 'Die Telefonnummer ist zu lang.',
        'err_canton' => 'Bitte wählen Sie einen Kanton aus der Liste.',
        'err_consentement' => 'Die Zustimmung zur Datenbearbeitung ist obligatorisch.',
        'err_nom' => 'Bitte geben Sie Ihren Namen an.',
        'err_message' => 'Bitte geben Sie eine Nachricht ein (maximal 5000 Zeichen).',
        'errors_generic' => 'Bitte korrigieren Sie die unten markierten Felder.',
        'db_error' => 'Ein technischer Fehler ist aufgetreten. Bitte versuchen Sie es in Kürze erneut oder kontaktieren Sie uns telefonisch.',
        'send_fail' => 'Ihre Nachricht konnte derzeit nicht gesendet werden. Bitte versuchen Sie es erneut oder rufen Sie uns direkt an.',
        'success_inscription' => 'Vielen Dank, Ihre Anfrage wurde erfolgreich erfasst, wir melden uns rasch bei Ihnen.',
        'success_contact' => 'Vielen Dank, Ihre Nachricht wurde gesendet. Wir antworten Ihnen rasch.',
        'type_organisateur' => 'Veranstalter / Festival',
        'type_prestataire' => 'Dienstleister / Unternehmen',
        'type_travailleur' => 'Arbeitnehmende(r)',
        'notif_new_request' => 'Neuer Beitrittsantrag OrTra',
        'contact_notif_subject' => 'Neue Kontaktnachricht',
        'confirm_subject' => 'Ihr Beitrittsantrag zur OrTra Schweiz Eventbranche',
        'confirm_greeting' => 'Guten Tag',
        'confirm_body1' => 'Wir haben Ihren Beitrittsantrag zur OrTra Schweiz Eventbranche als',
        'confirm_body2' => 'Unser Team meldet sich rasch bei Ihnen für die weiteren Schritte.',
        'confirm_signature' => 'Freundliche Grüsse,<br>Das Team der OrTra Schweiz Eventbranche',
    ],
    'it' => [
        'method_not_allowed' => 'Metodo non consentito.',
        'err_type_membre' => 'Selezionare un tipo di membro valido.',
        'err_nom_complet' => 'Indicare il proprio nome completo.',
        'err_entreprise' => "Il nome dell'azienda è troppo lungo.",
        'err_email' => 'Indicare un indirizzo e-mail valido.',
        'err_telephone' => 'Il numero di telefono è troppo lungo.',
        'err_canton' => "Selezionare un cantone dall'elenco.",
        'err_consentement' => "L'accettazione del trattamento dei dati è obbligatoria.",
        'err_nom' => 'Indicare il proprio nome.',
        'err_message' => 'Inserire un messaggio (massimo 5000 caratteri).',
        'errors_generic' => 'Correggere i campi indicati qui sotto.',
        'db_error' => 'Si è verificato un errore tecnico. Riprovare tra qualche istante o contattarci telefonicamente.',
        'send_fail' => 'Impossibile inviare il messaggio in questo momento. Riprovare o contattarci telefonicamente.',
        'success_inscription' => 'Grazie, la vostra richiesta è stata registrata con successo, vi ricontatteremo a breve.',
        'success_contact' => 'Grazie, il vostro messaggio è stato inviato. Vi risponderemo a breve.',
        'type_organisateur' => 'Organizzatore / Festival',
        'type_prestataire' => 'Fornitore / Azienda',
        'type_travailleur' => 'Lavoratore',
        'notif_new_request' => 'Nuova richiesta di adesione OrTra',
        'contact_notif_subject' => 'Nuovo messaggio di contatto',
        'confirm_subject' => "La vostra richiesta di adesione all'OrTra Svizzera dell'Eventistica",
        'confirm_greeting' => 'Gentile',
        'confirm_body1' => "Abbiamo ricevuto la vostra richiesta di adesione all'OrTra Svizzera dell'Eventistica come",
        'confirm_body2' => 'Il nostro team vi ricontatterà a breve per il seguito della procedura.',
        'confirm_signature' => "Cordiali saluti,<br>Il team dell'OrTra Svizzera dell'Eventistica",
    ],
];

function get_lang(array $post): string
{
    $lang = strtolower((string) ($post['lang'] ?? 'fr'));
    return in_array($lang, ['fr', 'de', 'it'], true) ? $lang : 'fr';
}

function t(string $lang, string $key): string
{
    return TRANSLATIONS[$lang][$key] ?? TRANSLATIONS['fr'][$key] ?? $key;
}
