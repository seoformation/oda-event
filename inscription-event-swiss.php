<?php
/**
 * inscription-event-swiss.php — Reçoit une demande d'adhésion OrTra soumise
 * depuis event-swiss.com par un membre déjà inscrit là-bas (voir
 * app/api/oda/request-membership sur ce dépôt-là). Appel serveur-à-serveur,
 * authentifié par le même secret partagé que /statut-webhook.php (bearer,
 * symétrique). Logique de validation/insertion volontairement dupliquée
 * depuis inscription.php plutôt que partagée : les deux fichiers ont des
 * frontières de confiance différentes (formulaire public anti-spam vs.
 * appel authentifié) et mélanger les deux augmenterait le risque d'une
 * fuite d'un chemin vers l'autre.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$expectedAuthHeader = defined('EVENT_SWISS_API_SECRET') ? 'Bearer ' . EVENT_SWISS_API_SECRET : null;
if (!$expectedAuthHeader || EVENT_SWISS_API_SECRET === '' || !hash_equals($expectedAuthHeader, $authHeader)) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Corps de requête invalide']);
    exit;
}

$lang = in_array($body['lang'] ?? '', ['fr', 'de', 'it'], true) ? $body['lang'] : 'fr';

$cantonsValides = [
    'Argovie', 'Appenzell Rhodes-Intérieures', 'Appenzell Rhodes-Extérieures', 'Berne',
    'Bâle-Campagne', 'Bâle-Ville', 'Fribourg', 'Genève', 'Glaris', 'Grisons', 'Jura',
    'Lucerne', 'Neuchâtel', 'Nidwald', 'Obwald', 'St-Gall', 'Schaffhouse', 'Soleure',
    'Schwyz', 'Thurgovie', 'Tessin', 'Uri', 'Vaud', 'Valais', 'Zoug', 'Zurich',
];
$accountTypesValides = ['private', 'company'];

$accountType = trim((string) ($body['account_type'] ?? ''));
$profileType = trim((string) ($body['profile_type'] ?? ''));
$prenom = strip_control_chars((string) ($body['prenom'] ?? ''));
$nom = strip_control_chars((string) ($body['nom'] ?? ''));
$email = trim((string) ($body['email'] ?? ''));
$telephone = trim((string) ($body['telephone'] ?? ''));
$adresse = trim((string) ($body['adresse'] ?? ''));
$canton = trim((string) ($body['canton'] ?? ''));
$message = trim((string) ($body['message'] ?? ''));
$consentement = (bool) ($body['consentement_rgpd'] ?? false);

$legalName = trim((string) ($body['legal_name'] ?? ''));
$entreprise = trim((string) ($body['entreprise'] ?? ''));
$companyAddress = trim((string) ($body['company_address'] ?? ''));
$companyEmail = trim((string) ($body['company_email'] ?? ''));
$companyPhone = trim((string) ($body['company_phone'] ?? ''));

$password = (string) ($body['password'] ?? '');

$errors = [];
if (!in_array($accountType, $accountTypesValides, true)) $errors[] = 'account_type';
$profileTypeCoherent = ($accountType === 'private' && $profileType === 'talent')
    || ($accountType === 'company' && in_array($profileType, ['provider', 'event'], true));
if (!$profileTypeCoherent) $errors[] = 'profile_type';
if ($prenom === '' || mb_strlen($prenom) > 100) $errors[] = 'prenom';
if ($nom === '' || mb_strlen($nom) > 100) $errors[] = 'nom';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (mb_strlen($telephone) > 50) $errors[] = 'telephone';
if ($adresse === '' || mb_strlen($adresse) > 255) $errors[] = 'adresse';
if (!in_array($canton, $cantonsValides, true)) $errors[] = 'canton';
if (!$consentement) $errors[] = 'consentement_rgpd';
if (mb_strlen($password) < 8) $errors[] = 'password';
if ($accountType === 'company') {
    if ($legalName === '' || mb_strlen($legalName) > 150) $errors[] = 'legal_name';
    if ($entreprise === '' || mb_strlen($entreprise) > 150) $errors[] = 'entreprise';
    if ($companyAddress === '' || mb_strlen($companyAddress) > 255) $errors[] = 'company_address';
    if ($companyEmail !== '' && !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'company_email';
}

$pdo = get_pdo();

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $checkStmt = $pdo->prepare('SELECT id FROM membres_inscription WHERE email = :email');
    $checkStmt->execute([':email' => $email]);
    if ($checkStmt->fetch()) {
        $errors[] = 'email_exists';
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Champs invalides', 'fields' => $errors]);
    exit;
}

$typeMembreParProfil = ['talent' => 'travailleur', 'event' => 'organisateur', 'provider' => 'prestataire'];
$typeMembre = $typeMembreParProfil[$profileType] ?? 'travailleur';
$nomComplet = trim($prenom . ' ' . $nom);
$montantChf = $accountType === 'company' ? 300 : 100;
$paymentToken = bin2hex(random_bytes(16));
$acceptToken = bin2hex(random_bytes(16));
$refuseToken = bin2hex(random_bytes(16));
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
unset($password);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO membres_inscription
            (type_membre, nom_complet, entreprise, email, telephone, canton, message, consentement_rgpd,
             event_swiss_opt_in, event_swiss_account_linked, account_type, profile_type, prenom, nom,
             es_legal_name, es_address, es_company_address, es_company_email, es_company_phone,
             payment_token, paiement_montant_chf, lang, password_hash, accept_token, refuse_token)
         VALUES
            (:type_membre, :nom_complet, :entreprise, :email, :telephone, :canton, :message, 1,
             1, 1, :account_type, :profile_type, :prenom, :nom,
             :es_legal_name, :es_address, :es_company_address, :es_company_email, :es_company_phone,
             :payment_token, :paiement_montant_chf, :lang, :password_hash, :accept_token, :refuse_token)'
    );
    $stmt->execute([
        ':type_membre' => $typeMembre,
        ':nom_complet' => $nomComplet,
        ':entreprise' => $accountType === 'company' ? $entreprise : null,
        ':email' => $email,
        ':telephone' => $telephone !== '' ? $telephone : null,
        ':canton' => $canton,
        ':message' => $message !== '' ? $message : null,
        ':account_type' => $accountType,
        ':profile_type' => $profileType,
        ':prenom' => $prenom,
        ':nom' => $nom,
        ':es_legal_name' => $accountType === 'company' ? $legalName : null,
        ':es_address' => $adresse,
        ':es_company_address' => $accountType === 'company' ? $companyAddress : null,
        ':es_company_email' => $accountType === 'company' && $companyEmail !== '' ? $companyEmail : null,
        ':es_company_phone' => $accountType === 'company' && $companyPhone !== '' ? $companyPhone : null,
        ':payment_token' => $paymentToken,
        ':paiement_montant_chf' => $montantChf,
        ':lang' => $lang,
        ':password_hash' => $passwordHash,
        ':accept_token' => $acceptToken,
        ':refuse_token' => $refuseToken,
    ]);
} catch (PDOException $ex) {
    if ((string) $ex->getCode() === '23000') {
        http_response_code(400);
        echo json_encode(['error' => 'Champs invalides', 'fields' => ['email_exists']]);
        exit;
    }
    error_log('Erreur MySQL inscription-event-swiss.php : ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
    exit;
}

$labelsType = [
    'organisateur' => t($lang, 'type_organisateur'),
    'prestataire' => t($lang, 'type_prestataire'),
    'travailleur' => t($lang, 'type_travailleur'),
];
$labelType = $labelsType[$typeMembre] ?? $typeMembre;

$notifSubject = t('fr', 'notif_new_request') . ' — ' . $nomComplet . ' [' . strtoupper($lang) . ', déjà sur event-swiss.com]';
$notifHtml = render_email_html(
    '<h2 style="margin:0 0 18px; font-size:18px; color:#0F3D2A;">' . e(t('fr', 'notif_new_request')) . '</h2>'
    . '<p style="margin:0 0 10px; color:#8A6317;"><strong>Demande soumise depuis event-swiss.com</strong> — cette personne a déjà un compte event-swiss.com lié à cette adresse e-mail.</p>'
    . '<p style="margin:0 0 10px;"><strong>Langue du formulaire :</strong> ' . e(strtoupper($lang)) . '</p>'
    . '<p style="margin:0 0 10px;"><strong>Type de membre :</strong> ' . e($labelType) . '</p>'
    . '<p style="margin:0 0 10px;"><strong>Nom complet :</strong> ' . e($nomComplet) . '</p>'
    . ($entreprise !== '' ? '<p style="margin:0 0 10px;"><strong>Entreprise :</strong> ' . e($entreprise) . '</p>' : '')
    . '<p style="margin:0 0 10px;"><strong>E-mail :</strong> ' . e($email) . '</p>'
    . ($telephone !== '' ? '<p style="margin:0 0 10px;"><strong>Téléphone :</strong> ' . e($telephone) . '</p>' : '')
    . '<p style="margin:0 0 10px;"><strong>Adresse :</strong> ' . e($adresse) . '</p>'
    . '<p style="margin:0 0 10px;"><strong>Canton :</strong> ' . e($canton) . '</p>'
    . ($message !== '' ? '<p style="margin:0 0 10px;"><strong>Message :</strong><br>' . nl2br(e($message)) . '</p>' : '')
    . '<p style="margin:0 0 18px;"><strong>Cotisation :</strong> ' . $montantChf . ' CHF</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td align="center" style="padding-right:6px;"><a href="' . e(rtrim(SITE_URL, '/') . '/decision-adhesion.php?token=' . $acceptToken . '&action=accepter') . '" style="display:inline-block; background:#0F3D2A; color:#ffffff; text-decoration:none; font-weight:700; font-size:14px; padding:12px 20px; border-radius:6px;">Accepter</a></td>'
    . '<td align="center" style="padding-left:6px;"><a href="' . e(rtrim(SITE_URL, '/') . '/decision-adhesion.php?token=' . $refuseToken . '&action=refuser') . '" style="display:inline-block; background:#ffffff; color:#B3261E; border:1px solid #B3261E; text-decoration:none; font-weight:700; font-size:14px; padding:11px 20px; border-radius:6px;">Refuser</a></td>'
    . '</tr></table>'
    . '<p style="margin:12px 0 0; font-size:12px; color:#8A897F;">Chaque lien n\'est valable qu\'une fois.</p>',
    rtrim(SITE_URL, '/') . '/admin.php?tab=demandes',
    'Traiter la demande'
);
$notifAlt = "Demande soumise depuis event-swiss.com\nLangue: $lang\nType: $labelType\nNom: $nomComplet\nEntreprise: $entreprise\nEmail: $email\nTéléphone: $telephone\nAdresse: $adresse\nCanton: $canton\nMessage: $message\nCotisation: $montantChf CHF";
send_mail(MAIL_NOTIFICATION_TO, "OrTra Suisse de l'Événementiel", $notifSubject, $notifHtml, $notifAlt, $email);

$confirmSubject = t($lang, 'confirm_subject');
$confirmHtml = render_email_html(
    '<p style="margin:0 0 14px;">' . e(t($lang, 'confirm_greeting')) . ' ' . e($nomComplet) . ',</p>'
    . '<p style="margin:0 0 14px;">' . e(t($lang, 'confirm_body1')) . ' <strong>' . e($labelType) . '</strong>.</p>'
    . '<p style="margin:0 0 14px;">' . e(t($lang, 'confirm_body2')) . '</p>'
    . '<p style="margin:0 0 18px;">' . e(t($lang, 'confirm_account')) . '</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
    . '<a href="' . e(rtrim(SITE_URL, '/') . '/connexion.php') . '" style="display:inline-block; background:#D9A94E; color:#0F3D2A; text-decoration:none; font-weight:700; font-size:15px; padding:13px 30px; border-radius:6px;">' . e(t($lang, 'confirm_login_cta')) . '</a>'
    . '</td></tr></table>'
    . '<p style="margin:20px 0 0;">' . t($lang, 'confirm_signature') . '</p>'
);
$confirmAlt = t($lang, 'confirm_greeting') . " $nomComplet,\n\n"
    . t($lang, 'confirm_body1') . " $labelType.\n"
    . t($lang, 'confirm_body2') . "\n"
    . t($lang, 'confirm_account');
send_mail($email, $nomComplet, $confirmSubject, $confirmHtml, $confirmAlt);

echo json_encode(['success' => true, 'oda_reference' => $paymentToken]);
