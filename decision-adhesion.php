<?php
/**
 * decision-adhesion.php — Point d'entree public (pas de connexion requise)
 * pour les liens "Accepter"/"Refuser" a token unique envoyes dans l'e-mail
 * de notification d'une nouvelle demande d'adhesion (voir inscription.php
 * et inscription-event-swiss.php). La decision elle-meme est deleguee a
 * event-swiss.com via decider_adhesion_event_swiss() — meme chemin que le
 * bouton equivalent dans admin.php, pour ne jamais dupliquer cette logique.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$token = trim((string) ($_GET['token'] ?? ''));
$action = (string) ($_GET['action'] ?? '');
$pdo = get_pdo();

$success = false;
$message = "Lien invalide.";

if ($token !== '' && in_array($action, ['accepter', 'refuser'], true)) {
    $column = $action === 'accepter' ? 'accept_token' : 'refuse_token';
    $stmt = $pdo->prepare("SELECT id, payment_token, nom_complet, statut_admission FROM membres_inscription WHERE $column = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $membre = $stmt->fetch();

    if (!$membre) {
        $message = "Ce lien n'est plus valide : il a déjà été utilisé, ou la demande a été traitée depuis le panneau admin.";
    } elseif ($membre['statut_admission'] !== 'en_attente') {
        $message = "Cette demande a déjà été traitée.";
    } else {
        $decision = $action === 'accepter' ? 'approve' : 'reject';
        $result = decider_adhesion_event_swiss((string) $membre['payment_token'], $decision);

        // Invalide les deux liens dans tous les cas (usage unique) : en cas
        // d'echec de communication avec event-swiss.com, on redirige vers
        // admin.php pour reessayer plutot que de laisser le lien reutilisable.
        $upd = $pdo->prepare('UPDATE membres_inscription SET accept_token = NULL, refuse_token = NULL WHERE id = :id');
        $upd->execute([':id' => $membre['id']]);

        if ($result['success']) {
            $success = true;
            $message = $action === 'accepter'
                ? 'Adhésion de ' . $membre['nom_complet'] . ' acceptée.'
                : 'Demande de ' . $membre['nom_complet'] . ' refusée.';
        } else {
            $message = $result['error'] ?? 'Une erreur est survenue.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Décision d'adhésion — OrTra Suisse de l'Événementiel</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=18">
</head>
<body>
<main style="min-height:70vh; display:flex; align-items:center; justify-content:center; padding:2rem;">
  <div style="max-width:480px; text-align:center;">
    <h1 style="font-size:1.5rem; margin-bottom:1rem;"><?= $success ? '✅' : 'ℹ️' ?></h1>
    <p style="font-size:1.05rem; margin-bottom:1.5rem;"><?= e($message) ?></p>
    <a href="admin.php?tab=demandes" class="btn btn-primary">Ouvrir le panneau admin</a>
  </div>
</main>
</body>
</html>
