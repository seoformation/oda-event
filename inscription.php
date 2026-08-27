<?php
/**
 * inscription.php — Traitement du formulaire d'adhésion (devenir-membre*.html).
 *
 * Étapes : anti-spam -> validation stricte -> insertion MySQL (PDO préparé)
 * -> e-mail de notification à l'association -> e-mail de confirmation au demandeur.
 * Répond toujours en JSON (le formulaire est soumis en AJAX par assets/js/main.js).
 * Les messages sont traduits selon le champ caché "lang" (fr/de/it, voir i18n.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$lang = get_lang($_POST);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, t($lang, 'method_not_allowed'));
}

// Anti-spam : on répond "succès" sans rien traiter ni notifier, pour ne pas
// renseigner les robots sur la détection.
if (is_probable_spam($_POST)) {
    json_response(true, t($lang, 'success_inscription'));
}

$typesMembreValides = ['organisateur', 'prestataire', 'travailleur'];
$cantonsValides = [
    'Argovie', 'Appenzell Rhodes-Intérieures', 'Appenzell Rhodes-Extérieures', 'Berne',
    'Bâle-Campagne', 'Bâle-Ville', 'Fribourg', 'Genève', 'Glaris', 'Grisons', 'Jura',
    'Lucerne', 'Neuchâtel', 'Nidwald', 'Obwald', 'St-Gall', 'Schaffhouse', 'Soleure',
    'Schwyz', 'Thurgovie', 'Tessin', 'Uri', 'Vaud', 'Valais', 'Zoug', 'Zurich',
];

$typeMembre = trim((string) ($_POST['type_membre'] ?? ''));
$nomComplet = trim((string) ($_POST['nom_complet'] ?? ''));
$entreprise = trim((string) ($_POST['entreprise'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$telephone = trim((string) ($_POST['telephone'] ?? ''));
$canton = trim((string) ($_POST['canton'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$consentement = (string) ($_POST['consentement_rgpd'] ?? '') === '1';

$errors = [];

if (!in_array($typeMembre, $typesMembreValides, true)) {
    $errors['type_membre'] = t($lang, 'err_type_membre');
}
if ($nomComplet === '' || mb_strlen($nomComplet) > 150) {
    $errors['nom_complet'] = t($lang, 'err_nom_complet');
}
if (mb_strlen($entreprise) > 150) {
    $errors['entreprise'] = t($lang, 'err_entreprise');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = t($lang, 'err_email');
}
if (mb_strlen($telephone) > 50) {
    $errors['telephone'] = t($lang, 'err_telephone');
}
if (!in_array($canton, $cantonsValides, true)) {
    $errors['canton'] = t($lang, 'err_canton');
}
if (!$consentement) {
    $errors['consentement_rgpd'] = t($lang, 'err_consentement');
}

if (!empty($errors)) {
    json_response(false, t($lang, 'errors_generic'), $errors);
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO membres_inscription
            (type_membre, nom_complet, entreprise, email, telephone, canton, message, consentement_rgpd)
         VALUES
            (:type_membre, :nom_complet, :entreprise, :email, :telephone, :canton, :message, :consentement_rgpd)'
    );
    $stmt->execute([
        ':type_membre' => $typeMembre,
        ':nom_complet' => $nomComplet,
        ':entreprise' => $entreprise !== '' ? $entreprise : null,
        ':email' => $email,
        ':telephone' => $telephone !== '' ? $telephone : null,
        ':canton' => $canton,
        ':message' => $message !== '' ? $message : null,
        ':consentement_rgpd' => 1,
    ]);
} catch (PDOException $ex) {
    error_log('Erreur MySQL inscription.php : ' . $ex->getMessage());
    json_response(false, t($lang, 'db_error'));
}

$labelsType = [
    'organisateur' => t($lang, 'type_organisateur'),
    'prestataire' => t($lang, 'type_prestataire'),
    'travailleur' => t($lang, 'type_travailleur'),
];
$labelType = $labelsType[$typeMembre] ?? $typeMembre;

// E-mail de notification à l'association (toujours en français, langue de travail interne)
$notifSubject = t('fr', 'notif_new_request') . ' — ' . $nomComplet . ' [' . strtoupper($lang) . ']';
$notifHtml = '<h2>' . e(t('fr', 'notif_new_request')) . '</h2>'
    . '<p><strong>Langue du formulaire :</strong> ' . e(strtoupper($lang)) . '</p>'
    . '<p><strong>Type de membre :</strong> ' . e($labelType) . '</p>'
    . '<p><strong>Nom complet :</strong> ' . e($nomComplet) . '</p>'
    . ($entreprise !== '' ? '<p><strong>Entreprise :</strong> ' . e($entreprise) . '</p>' : '')
    . '<p><strong>E-mail :</strong> ' . e($email) . '</p>'
    . ($telephone !== '' ? '<p><strong>Téléphone :</strong> ' . e($telephone) . '</p>' : '')
    . '<p><strong>Canton :</strong> ' . e($canton) . '</p>'
    . ($message !== '' ? '<p><strong>Message :</strong><br>' . nl2br(e($message)) . '</p>' : '');
$notifAlt = "Langue: $lang\nType: $labelType\nNom: $nomComplet\nEntreprise: $entreprise\nEmail: $email\nTéléphone: $telephone\nCanton: $canton\nMessage: $message";

send_mail(MAIL_NOTIFICATION_TO, "OrTra Suisse de l'Événementiel", $notifSubject, $notifHtml, $notifAlt, $email);

// E-mail de confirmation au demandeur, dans sa langue
$confirmSubject = t($lang, 'confirm_subject');
$confirmHtml = '<p>' . e(t($lang, 'confirm_greeting')) . ' ' . e($nomComplet) . ',</p>'
    . '<p>' . e(t($lang, 'confirm_body1')) . ' <strong>' . e($labelType) . '</strong>.</p>'
    . '<p>' . e(t($lang, 'confirm_body2')) . '</p>'
    . '<p>' . t($lang, 'confirm_signature') . '</p>';
$confirmAlt = t($lang, 'confirm_greeting') . " $nomComplet,\n\n"
    . t($lang, 'confirm_body1') . " $labelType.\n"
    . t($lang, 'confirm_body2');

send_mail($email, $nomComplet, $confirmSubject, $confirmHtml, $confirmAlt);

json_response(true, t($lang, 'success_inscription'));
