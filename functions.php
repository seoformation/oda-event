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
