<?php
/**
 * admin.php — Espace équipe OrTra : gestion des événements affichés aux
 * membres, envoi de messages, et vue en lecture seule des demandes
 * d'adhésion (le statut vient de statut-webhook.php, appelé par
 * event-swiss.com — l'acceptation/refus reste décidée là-bas).
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$admin = require_admin_login();
$pdo = get_pdo();
$tab = in_array($_GET['tab'] ?? '', ['evenements', 'messages', 'demandes', 'equipe'], true) ? $_GET['tab'] : 'demandes';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'creer_evenement') {
        $titre = trim((string) ($_POST['titre'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $dateDebut = trim((string) ($_POST['date_debut'] ?? ''));
        $dateFin = trim((string) ($_POST['date_fin'] ?? ''));
        $lieu = trim((string) ($_POST['lieu'] ?? ''));
        if ($titre !== '' && $dateDebut !== '') {
            $stmt = $pdo->prepare(
                'INSERT INTO evenements (titre, description, date_debut, date_fin, lieu, publie, created_by)
                 VALUES (:titre, :description, :date_debut, :date_fin, :lieu, 1, :created_by)'
            );
            $stmt->execute([
                ':titre' => $titre,
                ':description' => $description !== '' ? $description : null,
                ':date_debut' => $dateDebut,
                ':date_fin' => $dateFin !== '' ? $dateFin : null,
                ':lieu' => $lieu !== '' ? $lieu : null,
                ':created_by' => $admin['id'],
            ]);
            $notice = "Événement créé.";
        }
        $tab = 'evenements';
    } elseif ($action === 'supprimer_evenement') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM evenements WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $notice = "Événement supprimé.";
        $tab = 'evenements';
    } elseif ($action === 'basculer_publication') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE evenements SET publie = 1 - publie WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $tab = 'evenements';
    } elseif ($action === 'creer_admin') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if ($email === '' || $nom === '') {
            $notice = "E-mail et nom sont obligatoires.";
        } elseif (mb_strlen($password) < 8) {
            $notice = "Le mot de passe doit contenir au moins 8 caractères.";
        } elseif ($password !== $passwordConfirm) {
            $notice = "Les mots de passe ne correspondent pas.";
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO admins (email, password_hash, nom) VALUES (:email, :password_hash, :nom)');
                $stmt->execute([
                    ':email' => $email,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':nom' => $nom,
                ]);
                $notice = "Compte admin créé pour " . $nom . ".";
            } catch (PDOException $ex) {
                $notice = $ex->getCode() === '23000'
                    ? "Un compte admin existe déjà avec cet e-mail."
                    : "Erreur lors de la création du compte.";
            }
        }
        $tab = 'equipe';
    } elseif ($action === 'envoyer_message') {
        $destinataire = trim((string) ($_POST['destinataire'] ?? '')); // 'tous' ou id membre
        $titre = trim((string) ($_POST['titre'] ?? ''));
        $corps = trim((string) ($_POST['corps'] ?? ''));
        if ($titre !== '' && $corps !== '') {
            $membreId = $destinataire === 'tous' ? null : (int) $destinataire;
            $stmt = $pdo->prepare(
                'INSERT INTO messages_membres (membre_id, titre, corps, envoye_par) VALUES (:membre_id, :titre, :corps, :envoye_par)'
            );
            $stmt->execute([':membre_id' => $membreId, ':titre' => $titre, ':corps' => $corps, ':envoye_par' => $admin['id']]);
            $notice = "Message envoyé.";
        }
        $tab = 'messages';
    }
}

$evenements = $pdo->query('SELECT * FROM evenements ORDER BY date_debut DESC')->fetchAll();
$messages = $pdo->query(
    'SELECT m.*, CONCAT(mi.prenom, " ", mi.nom) AS destinataire_nom
     FROM messages_membres m LEFT JOIN membres_inscription mi ON mi.id = m.membre_id
     ORDER BY m.created_at DESC LIMIT 50'
)->fetchAll();
$demandes = $pdo->query('SELECT id, prenom, nom, email, account_type, profile_type, statut_admission, paiement_statut, date_inscription FROM membres_inscription ORDER BY date_inscription DESC')->fetchAll();
$membresPourMessage = $pdo->query('SELECT id, prenom, nom, email FROM membres_inscription ORDER BY nom, prenom')->fetchAll();
$admins = $pdo->query('SELECT id, email, nom, created_at FROM admins ORDER BY created_at DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Espace admin — OrTra Suisse de l'Événementiel</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main>
  <section class="section section--light">
    <div class="container">
      <div class="form-card" style="max-width:900px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
          <h1 style="margin:0; font-size:1.4rem;">Espace admin OrTra</h1>
          <a href="admin-deconnexion.php" class="btn btn-secondary" style="font-size:0.85rem;">Se déconnecter</a>
        </div>

        <?php if ($notice !== ''): ?>
          <div class="form-alert is-visible form-alert--success" role="status"><?= e($notice) ?></div>
        <?php endif; ?>

        <nav style="display:flex; gap:1rem; margin-bottom:1.5rem; border-bottom:1px solid #e2e2e2;">
          <a href="admin.php?tab=demandes" style="padding-bottom:0.5rem; <?= $tab === 'demandes' ? 'border-bottom:2px solid var(--gold);' : '' ?>">Demandes d'adhésion</a>
          <a href="admin.php?tab=evenements" style="padding-bottom:0.5rem; <?= $tab === 'evenements' ? 'border-bottom:2px solid var(--gold);' : '' ?>">Événements</a>
          <a href="admin.php?tab=messages" style="padding-bottom:0.5rem; <?= $tab === 'messages' ? 'border-bottom:2px solid var(--gold);' : '' ?>">Messages</a>
          <a href="admin.php?tab=equipe" style="padding-bottom:0.5rem; <?= $tab === 'equipe' ? 'border-bottom:2px solid var(--gold);' : '' ?>">Équipe</a>
        </nav>

        <?php if ($tab === 'demandes'): ?>
          <p style="font-size:0.85rem; color:#666;">L'acceptation/refus des demandes se fait sur event-swiss.com (panneau admin, onglet « Adhésions OrTra »). Cette liste est en lecture seule, mise à jour automatiquement après décision.</p>
          <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead><tr style="text-align:left; border-bottom:1px solid #e2e2e2;">
              <th style="padding:0.5rem;">Nom</th><th>E-mail</th><th>Type</th><th>Statut</th><th>Paiement</th><th>Date</th>
            </tr></thead>
            <tbody>
              <?php foreach ($demandes as $d): ?>
                <tr style="border-bottom:1px solid #f0f0f0;">
                  <td style="padding:0.5rem;"><?= e((string) $d['prenom'] . ' ' . (string) $d['nom']) ?></td>
                  <td><?= e((string) $d['email']) ?></td>
                  <td><?= e((string) $d['account_type']) ?> / <?= e((string) $d['profile_type']) ?></td>
                  <td><?= e((string) $d['statut_admission']) ?></td>
                  <td><?= e((string) $d['paiement_statut']) ?></td>
                  <td><?= e(date('d.m.Y', strtotime((string) $d['date_inscription']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

        <?php elseif ($tab === 'evenements'): ?>
          <h2 style="font-size:1rem;">Nouvel événement</h2>
          <form method="post" novalidate style="margin-bottom:2rem;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="creer_evenement">
            <div class="field"><label for="titre">Titre</label><input type="text" id="titre" name="titre" required></div>
            <div class="field"><label for="description">Description</label><textarea id="description" name="description"></textarea></div>
            <div class="form-row-2 form-grid" style="display:grid;">
              <div class="field"><label for="date_debut">Date/heure de début</label><input type="datetime-local" id="date_debut" name="date_debut" required></div>
              <div class="field"><label for="date_fin">Date/heure de fin</label><input type="datetime-local" id="date_fin" name="date_fin"></div>
            </div>
            <div class="field"><label for="lieu">Lieu</label><input type="text" id="lieu" name="lieu"></div>
            <button type="submit" class="btn btn-primary">Créer</button>
          </form>

          <h2 style="font-size:1rem;">Événements existants</h2>
          <?php foreach ($evenements as $ev): ?>
            <div style="border:1px solid #e2e2e2; border-radius:8px; padding:1rem; margin-bottom:0.75rem;">
              <strong><?= e((string) $ev['titre']) ?></strong> — <?= e(date('d.m.Y H:i', strtotime((string) $ev['date_debut']))) ?>
              <?= ((int) $ev['publie']) === 1 ? '<span style="color:green;">· publié</span>' : '<span style="color:#999;">· masqué</span>' ?>
              <div style="margin-top:0.5rem; display:flex; gap:1rem;">
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="basculer_publication"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button type="submit" class="btn btn-secondary" style="font-size:0.8rem;"><?= ((int) $ev['publie']) === 1 ? 'Masquer' : 'Publier' ?></button></form>
                <form method="post" onsubmit="return confirm('Supprimer cet événement ?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="supprimer_evenement"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button type="submit" class="btn btn-secondary" style="font-size:0.8rem; color:#c00;">Supprimer</button></form>
              </div>
            </div>
          <?php endforeach; ?>

        <?php elseif ($tab === 'messages'): ?>
          <h2 style="font-size:1rem;">Envoyer un message</h2>
          <form method="post" novalidate style="margin-bottom:2rem;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="envoyer_message">
            <div class="field">
              <label for="destinataire">Destinataire</label>
              <select id="destinataire" name="destinataire" required>
                <option value="tous">Tous les membres</option>
                <?php foreach ($membresPourMessage as $m): ?>
                  <option value="<?= (int) $m['id'] ?>"><?= e((string) $m['prenom'] . ' ' . (string) $m['nom'] . ' (' . (string) $m['email'] . ')') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label for="titre">Titre</label><input type="text" id="titre" name="titre" required></div>
            <div class="field"><label for="corps">Message</label><textarea id="corps" name="corps" required></textarea></div>
            <button type="submit" class="btn btn-primary">Envoyer</button>
          </form>

          <h2 style="font-size:1rem;">Messages envoyés</h2>
          <?php foreach ($messages as $msg): ?>
            <div style="border-bottom:1px solid #f0f0f0; padding:0.75rem 0;">
              <strong><?= e((string) $msg['titre']) ?></strong> — <?= $msg['membre_id'] ? e((string) $msg['destinataire_nom']) : 'Tous les membres' ?>
              <span style="font-size:0.8rem; color:#999;"> · <?= e(date('d.m.Y', strtotime((string) $msg['created_at']))) ?></span>
            </div>
          <?php endforeach; ?>

        <?php else: ?>
          <h2 style="font-size:1rem;">Ajouter un admin</h2>
          <form method="post" novalidate style="margin-bottom:2rem; max-width:420px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="creer_admin">
            <div class="field"><label for="admin_email">E-mail</label><input type="email" id="admin_email" name="email" required autocomplete="off"></div>
            <div class="field"><label for="admin_nom">Nom</label><input type="text" id="admin_nom" name="nom" required></div>
            <div class="field"><label for="admin_password">Mot de passe</label><input type="password" id="admin_password" name="password" required minlength="8" autocomplete="new-password"></div>
            <div class="field"><label for="admin_password_confirm">Confirmer le mot de passe</label><input type="password" id="admin_password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"></div>
            <button type="submit" class="btn btn-primary">Créer le compte</button>
          </form>

          <h2 style="font-size:1rem;">Membres de l'équipe</h2>
          <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead><tr style="text-align:left; border-bottom:1px solid #e2e2e2;">
              <th style="padding:0.5rem;">Nom</th><th>E-mail</th><th>Depuis</th>
            </tr></thead>
            <tbody>
              <?php foreach ($admins as $a): ?>
                <tr style="border-bottom:1px solid #f0f0f0;">
                  <td style="padding:0.5rem;"><?= e((string) $a['nom']) ?><?= ((int) $a['id']) === (int) $admin['id'] ? ' (vous)' : '' ?></td>
                  <td><?= e((string) $a['email']) ?></td>
                  <td><?= e(date('d.m.Y', strtotime((string) $a['created_at']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      </div>
    </div>
  </section>
</main>
</body>
</html>
