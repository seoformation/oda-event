<?php
/**
 * cron-relance-paiement.php — Relance automatique des membres acceptés qui
 * n'ont pas réglé leur cotisation 30 jours après l'acceptation (délai fixé
 * par les conditions d'adhésion). Renvoie un e-mail motivant, avec la
 * facture QR-suisse jointe à nouveau, une seule fois par membre.
 *
 * A exécuter une fois par jour via un Cron Job Hostinger (hPanel > Avancé >
 * Cron Jobs) :
 *   php /home/.../public_html/cron-relance-paiement.php 84a746a40527f5a343071b1bb3d0ce66183a21d96d6482ca
 *
 * Appelable aussi en HTTP (GET/POST ?key=...) si un cron shell n'est pas
 * disponible — protégé par CRON_SECRET dans les deux cas, jamais exécuté
 * sans le secret.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$providedKey = $argv[1] ?? ($_GET['key'] ?? $_POST['key'] ?? '');
if (!defined('CRON_SECRET') || CRON_SECRET === '' || !hash_equals(CRON_SECRET, (string) $providedKey)) {
    http_response_code(401);
    echo "Non autorisé.\n";
    exit(1);
}

if (QRBILL_IBAN === '') {
    echo "QRBILL_IBAN non configuré, relance ignorée.\n";
    exit(0);
}

$pdo = get_pdo();
$stmt = $pdo->query(
    "SELECT * FROM membres_inscription
     WHERE statut_admission = 'accepte'
       AND paiement_statut = 'non_paye'
       AND accepte_at IS NOT NULL
       AND accepte_at <= (NOW() - INTERVAL 30 DAY)
       AND relance_paiement_envoyee_at IS NULL"
);
$membres = $stmt->fetchAll();

$envoyes = 0;
foreach ($membres as $membre) {
    $lang = in_array($membre['lang'] ?? 'fr', ['fr', 'de', 'it'], true) ? $membre['lang'] : 'fr';

    try {
        $payload = build_qrbill_payload($membre);
        $qrBytes = generate_qrbill_png_bytes($payload);
        $qrCid = 'qrbill-' . bin2hex(random_bytes(6));

        $bodyHtml = '<p>' . e(t($lang, 'reminder_intro')) . '</p>'
            . '<p>' . e(t($lang, 'reminder_cta_text')) . '</p>'
            . render_qrbill_html($membre, $lang, 'cid:' . $qrCid)
            . '<p style="margin-top:20px;">' . e(t($lang, 'reminder_contact')) . '</p>';

        $sent = send_mail(
            (string) $membre['email'],
            trim((string) $membre['prenom'] . ' ' . (string) $membre['nom']),
            t($lang, 'reminder_subject'),
            render_email_html($bodyHtml),
            t($lang, 'reminder_intro') . ' ' . t($lang, 'reminder_cta_text'),
            null,
            ['content' => $qrBytes, 'name' => 'qrbill.png', 'mime' => 'image/png', 'cid' => $qrCid]
        );

        if ($sent) {
            $update = $pdo->prepare('UPDATE membres_inscription SET relance_paiement_envoyee_at = NOW() WHERE id = :id');
            $update->execute([':id' => $membre['id']]);
            $envoyes++;
        } else {
            error_log('cron-relance-paiement.php : échec envoi pour membre id=' . $membre['id']);
        }
    } catch (Throwable $ex) {
        error_log('cron-relance-paiement.php : erreur pour membre id=' . $membre['id'] . ' — ' . $ex->getMessage());
    }
}

echo $envoyes . " relance(s) envoyée(s) sur " . count($membres) . " membre(s) éligible(s).\n";
