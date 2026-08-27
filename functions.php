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
 * Rendu HTML de la facture (payée), réutilisé par paiement.php et
 * mon-compte.php pour éviter de dupliquer la mise en forme.
 */
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
