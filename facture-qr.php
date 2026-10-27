<?php
/**
 * facture-qr.php — Facture QR-suisse (QR-bill) pour le paiement de la
 * cotisation par virement bancaire, alternative au paiement par carte
 * (paiement.php / Stripe, actuellement non configuré en production).
 *
 * Accessible via le même lien à token unique que paiement.php
 * (?ref=<payment_token>), uniquement une fois la demande acceptée.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

function render_page(string $title, string $bodyHtml): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">'
        . '<link rel="stylesheet" href="assets/css/style.css"></head><body>'
        . '<main><section class="section section--light"><div class="container">'
        . '<div class="form-card" style="max-width:640px;">' . $bodyHtml . '</div>'
        . '</div></section></main></body></html>';
}

$token = trim((string) ($_GET['ref'] ?? ''));

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    render_page(t('fr', 'pay_title'), '<p>' . e(t('fr', 'pay_not_found')) . '</p>');
    exit;
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM membres_inscription WHERE payment_token = :token');
$stmt->execute([':token' => $token]);
$row = $stmt->fetch();

if (!$row) {
    render_page(t('fr', 'pay_title'), '<p>' . e(t('fr', 'pay_not_found')) . '</p>');
    exit;
}

$lang = in_array($row['lang'] ?? 'fr', ['fr', 'de', 'it'], true) ? $row['lang'] : 'fr';

if ($row['paiement_statut'] === 'paye') {
    $html = '<h1 style="margin-top:0;">' . e(t($lang, 'pay_already_paid_title')) . '</h1>'
        . render_invoice_html($row, $lang)
        . '<p style="margin-top:1.5rem;"><button onclick="window.print()" class="btn btn-primary">' . e(t($lang, 'invoice_print')) . '</button></p>';
    render_page(t($lang, 'invoice_title'), $html);
    exit;
}

if ((string) $row['statut_admission'] !== 'accepte') {
    render_page(t($lang, 'pay_title'), '<p>' . e(t($lang, 'pay_not_found')) . '</p>');
    exit;
}

if (QRBILL_IBAN === '') {
    render_page(t($lang, 'pay_title'), '<p>' . e(t($lang, 'pay_unavailable')) . '</p>');
    exit;
}

$payload = build_qrbill_payload($row);
$qrDataUri = generate_qrbill_png_data_uri($payload);

$html = render_qrbill_html($row, $lang, $qrDataUri)
    . '<p style="margin-top:1.5rem;"><button onclick="window.print()" class="btn btn-primary">' . e(t($lang, 'qrbill_print')) . '</button></p>';

render_page(t($lang, 'qrbill_title'), $html);
