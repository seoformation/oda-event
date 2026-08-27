<?php
/**
 * paiement.php — Paiement de la cotisation OrTra par carte (Stripe Checkout)
 * et affichage de la facture une fois le paiement confirmé.
 *
 * Accessible uniquement via le lien envoyé dans l'e-mail d'acceptation
 * (?ref=<payment_token>), généré côté event-swiss.com uniquement après
 * validation admin de la demande d'adhésion — jamais avant.
 *
 * GET  : affiche le récapitulatif + bouton de paiement, ou la facture si
 *        déjà payé.
 * POST : crée une session Stripe Checkout (appel cURL direct à l'API REST
 *        Stripe, pas de SDK) et redirige vers la page de paiement Stripe.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

function render_page(string $title, string $bodyHtml): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="assets/css/style.css"></head><body>'
        . '<main><section class="section section--light"><div class="container">'
        . '<div class="form-card" style="max-width:560px;">' . $bodyHtml . '</div>'
        . '</div></section></main></body></html>';
}

$token = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));

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

// --- Déjà payé : afficher la facture ---
if ($row['paiement_statut'] === 'paye') {
    $html = '<h1 style="margin-top:0;">' . e(t($lang, 'pay_already_paid_title')) . '</h1>'
        . render_invoice_html($row, $lang)
        . '<p style="margin-top:1.5rem;"><button onclick="window.print()" class="btn btn-primary">' . e(t($lang, 'invoice_print')) . '</button></p>';

    render_page(t($lang, 'invoice_title'), $html);
    exit;
}

// --- POST : création de la session Stripe Checkout ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (STRIPE_SECRET_KEY === '') {
        render_page(t($lang, 'pay_title'), '<p>' . e(t($lang, 'pay_unavailable')) . '</p>');
        exit;
    }

    $amountCents = (int) $row['paiement_montant_chf'] * 100;
    $successUrl = rtrim(SITE_URL, '/') . '/paiement.php?ref=' . urlencode($token);
    $cancelUrl = rtrim(SITE_URL, '/') . '/paiement.php?ref=' . urlencode($token);

    $fields = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => (string) $row['email'],
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => 'chf',
        'line_items[0][price_data][unit_amount]' => (string) $amountCents,
        'line_items[0][price_data][product_data][name]' => 'Cotisation OrTra ' . date('Y'),
        'metadata[payment_token]' => $token,
    ];

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = is_string($response) ? json_decode($response, true) : null;
    if ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && !empty($data['url'])) {
        header('Location: ' . $data['url']);
        exit;
    }

    error_log('Erreur création session Stripe Checkout : ' . ($curlError !== '' ? $curlError : (string) $response));
    render_page(t($lang, 'pay_title'), '<p>' . e(t($lang, 'pay_error')) . '</p>');
    exit;
}

// --- GET : récapitulatif + bouton de paiement ---
$montant = (int) $row['paiement_montant_chf'];
$stripeConfigured = STRIPE_SECRET_KEY !== '';

$html = '<h1 style="margin-top:0;">' . e(t($lang, 'pay_title')) . '</h1>'
    . '<p>' . e($row['prenom'] . ' ' . $row['nom']) . '</p>'
    . '<div style="border:1px solid #e2e2e2; border-radius:8px; padding:1.5rem; margin:1.5rem 0;">'
    . '<p>' . e(t($lang, 'pay_summary')) . '</p>'
    . '<p style="font-size:1.4rem; font-weight:700;">' . e(t($lang, 'pay_amount_label')) . ' : ' . $montant . ' CHF</p>'
    . '</div>';

if ($stripeConfigured) {
    $html .= '<form method="post" action="paiement.php?ref=' . e($token) . '">'
        . '<input type="hidden" name="ref" value="' . e($token) . '">'
        . '<button type="submit" class="btn btn-primary btn-block">' . e(t($lang, 'pay_button')) . '</button>'
        . '</form>';
} else {
    $html .= '<p>' . e(t($lang, 'pay_unavailable')) . '</p>';
}

render_page(t($lang, 'pay_title'), $html);
