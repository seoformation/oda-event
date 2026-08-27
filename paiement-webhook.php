<?php
/**
 * paiement-webhook.php — Réception des événements Stripe (paiement.php).
 *
 * Reste public (pas de blocage .htaccess, comme inscription.php/contact.php) :
 * Stripe doit pouvoir y accéder directement. Protégé par la vérification de
 * la signature Stripe-Signature, pas par un blocage d'accès.
 *
 * Traite checkout.session.completed : marque la ligne membres_inscription
 * correspondante (retrouvée via metadata.payment_token) comme payée et
 * génère un numéro de facture déterministe.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

http_response_code(200); // Stripe retente indéfiniment sur non-2xx ; on répond toujours 200 après lecture, les erreurs sont journalisées côté serveur.

if (STRIPE_WEBHOOK_SECRET === '') {
    error_log('paiement-webhook.php appelé mais STRIPE_WEBHOOK_SECRET non configuré.');
    exit;
}

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!verify_stripe_signature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    error_log('paiement-webhook.php : signature Stripe invalide.');
    http_response_code(400);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || ($event['type'] ?? '') !== 'checkout.session.completed') {
    exit; // Autres événements ignorés (on ne s'est abonné qu'à celui-ci côté Stripe, mais on ignore proprement si besoin).
}

$session = $event['data']['object'] ?? [];
$paymentToken = $session['metadata']['payment_token'] ?? null;
$stripeSessionId = $session['id'] ?? null;

if (!$paymentToken || !$stripeSessionId) {
    error_log('paiement-webhook.php : metadata.payment_token ou session id manquant.');
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM membres_inscription WHERE payment_token = :token');
    $stmt->execute([':token' => $paymentToken]);
    $row = $stmt->fetch();

    if (!$row) {
        error_log('paiement-webhook.php : aucune ligne trouvée pour payment_token=' . $paymentToken);
        exit;
    }

    $factureNumero = 'ORTRA-' . date('Y') . '-' . str_pad((string) $row['id'], 5, '0', STR_PAD_LEFT);

    $update = $pdo->prepare(
        'UPDATE membres_inscription
         SET paiement_statut = "paye", paiement_stripe_session_id = :session_id,
             paiement_confirme_at = NOW(), facture_numero = :facture_numero
         WHERE payment_token = :token AND paiement_statut != "paye"'
    );
    $update->execute([
        ':session_id' => $stripeSessionId,
        ':facture_numero' => $factureNumero,
        ':token' => $paymentToken,
    ]);
} catch (PDOException $ex) {
    error_log('paiement-webhook.php : erreur MySQL — ' . $ex->getMessage());
}

/**
 * Vérifie la signature d'un webhook Stripe selon l'algorithme documenté :
 * https://docs.stripe.com/webhooks#verify-manually
 * En-tête "Stripe-Signature: t=<timestamp>,v1=<signature>[,v0=...]"
 * signature = HMAC-SHA256(secret, "<timestamp>.<payload>")
 */
function verify_stripe_signature(string $payload, string $sigHeader, string $secret): bool
{
    if ($sigHeader === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        if ($key === 't') {
            $timestamp = $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || empty($signatures)) {
        return false;
    }

    // Tolérance de 5 minutes pour éviter les attaques par rejeu d'un
    // évènement intercepté, tout en laissant une marge raisonnable pour la
    // latence réseau normale.
    if (abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}
