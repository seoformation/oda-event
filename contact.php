<?php
/**
 * contact.php — Traitement du formulaire de contact (contact*.html).
 * Validation stricte + envoi d'e-mail à l'association (pas de stockage en base).
 * Les messages sont traduits selon le champ caché "lang" (fr/de/it, voir i18n.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$lang = get_lang($_POST);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, t($lang, 'method_not_allowed'));
}

if (is_probable_spam($_POST)) {
    json_response(true, t($lang, 'success_contact'));
}

$nom = trim((string) ($_POST['nom'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$errors = [];

if ($nom === '' || mb_strlen($nom) > 150) {
    $errors['nom'] = t($lang, 'err_nom');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = t($lang, 'err_email');
}
if ($message === '' || mb_strlen($message) > 5000) {
    $errors['message'] = t($lang, 'err_message');
}

if (!empty($errors)) {
    json_response(false, t($lang, 'errors_generic'), $errors);
}

$subject = t('fr', 'contact_notif_subject') . ' — ' . $nom . ' [' . strtoupper($lang) . ']';
$html = '<h2>' . e(t('fr', 'contact_notif_subject')) . '</h2>'
    . '<p><strong>Langue du formulaire :</strong> ' . e(strtoupper($lang)) . '</p>'
    . '<p><strong>Nom :</strong> ' . e($nom) . '</p>'
    . '<p><strong>E-mail :</strong> ' . e($email) . '</p>'
    . '<p><strong>Message :</strong><br>' . nl2br(e($message)) . '</p>';
$alt = "Langue: $lang\nNom: $nom\nEmail: $email\n\n$message";

$sent = send_mail(MAIL_NOTIFICATION_TO, "OrTra Suisse de l'Événementiel", $subject, $html, $alt, $email);

if (!$sent) {
    json_response(false, t($lang, 'send_fail'));
}

json_response(true, t($lang, 'success_contact'));
