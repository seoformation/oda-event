<?php
/**
 * admin.php — Espace équipe OrTra : gestion des événements affichés aux
 * membres, envoi de messages, et gestion des demandes d'adhésion.
 * L'acceptation/refus peut se faire ici (appel à
 * event-swiss.com/api/oda/decide-membership) ou depuis le panneau admin
 * event-swiss.com — les deux points d'entrée partagent la même logique de
 * décision là-bas ; statut-webhook.php reflète ensuite le résultat ici.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$admin = require_admin_login();
$pdo = get_pdo();
$tab = in_array($_GET['tab'] ?? '', ['evenements', 'messages', 'demandes', 'equipe'], true) ? $_GET['tab'] : 'demandes';
$notice = '';
$noticeType = 'success';

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
        } else {
            $notice = "Titre et date de début sont obligatoires.";
            $noticeType = 'error';
        }
        $tab = 'evenements';
    } elseif ($action === 'decider_adhesion') {
        $paymentToken = trim((string) ($_POST['payment_token'] ?? ''));
        $decision = (string) ($_POST['decision'] ?? '');
        if ($paymentToken === '' || !in_array($decision, ['approve', 'reject'], true)) {
            $notice = "Requête invalide.";
            $noticeType = 'error';
        } elseif (!defined('EVENT_SWISS_API_URL') || !defined('EVENT_SWISS_API_SECRET') || EVENT_SWISS_API_SECRET === '') {
            $notice = "Configuration manquante pour contacter event-swiss.com.";
            $noticeType = 'error';
        } else {
            $ch = curl_init(rtrim(EVENT_SWISS_API_URL, '/') . '/api/oda/decide-membership');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['oda_reference' => $paymentToken, 'decision' => $decision]),
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
                $notice = "Erreur lors de la communication avec event-swiss.com. Réessayez, ou traitez la demande depuis le panneau admin event-swiss.com.";
                $noticeType = 'error';
            } else {
                $notice = $decision === 'approve' ? "Demande acceptée." : "Demande refusée.";
            }
        }
        $tab = 'demandes';
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
    } elseif ($action === 'promouvoir_admin') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $nom = trim((string) ($_POST['nom'] ?? ''));
        if ($email === '' || $nom === '') {
            $notice = "E-mail et nom sont obligatoires.";
            $noticeType = 'error';
        } else {
            $existing = $pdo->prepare('SELECT id FROM admins WHERE email = :email');
            $existing->execute([':email' => $email]);
            if ($existing->fetch()) {
                $notice = "Cette personne a déjà un compte admin.";
                $noticeType = 'error';
            } else {
                $genereMotDePasse = generer_mot_de_passe_admin();
                $stmt = $pdo->prepare('INSERT INTO admins (email, password_hash, nom) VALUES (:email, :password_hash, :nom)');
                $stmt->execute([
                    ':email' => $email,
                    ':password_hash' => password_hash($genereMotDePasse, PASSWORD_DEFAULT),
                    ':nom' => $nom,
                ]);
                $notice = "Compte admin créé pour " . $nom . ". Mot de passe généré : " . $genereMotDePasse . " — communiquez-le en toute sécurité à cette personne, il ne sera plus jamais affiché.";
            }
        }
        $tab = 'demandes';
    } elseif ($action === 'creer_admin') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if ($email === '' || $nom === '') {
            $notice = "E-mail et nom sont obligatoires.";
            $noticeType = 'error';
        } elseif (mb_strlen($password) < 8) {
            $notice = "Le mot de passe doit contenir au moins 8 caractères.";
            $noticeType = 'error';
        } elseif ($password !== $passwordConfirm) {
            $notice = "Les mots de passe ne correspondent pas.";
            $noticeType = 'error';
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
                $noticeType = 'error';
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
        } else {
            $notice = "Titre et message sont obligatoires.";
            $noticeType = 'error';
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
$demandes = $pdo->query('SELECT id, prenom, nom, email, account_type, profile_type, statut_admission, paiement_statut, payment_token, event_swiss_opt_in, date_inscription FROM membres_inscription ORDER BY date_inscription DESC')->fetchAll();
$membresPourMessage = $pdo->query('SELECT id, prenom, nom, email FROM membres_inscription ORDER BY nom, prenom')->fetchAll();
$admins = $pdo->query('SELECT id, email, nom, created_at FROM admins ORDER BY created_at DESC')->fetchAll();

$statutBadges = [
    'en_attente' => ['badge-pending', 'En attente'],
    'accepte' => ['badge-accepted', 'Accepté'],
    'refuse' => ['badge-rejected', 'Refusé'],
];
$nbEnAttente = count(array_filter($demandes, fn ($d) => $d['statut_admission'] === 'en_attente'));
$nbAcceptes = count(array_filter($demandes, fn ($d) => $d['statut_admission'] === 'accepte'));
$nbEvenementsPublies = count(array_filter($evenements, fn ($ev) => (int) $ev['publie'] === 1));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Espace admin — OrTra Suisse de l'Événementiel</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=14">
</head>
<body>
<main>
  <section class="section section--light">
    <div class="container">
      <div class="form-card" style="max-width:1140px;">
        <div class="admin-topbar">
          <h1>Espace admin OrTra</h1>
          <a href="admin-deconnexion.php" class="btn btn-secondary" style="font-size:0.85rem;">Se déconnecter</a>
        </div>

        <?php if ($notice !== ''): ?>
          <div class="form-alert is-visible form-alert--<?= $noticeType === 'error' ? 'error' : 'success' ?>" role="status"><?= e($notice) ?></div>
        <?php endif; ?>

        <div class="admin-stats">
          <div class="admin-stat<?= $nbEnAttente > 0 ? ' admin-stat--highlight' : '' ?>">
            <strong><?= $nbEnAttente ?></strong>
            <span>Demande<?= $nbEnAttente > 1 ? 's' : '' ?> en attente</span>
          </div>
          <div class="admin-stat">
            <strong><?= $nbAcceptes ?></strong>
            <span>Membre<?= $nbAcceptes > 1 ? 's' : '' ?> accepté<?= $nbAcceptes > 1 ? 's' : '' ?></span>
          </div>
          <div class="admin-stat">
            <strong><?= $nbEvenementsPublies ?></strong>
            <span>Événement<?= $nbEvenementsPublies > 1 ? 's' : '' ?> publié<?= $nbEvenementsPublies > 1 ? 's' : '' ?></span>
          </div>
          <div class="admin-stat">
            <strong><?= count($admins) ?></strong>
            <span>Admin<?= count($admins) > 1 ? 's' : '' ?> OrTra</span>
          </div>
        </div>

        <nav class="admin-tabs">
          <a href="admin.php?tab=demandes" class="<?= $tab === 'demandes' ? 'is-active' : '' ?>">Demandes d'adhésion<?php if ($nbEnAttente > 0): ?> <span class="count"><?= $nbEnAttente ?></span><?php endif; ?></a>
          <a href="admin.php?tab=evenements" class="<?= $tab === 'evenements' ? 'is-active' : '' ?>">Événements</a>
          <a href="admin.php?tab=messages" class="<?= $tab === 'messages' ? 'is-active' : '' ?>">Messages</a>
          <a href="admin.php?tab=equipe" class="<?= $tab === 'equipe' ? 'is-active' : '' ?>">Équipe</a>
        </nav>

        <?php if ($tab === 'demandes'): ?>
          <p class="admin-hint">Vous pouvez accepter ou refuser une demande directement ici, ou depuis le panneau admin event-swiss.com (onglet « Adhésions OrTra ») — les deux sont équivalents. Vous pouvez aussi donner un accès admin OrTra à une personne depuis cette liste.</p>
          <?php if (empty($demandes)): ?>
            <div class="admin-empty">Aucune demande d'adhésion pour le moment.</div>
          <?php else: ?>
          <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr>
              <th>Nom</th><th>E-mail</th><th>Type</th><th>Statut</th><th>Paiement</th><th>event-swiss.com</th><th>Date</th><th>Décision</th><th>Accès admin</th>
            </tr></thead>
            <tbody>
              <?php $adminEmails = array_column($admins, 'email'); ?>
              <?php foreach ($demandes as $d): ?>
                <?php $statutInfo = $statutBadges[$d['statut_admission']] ?? ['badge-neutral', (string) $d['statut_admission']]; ?>
                <tr>
                  <td><?= e((string) $d['prenom'] . ' ' . (string) $d['nom']) ?></td>
                  <td><?= e((string) $d['email']) ?></td>
                  <td><?= e((string) $d['account_type']) ?> / <?= e((string) $d['profile_type']) ?></td>
                  <td><span class="badge <?= $statutInfo[0] ?>"><?= e($statutInfo[1]) ?></span></td>
                  <td><span class="badge <?= $d['paiement_statut'] === 'paye' ? 'badge-accepted' : 'badge-neutral' ?>"><?= e((string) $d['paiement_statut']) ?></span></td>
                  <td style="text-align:center;">
                    <?php if ((int) $d['event_swiss_opt_in'] === 1): ?>
                      <span class="badge badge-star" title="A demandé un compte event-swiss.com">★ Oui</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Non</span>
                    <?php endif; ?>
                  </td>
                  <td><?= e(date('d.m.Y', strtotime((string) $d['date_inscription']))) ?></td>
                  <td>
                    <?php if ((string) $d['statut_admission'] === 'en_attente' && (string) $d['payment_token'] !== ''): ?>
                      <div class="admin-actions">
                        <form method="post" onsubmit="return confirm('Accepter la demande de <?= e(addslashes((string) $d['prenom'] . ' ' . (string) $d['nom'])) ?> ?');">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="decider_adhesion">
                          <input type="hidden" name="payment_token" value="<?= e((string) $d['payment_token']) ?>">
                          <input type="hidden" name="decision" value="approve">
                          <button type="submit" class="btn btn-primary btn-xs">Accepter</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Refuser la demande de <?= e(addslashes((string) $d['prenom'] . ' ' . (string) $d['nom'])) ?> ?');">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="decider_adhesion">
                          <input type="hidden" name="payment_token" value="<?= e((string) $d['payment_token']) ?>">
                          <input type="hidden" name="decision" value="reject">
                          <button type="submit" class="btn-danger-outline btn-xs" style="border-radius:6px;">Refuser</button>
                        </form>
                      </div>
                    <?php else: ?>
                      <span class="badge badge-neutral">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (in_array((string) $d['email'], $adminEmails, true)): ?>
                      <span class="badge badge-neutral">Déjà admin</span>
                    <?php else: ?>
                      <form method="post" onsubmit="return confirm('Créer un compte admin pour <?= e(addslashes((string) $d['prenom'] . ' ' . (string) $d['nom'])) ?> ?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="promouvoir_admin">
                        <input type="hidden" name="email" value="<?= e((string) $d['email']) ?>">
                        <input type="hidden" name="nom" value="<?= e((string) $d['prenom'] . ' ' . (string) $d['nom']) ?>">
                        <button type="submit" class="btn btn-secondary btn-xs">Passer admin</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>

        <?php elseif ($tab === 'evenements'): ?>
          <h2 class="admin-section-title">Nouvel événement</h2>
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

          <h2 class="admin-section-title">Événements existants</h2>
          <?php if (empty($evenements)): ?>
            <div class="admin-empty">Aucun événement pour le moment.</div>
          <?php else: ?>
          <div class="admin-card-list">
            <?php foreach ($evenements as $ev): ?>
              <div class="admin-card">
                <div class="admin-card-head">
                  <div>
                    <strong><?= e((string) $ev['titre']) ?></strong>
                    <div class="admin-card-meta"><?= e(date('d.m.Y H:i', strtotime((string) $ev['date_debut']))) ?><?= $ev['lieu'] ? ' · ' . e((string) $ev['lieu']) : '' ?></div>
                  </div>
                  <span class="badge <?= ((int) $ev['publie']) === 1 ? 'badge-accepted' : 'badge-neutral' ?>"><?= ((int) $ev['publie']) === 1 ? 'Publié' : 'Masqué' ?></span>
                </div>
                <div class="admin-card-actions">
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="basculer_publication"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button type="submit" class="btn btn-secondary btn-xs"><?= ((int) $ev['publie']) === 1 ? 'Masquer' : 'Publier' ?></button></form>
                  <form method="post" onsubmit="return confirm('Supprimer cet événement ?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="supprimer_evenement"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button type="submit" class="btn-danger-outline btn-xs" style="border-radius:6px;">Supprimer</button></form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        <?php elseif ($tab === 'messages'): ?>
          <h2 class="admin-section-title">Envoyer un message</h2>
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

          <h2 class="admin-section-title">Messages envoyés</h2>
          <?php if (empty($messages)): ?>
            <div class="admin-empty">Aucun message envoyé pour le moment.</div>
          <?php else: ?>
          <div class="admin-card-list">
            <?php foreach ($messages as $msg): ?>
              <div class="admin-card">
                <div class="admin-card-head">
                  <div>
                    <strong><?= e((string) $msg['titre']) ?></strong>
                    <div class="admin-card-meta"><?= $msg['membre_id'] ? e((string) $msg['destinataire_nom']) : 'Tous les membres' ?> · <?= e(date('d.m.Y', strtotime((string) $msg['created_at']))) ?></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        <?php else: ?>
          <h2 class="admin-section-title">Ajouter un admin</h2>
          <form method="post" novalidate style="margin-bottom:2rem; max-width:420px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="creer_admin">
            <div class="field"><label for="admin_email">E-mail</label><input type="email" id="admin_email" name="email" required autocomplete="off"></div>
            <div class="field"><label for="admin_nom">Nom</label><input type="text" id="admin_nom" name="nom" required></div>
            <div class="field"><label for="admin_password">Mot de passe</label><input type="password" id="admin_password" name="password" required minlength="8" autocomplete="new-password"></div>
            <div class="field"><label for="admin_password_confirm">Confirmer le mot de passe</label><input type="password" id="admin_password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"></div>
            <button type="submit" class="btn btn-primary">Créer le compte</button>
          </form>

          <h2 class="admin-section-title">Membres de l'équipe</h2>
          <div class="admin-table-wrap">
          <table class="admin-table" style="min-width:0;">
            <thead><tr>
              <th>Nom</th><th>E-mail</th><th>Depuis</th>
            </tr></thead>
            <tbody>
              <?php foreach ($admins as $a): ?>
                <tr>
                  <td><?= e((string) $a['nom']) ?><?= ((int) $a['id']) === (int) $admin['id'] ? ' <span class="badge badge-star">Vous</span>' : '' ?></td>
                  <td><?= e((string) $a['email']) ?></td>
                  <td><?= e(date('d.m.Y', strtotime((string) $a['created_at']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </section>
</main>
</body>
</html>
