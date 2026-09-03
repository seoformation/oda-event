<?php
/**
 * functions.php — Fonctions partagées par inscription.php et contact.php :
 * réponse JSON, détection anti-spam, envoi d'e-mail via SMTP (PHPMailer).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/assets/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/assets/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/assets/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function json_response(bool $success, string $message, array $errors = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'errors' => $errors,
    ]);
    exit;
}

/**
 * Anti-spam basique : champ honeypot rempli, ou formulaire envoyé
 * moins de 3 secondes après son chargement (form_started_at posé en JS).
 */
function is_probable_spam(array $post): bool
{
    if (!empty($post['site_web'] ?? '')) {
        return true;
    }

    $startedAt = isset($post['form_started_at']) ? (int) $post['form_started_at'] : 0;
    if ($startedAt <= 0) {
        return false; // JS désactivé côté client : impossible de mesurer, on laisse passer
    }

    $elapsedMs = (microtime(true) * 1000) - $startedAt;
    return $elapsedMs < 3000;
}

/**
 * Envoie un e-mail via le relais SMTP Hostinger (identifiants dans config.php).
 * Les échecs d'envoi sont journalisés mais ne doivent jamais empêcher
 * l'enregistrement de la demande côté base de données.
 */
function send_mail(string $toAddress, string $toName, string $subject, string $htmlBody, string $altBody, ?string $replyTo = null): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toAddress, $toName);
        if ($replyTo !== null && $replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Erreur envoi e-mail (' . $toAddress . ') : ' . $mail->ErrorInfo);
        return false;
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Habille un contenu HTML (deja echappe par l'appelant) dans le gabarit
 * visuel de la marque OrTra — utilise par tous les e-mails partant du site
 * (confirmation d'inscription, notification admin, contact) pour qu'ils
 * soient reconnaissables au premier coup d'oeil. Mise en page par tableaux
 * et styles inline (obligatoire pour un rendu fiable dans Outlook/Gmail).
 */
function render_email_html(string $bodyHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): string
{
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

    $cta = '';
    if ($ctaUrl !== null && $ctaLabel !== null) {
        $cta = '<tr><td style="padding:4px 40px 8px;" align="center">'
            . '<a href="' . e($ctaUrl) . '" style="display:inline-block; background:#D9A94E; color:#0F3D2A; text-decoration:none; font-weight:700; font-size:15px; padding:13px 30px; border-radius:6px; font-family:' . $font . ';">' . e($ctaLabel) . '</a>'
            . '</td></tr>';
    }

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>OrTra Suisse de l\'Événementiel</title></head>'
        . '<body style="margin:0; padding:0; background:#F4F3EF;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F3EF; padding:32px 16px; font-family:' . $font . ';">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background:#ffffff; border-radius:10px; overflow:hidden;">'
        . '<tr><td style="background:#0F3D2A; padding:26px 40px; text-align:center;">'
        . '<div style="color:#D9A94E; font-size:21px; font-weight:800; letter-spacing:0.02em; font-family:' . $font . ';">OrTra</div>'
        . '<div style="color:#ffffff; font-size:12px; letter-spacing:0.1em; text-transform:uppercase; margin-top:3px; font-family:' . $font . ';">Suisse de l\'Événementiel</div>'
        . '</td></tr>'
        . '<tr><td style="padding:32px 40px 12px; color:#1a1a1a; font-size:15px; line-height:1.65; font-family:' . $font . ';">'
        . $bodyHtml
        . '</td></tr>'
        . $cta
        . '<tr><td style="padding:24px 40px 0;"><div style="border-top:1px solid #E6E2D8;"></div></td></tr>'
        . '<tr><td style="padding:16px 40px 28px; color:#8A897F; font-size:12px; line-height:1.6; font-family:' . $font . ';">'
        . 'Cercle des membres fondateurs · OrTra Suisse de l\'Événementiel · <a href="https://oda-event.ch" style="color:#8A897F;">oda-event.ch</a>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Génère un mot de passe temporaire lisible (sans caractères ambigus type
 * 0/O ou 1/l/I) pour la création rapide d'un compte admin depuis
 * admin.php. Affiché une seule fois à l'admin qui promeut la personne,
 * jamais stocké en clair ni journalisé.
 */
function generer_mot_de_passe_admin(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    $password = '';
    for ($i = 0; $i < 16; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

/**
 * Retire les caractères de contrôle (CR/LF notamment) d'une saisie
 * utilisateur avant usage dans un en-tête d'e-mail (Subject). PHPMailer
 * protège déjà contre l'injection d'en-têtes via ses propriétés, mais on ne
 * dépend pas uniquement de ce comportement interne.
 */
function strip_control_chars(string $value): string
{
    return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
}

/**
 * ---------- Authentification (comptes membres et admins) ----------
 * Sessions PHP natives. Deux realites separees et jamais melangees :
 * $_SESSION['membre_id'] (mon-compte.php) et $_SESSION['admin_id']
 * (admin.php) — un compte membre ne peut jamais agir comme admin et
 * inversement, meme avec le meme e-mail.
 */

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool
{
    start_secure_session();
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    return $submitted !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Verrouillage basique apres echecs de connexion repetes : 5 tentatives,
 * verrouillage 15 minutes. $table doit etre 'membres_inscription' ou
 * 'admins' (jamais une valeur exterieure — toujours un litteral fixe cote
 * appelant, pas de risque d'injection).
 */
function is_login_locked(PDO $pdo, string $table, string $email): bool
{
    $stmt = $pdo->prepare("SELECT login_verrouille_jusqu_a FROM `$table` WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $lockedUntil = $stmt->fetchColumn();
    return $lockedUntil !== false && $lockedUntil !== null && strtotime((string) $lockedUntil) > time();
}

function register_login_failure(PDO $pdo, string $table, string $email): void
{
    $stmt = $pdo->prepare("SELECT id, login_tentatives FROM `$table` WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    $attempts = ((int) $row['login_tentatives']) + 1;
    $lockUntil = $attempts >= 5 ? (new DateTime('+15 minutes'))->format('Y-m-d H:i:s') : null;
    $update = $pdo->prepare("UPDATE `$table` SET login_tentatives = :attempts, login_verrouille_jusqu_a = :lock WHERE id = :id");
    $update->execute([':attempts' => $attempts, ':lock' => $lockUntil, ':id' => $row['id']]);
}

function reset_login_failures(PDO $pdo, string $table, int $id): void
{
    $stmt = $pdo->prepare("UPDATE `$table` SET login_tentatives = 0, login_verrouille_jusqu_a = NULL WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

function require_membre_login(): array
{
    start_secure_session();
    if (empty($_SESSION['membre_id'])) {
        header('Location: connexion.php');
        exit;
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM membres_inscription WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['membre_id']]);
    $membre = $stmt->fetch();
    if (!$membre) {
        session_destroy();
        header('Location: connexion.php');
        exit;
    }
    return $membre;
}

function require_admin_login(): array
{
    start_secure_session();
    if (empty($_SESSION['admin_id'])) {
        header('Location: admin-connexion.php');
        exit;
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        session_destroy();
        header('Location: admin-connexion.php');
        exit;
    }
    return $admin;
}

/**
 * Transmet la decision (accepter/refuser) d'une demande d'adhesion a
 * event-swiss.com, source de verite pour cette decision (voir
 * decideOdaMembership() cote event-swiss.com). Factorise ici car appelee a
 * la fois par admin.php (bouton apres connexion) et decision-adhesion.php
 * (lien a token unique depuis l'e-mail de notification, sans connexion).
 */
function decider_adhesion_event_swiss(string $odaReference, string $decision): array
{
    if (!defined('EVENT_SWISS_API_URL') || !defined('EVENT_SWISS_API_SECRET') || EVENT_SWISS_API_SECRET === '') {
        return ['success' => false, 'error' => "Configuration manquante pour contacter event-swiss.com."];
    }
    $ch = curl_init(rtrim(EVENT_SWISS_API_URL, '/') . '/api/oda/decide-membership');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['oda_reference' => $odaReference, 'decision' => $decision]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . EVENT_SWISS_API_SECRET],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr !== '' || $code < 200 || $code >= 300) {
        error_log('Erreur decide-membership event-swiss.com : ' . ($curlErr !== '' ? $curlErr : (string) $resp));
        return ['success' => false, 'error' => "Erreur lors de la communication avec event-swiss.com. Réessayez, ou traitez la demande depuis le panneau admin event-swiss.com."];
    }
    return ['success' => true, 'error' => null];
}

/**
 * Rendu HTML de la facture (payée), réutilisé par paiement.php et
 * mon-compte.php pour éviter de dupliquer la mise en forme.
 */

// Memes 26 cantons (noms complets en francais, y compris pour les
// formulaires DE/IT) que devenir-membre*.html — la valeur soumise est
// toujours en francais quelle que soit la langue de l'interface, pour
// rester compatible avec le mapping cote event-swiss.com.
const CANTONS_SUISSE = [
    'Argovie', 'Appenzell Rhodes-Intérieures', 'Appenzell Rhodes-Extérieures', 'Berne',
    'Bâle-Campagne', 'Bâle-Ville', 'Fribourg', 'Genève', 'Glaris', 'Grisons', 'Jura',
    'Lucerne', 'Neuchâtel', 'Nidwald', 'Obwald', 'St-Gall', 'Schaffhouse', 'Soleure',
    'Schwyz', 'Thurgovie', 'Tessin', 'Uri', 'Vaud', 'Valais', 'Zoug', 'Zurich',
];

/**
 * Options <option> pour un <select> de canton, reutilise par mon-compte.php.
 */
function render_canton_options(?string $selected): string
{
    $html = '';
    foreach (CANTONS_SUISSE as $canton) {
        $isSelected = $selected !== null && $selected === $canton ? ' selected' : '';
        $html .= '<option value="' . e($canton) . '"' . $isSelected . '>' . e($canton) . '</option>';
    }
    return $html;
}

function render_invoice_html(array $row, string $lang): string
{
    $factureNumero = (string) ($row['facture_numero'] ?? '');
    $datePaiement = $row['paiement_confirme_at'] ? date('d.m.Y', strtotime((string) $row['paiement_confirme_at'])) : date('d.m.Y');
    $montant = (int) $row['paiement_montant_chf'];
    $nomClient = trim((string) $row['prenom'] . ' ' . (string) $row['nom']);
    $entrepriseLigne = $row['entreprise'] ? '<p>' . e((string) $row['entreprise']) . '</p>' : '';

    return '<div style="border:1px solid #e2e2e2; border-radius:8px; padding:1.5rem;">'
        . '<h2 style="margin-top:0; font-size:1.1rem;">' . e(t($lang, 'invoice_title')) . '</h2>'
        . '<p><strong>' . e(t($lang, 'invoice_number')) . ' :</strong> ' . e($factureNumero) . '</p>'
        . '<p><strong>' . e(t($lang, 'invoice_date')) . ' :</strong> ' . e($datePaiement) . '</p>'
        . '<hr style="margin:1rem 0; border:none; border-top:1px solid #e2e2e2;">'
        . '<p>' . e($nomClient) . '</p>'
        . $entrepriseLigne
        . '<hr style="margin:1rem 0; border:none; border-top:1px solid #e2e2e2;">'
        . '<p>' . e(t($lang, 'pay_summary')) . ' — <strong>' . $montant . ' CHF</strong></p>'
        . '<hr style="margin:1rem 0; border:none; border-top:1px solid #e2e2e2;">'
        . '<p style="font-size:0.85rem; color:#666;">' . e(t($lang, 'invoice_paid_to')) . '<br>c/o Clément Rozier, La Petite Camargue 66, 1897 Bouveret</p>'
        . '</div>';
}

/**
 * Encode un bloc JSON-LD en echappant < > pour empecher une injection qui
 * romprait la balise <script> englobante (meme principe que safeJsonLd()
 * cote event-swiss.com).
 */
function safe_json_ld(array $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}

/**
 * Chaines de l'entete/pied de page communes, pour les pages dynamiques
 * (actualites.php, actualite.php) qui n'ont pas de fichier statique par
 * langue comme le reste du site. Volontairement separe de TRANSLATIONS
 * (i18n.php, dedie aux messages de formulaire) pour ne rien risquer de
 * casser la-bas.
 */
const CHROME_STRINGS = [
    'fr' => [
        'accueil' => 'Accueil', 'devenir_membre' => 'Devenir membre', 'contact' => 'Contact',
        'rejoindre' => "Rejoindre l'OrTra", 'faq' => 'FAQ', 'utilite' => 'À quoi sert une OrTra ?',
        'mentions' => 'Mentions légales & protection des données', 'navigation' => 'Navigation',
        'plateforme_titre' => 'Plateforme partenaire',
        'plateforme_texte' => "L'annuaire professionnel du secteur est disponible sur",
        'statuts' => "Statuts de l'association", 'blog' => 'Actualités', 'tarifs' => 'Tarifs',
    ],
    'de' => [
        'accueil' => 'Startseite', 'devenir_membre' => 'Mitglied werden', 'contact' => 'Kontakt',
        'rejoindre' => 'Der OrTra beitreten', 'faq' => 'FAQ', 'utilite' => 'Wozu dient eine OrTra?',
        'mentions' => 'Impressum & Datenschutz', 'navigation' => 'Navigation',
        'plateforme_titre' => 'Partnerplattform',
        'plateforme_texte' => 'Das Branchenverzeichnis ist verfügbar auf',
        'statuts' => 'Vereinsstatuten', 'blog' => 'Aktuelles', 'tarifs' => 'Tarife',
    ],
    'it' => [
        'accueil' => 'Home', 'devenir_membre' => 'Diventare membro', 'contact' => 'Contatto',
        'rejoindre' => "Unisciti all'OrTra", 'faq' => 'FAQ', 'utilite' => "A cosa serve un'OrTra?",
        'mentions' => 'Note legali e protezione dei dati', 'navigation' => 'Navigazione',
        'plateforme_titre' => 'Piattaforma partner',
        'plateforme_texte' => 'La directory professionale del settore è disponibile su',
        'statuts' => "Statuto dell'associazione", 'blog' => 'Novità', 'tarifs' => 'Tariffe',
    ],
];

function cs(string $lang, string $key): string
{
    return CHROME_STRINGS[$lang][$key] ?? CHROME_STRINGS['fr'][$key] ?? $key;
}

/**
 * Entete commune des pages dynamiques publiques (actualites.php,
 * actualite.php). $langSwitchUrls doit fournir les 3 URLs (fr/de/it) de la
 * page equivalente dans chaque langue.
 */
function render_public_header(string $lang, array $langSwitchUrls): string
{
    $logoByLang = ['fr' => 'OrTra_Logo_FR.svg', 'de' => 'OrTra_Logo_DE.svg', 'it' => 'OrTra_Logo_IT.svg'];
    $logo = $logoByLang[$lang] ?? $logoByLang['fr'];
    $langLinks = '';
    foreach (['fr' => 'FR', 'de' => 'DE', 'it' => 'IT'] as $code => $label) {
        $active = $code === $lang ? ' class="is-active"' : '';
        $langLinks .= '<a href="' . e($langSwitchUrls[$code]) . '"' . $active . ' lang="' . $code . '">' . $label . '</a>';
        if ($code !== 'it') {
            $langLinks .= '<span class="sep" aria-hidden="true">·</span>';
        }
    }

    return '<header class="site-header"><div class="container">'
        . '<a href="index' . ($lang === 'fr' ? '' : '.' . $lang) . '.html" class="brand">'
        . '<img src="assets/img/' . $logo . '?v=4" alt="OrTra Suisse de l\'Événementiel" class="brand-logo">'
        . '<span class="visually-hidden">— ' . e(cs($lang, 'accueil')) . '</span></a>'
        . '<button class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="main-nav" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>'
        . '<nav class="main-nav" id="main-nav" data-main-nav aria-label="Navigation principale">'
        . '<a href="devenir-membre' . ($lang === 'fr' ? '' : '.' . $lang) . '.html">' . e(cs($lang, 'devenir_membre')) . '</a>'
        . '<a href="tarifs' . ($lang === 'fr' ? '' : '.' . $lang) . '.html">' . e(cs($lang, 'tarifs')) . '</a>'
        . '<a href="contact' . ($lang === 'fr' ? '' : '.' . $lang) . '.html">' . e(cs($lang, 'contact')) . '</a>'
        . '<div class="lang-switch" aria-label="Choix de la langue">' . $langLinks . '</div>'
        . '<a href="devenir-membre' . ($lang === 'fr' ? '' : '.' . $lang) . '.html" class="btn btn-primary">' . e(cs($lang, 'rejoindre')) . '</a>'
        . '</nav></div></header>';
}

/**
 * Pied de page commun des pages dynamiques publiques. Meme parametre
 * $langSwitchUrls que render_public_header().
 */
function render_public_footer(string $lang, array $langSwitchUrls): string
{
    $suffix = $lang === 'fr' ? '' : '.' . $lang;
    $langLinks = '';
    foreach (['fr' => 'FR', 'de' => 'DE', 'it' => 'IT'] as $code => $label) {
        $active = $code === $lang ? ' class="is-active"' : '';
        $langLinks .= '<a href="' . e($langSwitchUrls[$code]) . '"' . $active . ' lang="' . $code . '">' . $label . '</a>';
        if ($code !== 'it') {
            $langLinks .= '<span class="sep" aria-hidden="true">·</span>';
        }
    }

    return '<footer class="site-footer"><div class="container"><div class="footer-grid">'
        . '<div class="footer-col"><div class="footer-brand">'
        . '<img src="assets/img/OrTra_Logo_Combine_3Langues.svg?v=4" alt="" class="footer-logo">'
        . '<span>OrTra Suisse de l\'Événementiel</span></div>'
        . '<address>OrTra Événementiel Suisse<br>c/o Clément Rozier<br>La Petite Camargue 66<br>1897 Bouveret · Suisse<br><br>'
        . 'Tél. <a href="tel:+41797463885">+41 79 746 38 85</a><br>Siège : Port-Valais, Suisse</address></div>'
        . '<div class="footer-col"><h4>' . e(cs($lang, 'navigation')) . '</h4><ul>'
        . '<li><a href="index' . $suffix . '.html">' . e(cs($lang, 'accueil')) . '</a></li>'
        . '<li><a href="devenir-membre' . $suffix . '.html">' . e(cs($lang, 'devenir_membre')) . '</a></li>'
        . '<li><a href="contact' . $suffix . '.html">' . e(cs($lang, 'contact')) . '</a></li>'
        . '<li><a href="faq' . $suffix . '.html">' . e(cs($lang, 'faq')) . '</a></li>'
        . '<li><a href="utilite-ortra' . $suffix . '.html">' . e(cs($lang, 'utilite')) . '</a></li>'
        . '<li><a href="actualites.php?lang=' . $lang . '">' . e(cs($lang, 'blog')) . '</a></li>'
        . '<li><a href="mentions-legales' . $suffix . '.html">' . e(cs($lang, 'mentions')) . '</a></li>'
        . '</ul></div>'
        . '<div class="footer-col"><h4>' . e(cs($lang, 'plateforme_titre')) . '</h4>'
        . '<p>' . e(cs($lang, 'plateforme_texte')) . ' <a href="https://event-swiss.com" target="_blank" rel="noopener">event-swiss.com</a>.</p></div>'
        . '</div><div class="footer-bottom"><span>Cercle des membres fondateurs · 2026</span>'
        . '<div class="lang-switch" aria-label="Choix de la langue">' . $langLinks . '</div>'
        . '<a href="mentions-legales' . $suffix . '.html">' . e(cs($lang, 'mentions')) . '</a>'
        . '<a href="assets/docs/statuts-ortra-evenementiel-suisse.pdf" target="_blank" rel="noopener">' . e(cs($lang, 'statuts')) . '</a>'
        . '<a href="admin-connexion.php">Admin</a>'
        . '</div></div></footer>';
}

/**
 * Slug URL-safe a partir d'un titre (translitteration des accents, minuscule,
 * tirets). Utilise a la creation d'un article dans admin-article.php ; les
 * appelants doivent verifier l'unicite en base (voir slug_est_disponible()).
 */
function slugify(string $titre): string
{
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titre) ?: $titre;
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

/**
 * Verifie qu'un slug est libre (hors d'un article donne, pour le cas d'une
 * modification qui garde le meme slug).
 */
function slug_est_disponible(PDO $pdo, string $slug, ?int $excludeId = null): bool
{
    if ($excludeId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM articles WHERE slug = :slug AND id != :id');
        $stmt->execute([':slug' => $slug, ':id' => $excludeId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM articles WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
    }
    return $stmt->fetch() === false;
}

/**
 * Convertit un contenu d'article en texte brut (un paragraphe par ligne
 * vide, saisi via un simple <textarea> dans admin-article.php) en HTML
 * echappe. Pas de parsing Markdown : on privilegie un formulaire admin
 * simple plutot qu'un editeur riche, coherent avec le reste du site.
 */
function render_article_paragraphs(string $texte): string
{
    $paragraphes = preg_split('/\R\s*\R/', trim($texte)) ?: [];
    $html = '';
    foreach ($paragraphes as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $html .= '<p>' . nl2br(e($p)) . '</p>';
    }
    return $html;
}
