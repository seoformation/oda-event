<?php
/**
 * statut-webhook.php — Appelé par event-swiss.com (app/api/admin/route.ts,
 * actions approve_oda_membership / reject_oda_membership) quand un admin y
 * accepte ou refuse une demande. Met à jour statut_admission et
 * event_swiss_account_linked côté oda-event.ch — la décision elle-même
 * reste prise sur event-swiss.com, ce fichier ne fait que refléter le
 * résultat.
 *
 * Authentifié par le même secret partagé que EVENT_SWISS_API_SECRET
 * (symétrique : ce secret sert dans les deux sens entre les deux sites).
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
$odaReference = trim((string) ($body['oda_reference'] ?? ''));
$status = (string) ($body['status'] ?? '');
$eventSwissLinked = !empty($body['event_swiss_account_linked']);

$statutMap = ['accepte' => 'accepte', 'refuse' => 'refuse'];
if ($odaReference === '' || !isset($statutMap[$status])) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres invalides']);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE membres_inscription SET statut_admission = :statut, event_swiss_account_linked = :linked,
            accepte_at = CASE WHEN :statut2 = "accepte" THEN NOW() ELSE accepte_at END,
            refuse_at = CASE WHEN :statut3 = "refuse" THEN COALESCE(refuse_at, NOW()) ELSE refuse_at END
         WHERE payment_token = :ref'
    );
    $stmt->execute([
        ':statut' => $statutMap[$status],
        ':statut2' => $statutMap[$status],
        ':statut3' => $statutMap[$status],
        ':linked' => $eventSwissLinked ? 1 : 0,
        ':ref' => $odaReference,
    ]);

    if ($stmt->rowCount() === 0) {
        error_log('statut-webhook.php : aucune ligne trouvée pour oda_reference=' . $odaReference);
    } elseif ($statutMap[$status] === 'accepte' && QRBILL_IBAN !== '') {
        // Facture QR-suisse envoyée par e-mail dès l'acceptation, comme
        // annoncé dans les conditions d'adhésion — best-effort : un échec
        // ici ne doit jamais faire échouer la réponse au webhook.
        $memberStmt = $pdo->prepare('SELECT * FROM membres_inscription WHERE payment_token = :ref');
        $memberStmt->execute([':ref' => $odaReference]);
        $member = $memberStmt->fetch();
        if ($member) {
            try {
                $lang = in_array($member['lang'] ?? 'fr', ['fr', 'de', 'it'], true) ? $member['lang'] : 'fr';
                $payload = build_qrbill_payload($member);
                $qrBytes = generate_qrbill_png_bytes($payload);
                $qrCid = 'qrbill-' . bin2hex(random_bytes(6));
                $bodyHtml = '<p>' . e(t($lang, 'qrbill_email_intro')) . '</p>'
                    . render_qrbill_html($member, $lang, 'cid:' . $qrCid);
                $subject = t($lang, 'qrbill_title') . ' — OrTra';
                send_mail(
                    (string) $member['email'],
                    trim((string) $member['prenom'] . ' ' . (string) $member['nom']),
                    $subject,
                    render_email_html($bodyHtml),
                    t($lang, 'qrbill_intro'),
                    null,
                    ['content' => $qrBytes, 'name' => 'qrbill.png', 'mime' => 'image/png', 'cid' => $qrCid]
                );
            } catch (Throwable $ex) {
                error_log('statut-webhook.php : erreur génération/envoi facture QR — ' . $ex->getMessage());
            }
        }
    }
} catch (PDOException $ex) {
    error_log('statut-webhook.php : erreur MySQL — ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
    exit;
}

echo json_encode(['success' => true]);
