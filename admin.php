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
require_once __DIR__ . '/admin-functions.php';

$admin = require_admin_login();
$pdo = get_pdo();
$tab = in_array($_GET['tab'] ?? '', ['evenements', 'messages', 'demandes', 'equipe', 'articles', 'archives'], true) ? $_GET['tab'] : 'demandes';
$sub = in_array($_GET['sub'] ?? '', ['desinscriptions', 'refus'], true) ? $_GET['sub'] : 'desinscriptions';
$notice = '';
$noticeType = 'success';

// Les colonnes de désinscription/archives n'existent qu'une fois
// sql/migration-2026-10-28-archives-desinscriptions.sql appliquée : sans
// elle, l'admin reste utilisable mais les actions qui en dépendent sont
// bloquées (plutôt qu'une erreur SQL en pleine page).
$migrationArchivesOk = migration_archives_appliquee($pdo);

// Message de la dernière action, transmis après redirection (voir plus bas).
if (!empty($_SESSION['admin_flash'])) {
    [$notice, $noticeType] = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

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
    } elseif ($action === 'action_dossier') {
        // Menu « Choisir une action » + OK des tableaux Demandes / Archives
        // (accepter, refuser, marquer payé, désinscrire, accès admin...) :
        // voir traiter_action_dossier() dans admin-functions.php.
        [$notice, $noticeType, $tab, $sub] = traiter_action_dossier($pdo, $admin, $_POST, $migrationArchivesOk);
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
    } elseif ($action === 'supprimer_article') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM articles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $notice = "Article supprimé.";
        $tab = 'articles';
    } elseif ($action === 'basculer_publication_article') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT statut, publie_le FROM articles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $nouveauStatut = $row['statut'] === 'publie' ? 'brouillon' : 'publie';
            $publieLe = $row['publie_le'] ?: ($nouveauStatut === 'publie' ? date('Y-m-d H:i:s') : null);
            $stmt2 = $pdo->prepare('UPDATE articles SET statut = :statut, publie_le = :publie_le WHERE id = :id');
            $stmt2->execute([':statut' => $nouveauStatut, ':publie_le' => $publieLe, ':id' => $id]);
        }
        $tab = 'articles';
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

    // Post/Redirect/Get : un rafraîchissement de la page après une action ne
    // renvoie pas le formulaire (pas de double traitement, pas de double
    // e-mail). Le message est affiché une fois après la redirection.
    $_SESSION['admin_flash'] = [$notice, $noticeType];
    header('Location: admin.php?tab=' . urlencode($tab) . ($tab === 'archives' && !empty($sub) ? '&sub=' . urlencode($sub) : ''));
    exit;
}

$evenements = $pdo->query('SELECT * FROM evenements ORDER BY date_debut DESC')->fetchAll();
$messages = $pdo->query(
    'SELECT m.*, CONCAT(mi.prenom, " ", mi.nom) AS destinataire_nom
     FROM messages_membres m LEFT JOIN membres_inscription mi ON mi.id = m.membre_id
     ORDER BY m.created_at DESC LIMIT 50'
)->fetchAll();
$demandes = $pdo->query('SELECT * FROM membres_inscription ORDER BY date_inscription DESC')->fetchAll();
// Répartition sans doublon : un dossier refusé ne peut pas être désinscrit
// (seul un membre accepté peut l'être), donc chaque dossier n'apparaît que
// dans une seule liste.
$refus = array_values(array_filter($demandes, fn ($d) => $d['statut_admission'] === 'refuse'));
$desinscrits = array_values(array_filter($demandes, fn ($d) => $d['statut_admission'] !== 'refuse' && dossier_est_desinscrit($d)));
$actifs = array_values(array_filter($demandes, fn ($d) => $d['statut_admission'] !== 'refuse' && !dossier_est_desinscrit($d)));
usort($desinscrits, fn ($a, $b) => strcmp((string) $b['desinscription_enregistree_at'], (string) $a['desinscription_enregistree_at']));
usort($refus, fn ($a, $b) => strcmp((string) ($b['refuse_at'] ?? $b['date_inscription']), (string) ($a['refuse_at'] ?? $a['date_inscription'])));
$parametresDesinscription = desinscription_parametres();

// Historique des dossiers archivés (admin_journal).
$journal = [];
$archivesIds = array_map(fn ($d) => (int) $d['id'], array_merge($refus, $desinscrits));
if ($migrationArchivesOk && $archivesIds !== []) {
    $place = implode(',', array_fill(0, count($archivesIds), '?'));
    $jStmt = $pdo->prepare("SELECT j.membre_id, j.action, j.details, j.created_at, a.nom AS admin_nom FROM admin_journal j LEFT JOIN admins a ON a.id = j.admin_id WHERE j.membre_id IN ($place) ORDER BY j.created_at, j.id");
    $jStmt->execute($archivesIds);
    foreach ($jStmt->fetchAll() as $row) {
        $journal[(int) $row['membre_id']][] = $row;
    }
}
$membresPourMessage = $pdo->query('SELECT id, prenom, nom, email FROM membres_inscription ORDER BY nom, prenom')->fetchAll();
$admins = $pdo->query('SELECT id, email, nom, created_at FROM admins ORDER BY created_at DESC')->fetchAll();
$articles = $pdo->query('SELECT id, slug, titre_fr, statut, publie_le, created_at FROM articles ORDER BY created_at DESC')->fetchAll();

$statutBadges = [
    'en_attente' => ['badge-pending', 'En attente'],
    'accepte' => ['badge-accepted', 'Accepté'],
    'refuse' => ['badge-rejected', 'Refusé'],
];
$adminEmails = array_column($admins, 'email');
$nbEnAttente = count(array_filter($actifs, fn ($d) => $d['statut_admission'] === 'en_attente'));
$nbAcceptes = count(array_filter($actifs, fn ($d) => $d['statut_admission'] === 'accepte'));
$nbEvenementsPublies = count(array_filter($evenements, fn ($ev) => (int) $ev['publie'] === 1));
$nbArticlesPublies = count(array_filter($articles, fn ($a) => $a['statut'] === 'publie'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Espace admin — OrTra Suisse de l'Événementiel</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=19">
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
            <span>Membre<?= $nbAcceptes > 1 ? 's' : '' ?> actif<?= $nbAcceptes > 1 ? 's' : '' ?></span>
          </div>
          <div class="admin-stat">
            <strong><?= $nbEvenementsPublies ?></strong>
            <span>Événement<?= $nbEvenementsPublies > 1 ? 's' : '' ?> publié<?= $nbEvenementsPublies > 1 ? 's' : '' ?></span>
          </div>
          <div class="admin-stat">
            <strong><?= count($admins) ?></strong>
            <span>Admin<?= count($admins) > 1 ? 's' : '' ?> OrTra</span>
          </div>
          <div class="admin-stat">
            <strong><?= $nbArticlesPublies ?></strong>
            <span>Article<?= $nbArticlesPublies > 1 ? 's' : '' ?> publié<?= $nbArticlesPublies > 1 ? 's' : '' ?></span>
          </div>
        </div>

        <nav class="admin-tabs">
          <a href="admin.php?tab=demandes" class="<?= $tab === 'demandes' ? 'is-active' : '' ?>">Demandes d'adhésion<?php if ($nbEnAttente > 0): ?> <span class="count"><?= $nbEnAttente ?></span><?php endif; ?></a>
          <a href="admin.php?tab=evenements" class="<?= $tab === 'evenements' ? 'is-active' : '' ?>">Événements</a>
          <a href="admin.php?tab=articles" class="<?= $tab === 'articles' ? 'is-active' : '' ?>">Articles</a>
          <a href="admin.php?tab=messages" class="<?= $tab === 'messages' ? 'is-active' : '' ?>">Messages</a>
          <a href="admin.php?tab=equipe" class="<?= $tab === 'equipe' ? 'is-active' : '' ?>">Équipe</a>
          <a href="admin.php?tab=archives" class="<?= $tab === 'archives' ? 'is-active' : '' ?>">Archives <span class="count count--muted"><?= count($refus) + count($desinscrits) ?></span></a>
        </nav>

        <?php if ($tab === 'demandes'): ?>
          <?php if (!$migrationArchivesOk): ?>
            <div class="form-alert is-visible form-alert--error" role="alert">La migration SQL des archives (sql/migration-2026-10-28-archives-desinscriptions.sql) n'est pas encore appliquée : la désinscription et les archives complètes sont indisponibles.</div>
          <?php endif; ?>
          <p class="admin-hint">Demandes en attente et membres actifs. Choisissez une action dans le menu de la ligne puis cliquez sur « OK ». Les dossiers refusés et les membres désinscrits sont dans <a href="admin.php?tab=archives">Archives</a>. Accepter ou refuser ici équivaut à le faire depuis le panneau admin event-swiss.com.</p>
          <?php if (empty($actifs)): ?>
            <div class="admin-empty">Aucune demande d'adhésion ni membre actif pour le moment.</div>
          <?php else: ?>
          <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr>
              <th>Nom</th><th>E-mail</th><th>Type</th><th>Statut</th><th>Paiement</th><th>event-swiss.com</th><th>Date</th><th>Actions</th>
            </tr></thead>
            <tbody>
              <?php foreach ($actifs as $d): ?>
                <?php $statutInfo = $statutBadges[$d['statut_admission']] ?? ['badge-neutral', (string) $d['statut_admission']]; ?>
                <tr>
                  <td><?= e((string) $d['prenom'] . ' ' . (string) $d['nom']) ?></td>
                  <td><?= e((string) $d['email']) ?></td>
                  <td><?= e((string) $d['account_type']) ?> / <?= e((string) $d['profile_type']) ?></td>
                  <td><span class="badge <?= $statutInfo[0] ?>"><?= e($statutInfo[1]) ?></span></td>
                  <td><span class="badge <?= $d['paiement_statut'] === 'paye' ? 'badge-accepted' : 'badge-neutral' ?>"><?= $d['paiement_statut'] === 'paye' ? 'Payé' : 'Non payé' ?></span></td>
                  <td style="text-align:center;">
                    <?php if ((int) $d['event_swiss_opt_in'] === 1): ?>
                      <span class="badge badge-star" title="A demandé un compte event-swiss.com">★ Oui</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Non</span>
                    <?php endif; ?>
                  </td>
                  <td><?= e(format_date_ch((string) $d['date_inscription'])) ?></td>
                  <td><?php render_menu_actions($d, $adminEmails, 'demandes', '', $migrationArchivesOk); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>

        <?php elseif ($tab === 'archives'): ?>
          <?php if (!$migrationArchivesOk): ?>
            <div class="form-alert is-visible form-alert--error" role="alert">La migration SQL des archives (sql/migration-2026-10-28-archives-desinscriptions.sql) n'est pas encore appliquée : seuls les refus sont listés, sans date de refus.</div>
          <?php endif; ?>
          <nav class="admin-subtabs" aria-label="Archives">
            <a href="admin.php?tab=archives&amp;sub=desinscriptions" class="<?= $sub === 'desinscriptions' ? 'is-active' : '' ?>">Désinscriptions <span class="count"><?= count($desinscrits) ?></span></a>
            <a href="admin.php?tab=archives&amp;sub=refus" class="<?= $sub === 'refus' ? 'is-active' : '' ?>">Refus d'acceptation <span class="count"><?= count($refus) ?></span></a>
          </nav>

          <?php if ($sub === 'desinscriptions'): ?>
            <p class="admin-hint">Membres dont la désinscription a été enregistrée. Ils restent membres jusqu'à la date d'effet ; l'année supplémentaire éventuellement due n'est jamais marquée payée automatiquement. Préavis appliqué : <?= (int) $parametresDesinscription['preavis_mois'] ?> mois avant la fin d'exercice (<?= e(substr($parametresDesinscription['fin_exercice'], 3, 2) . '.' . substr($parametresDesinscription['fin_exercice'], 0, 2)) ?>).</p>
            <?php if (empty($desinscrits)): ?>
              <div class="admin-empty">Aucune désinscription enregistrée.</div>
            <?php else: ?>
            <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr>
                <th>Nom</th><th>E-mail</th><th>Demande reçue</th><th>Effective le</th><th>Statut</th><th>Année supplémentaire</th><th>E-mail membre</th><th>Historique</th><th>Actions</th>
              </tr></thead>
              <tbody>
                <?php foreach ($desinscrits as $d): ?>
                  <?php $effectif = (string) $d['desinscription_effective_le'] !== '' && (string) $d['desinscription_effective_le'] < date('Y-m-d'); ?>
                  <tr>
                    <td><?= e((string) $d['prenom'] . ' ' . (string) $d['nom']) ?></td>
                    <td><?= e((string) $d['email']) ?></td>
                    <td><?= e(format_date_ch($d['desinscription_demande_le'])) ?><?php if ((int) $d['desinscription_hors_delai'] === 1): ?><br><span class="badge badge-pending" title="Moins de <?= (int) $parametresDesinscription['preavis_mois'] ?> mois avant la fin d'exercice">Hors délai</span><?php endif; ?></td>
                    <td><?= e(format_date_ch($d['desinscription_effective_le'])) ?></td>
                    <td><span class="badge <?= $effectif ? 'badge-neutral' : 'badge-pending' ?>"><?= $effectif ? 'Désinscrit' : 'En préavis' ?></span></td>
                    <td>
                      <?php if ($d['annee_supp_annee'] === null): ?>
                        <span class="badge badge-neutral">Aucune</span>
                      <?php elseif ($d['annee_supp_statut'] === 'paye'): ?>
                        <span class="badge badge-accepted"><?= (int) $d['annee_supp_annee'] ?> · payée<?= $d['annee_supp_montant_chf'] !== null ? ' · CHF ' . (int) $d['annee_supp_montant_chf'] : '' ?></span>
                        <div class="admin-card-meta">le <?= e(format_date_ch($d['annee_supp_paye_at'])) ?></div>
                      <?php else: ?>
                        <span class="badge badge-rejected"><?= (int) $d['annee_supp_annee'] ?> · due</span>
                        <?php if ($d['annee_supp_montant_chf'] !== null): ?>
                          <div class="admin-card-meta">CHF <?= (int) $d['annee_supp_montant_chf'] ?></div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php $emailStatut = (string) ($d['desinscription_email_statut'] ?? ''); ?>
                      <?php if ($emailStatut === 'envoye'): ?>
                        <span class="badge badge-accepted">Envoyé</span><div class="admin-card-meta">le <?= e(format_date_ch($d['desinscription_email_at'])) ?></div>
                      <?php elseif ($emailStatut === 'echec'): ?>
                        <span class="badge badge-rejected">Échec</span><div class="admin-card-meta">à renvoyer</div>
                      <?php elseif ($emailStatut === 'en_cours'): ?>
                        <span class="badge badge-pending">En cours</span>
                      <?php else: ?>
                        <span class="badge badge-pending">À envoyer</span>
                      <?php endif; ?>
                    </td>
                    <td><?php render_historique($journal[(int) $d['id']] ?? [], $d); ?></td>
                    <td><?php render_menu_actions($d, $adminEmails, 'archives', 'desinscriptions', $migrationArchivesOk); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <?php endif; ?>

          <?php else: ?>
            <p class="admin-hint">Demandes d'adhésion refusées. Consultation seule : la décision est prise sur event-swiss.com et n'est pas réversible depuis cet écran.</p>
            <?php if (empty($refus)): ?>
              <div class="admin-empty">Aucune demande refusée.</div>
            <?php else: ?>
            <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr>
                <th>Nom</th><th>Coordonnées</th><th>Type</th><th>Demande du</th><th>Refusée le</th><th>Statut</th><th>Historique</th>
              </tr></thead>
              <tbody>
                <?php foreach ($refus as $d): ?>
                  <tr>
                    <td><?= e((string) $d['prenom'] . ' ' . (string) $d['nom']) ?><?php if ((string) ($d['entreprise'] ?? '') !== ''): ?><div class="admin-card-meta"><?= e((string) $d['entreprise']) ?></div><?php endif; ?></td>
                    <td><?= e((string) $d['email']) ?><?php if ((string) ($d['telephone'] ?? '') !== ''): ?><div class="admin-card-meta"><?= e((string) $d['telephone']) ?></div><?php endif; ?><?php if ((string) ($d['canton'] ?? '') !== ''): ?><div class="admin-card-meta"><?= e((string) $d['canton']) ?></div><?php endif; ?></td>
                    <td><?= e((string) $d['account_type']) ?> / <?= e((string) $d['profile_type']) ?></td>
                    <td><?= e(format_date_ch((string) $d['date_inscription'])) ?></td>
                    <td><?= e(format_date_ch($d['refuse_at'] ?? null)) ?></td>
                    <td><span class="badge badge-rejected">Refusé</span></td>
                    <td><?php render_historique($journal[(int) $d['id']] ?? [], $d); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <?php endif; ?>
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

        <?php elseif ($tab === 'articles'): ?>
          <div class="admin-topbar" style="margin-bottom:1.25rem;">
            <p class="admin-hint" style="margin:0;">Articles du blog OrTra, trilingues (FR/DE/IT obligatoire). Un brouillon n'est jamais visible publiquement.</p>
            <a href="admin-article.php" class="btn btn-primary btn-xs">+ Nouvel article</a>
          </div>
          <?php if (empty($articles)): ?>
            <div class="admin-empty">Aucun article pour le moment.</div>
          <?php else: ?>
          <div class="admin-table-wrap">
          <table class="admin-table" style="min-width:0;">
            <thead><tr>
              <th>Titre (FR)</th><th>Statut</th><th>Date</th><th>Actions</th>
            </tr></thead>
            <tbody>
              <?php foreach ($articles as $art): ?>
                <tr>
                  <td><?= e((string) $art['titre_fr']) ?></td>
                  <td><span class="badge <?= $art['statut'] === 'publie' ? 'badge-accepted' : 'badge-neutral' ?>"><?= $art['statut'] === 'publie' ? 'Publié' : 'Brouillon' ?></span></td>
                  <td><?= e(date('d.m.Y', strtotime((string) ($art['publie_le'] ?: $art['created_at'])))) ?></td>
                  <td>
                    <div class="admin-actions">
                      <a href="admin-article.php?id=<?= (int) $art['id'] ?>" class="btn btn-secondary btn-xs">Modifier</a>
                      <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="basculer_publication_article"><input type="hidden" name="id" value="<?= (int) $art['id'] ?>"><button type="submit" class="btn btn-secondary btn-xs"><?= $art['statut'] === 'publie' ? 'Dépublier' : 'Publier' ?></button></form>
                      <form method="post" onsubmit="return confirm('Supprimer cet article ?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="supprimer_article"><input type="hidden" name="id" value="<?= (int) $art['id'] ?>"><button type="submit" class="btn-danger-outline btn-xs" style="border-radius:6px;">Supprimer</button></form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
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

<dialog id="action-dialog" class="admin-dialog" aria-labelledby="action-dialog-title"
  data-fin-exercice="<?= e($parametresDesinscription['fin_exercice']) ?>"
  data-preavis-mois="<?= (int) $parametresDesinscription['preavis_mois'] ?>"
  data-annee-supp="<?= e($parametresDesinscription['annee_supp']) ?>"
  data-aujourdhui="<?= e(date('Y-m-d')) ?>">
  <form method="dialog">
    <h2 id="action-dialog-title" class="admin-dialog-title"></h2>
    <p class="admin-dialog-message"></p>
    <div class="admin-dialog-desinscription" hidden>
      <div class="field">
        <label for="dialog-date-demande">Date de réception de la demande de désinscription</label>
        <input type="date" id="dialog-date-demande">
      </div>
      <div class="admin-warning" role="alert" hidden>
        Attention : cette demande de désinscription intervient moins de trois mois avant la date de référence. Selon les conditions applicables, une année supplémentaire peut rester due. Veuillez vérifier les conséquences financières avant de confirmer cette opération.
      </div>
      <ul class="admin-dialog-summary"></ul>
    </div>
    <div class="admin-dialog-buttons">
      <button type="submit" value="annuler" class="btn btn-secondary btn-xs">Annuler</button>
      <button type="submit" value="confirmer" class="btn btn-primary btn-xs admin-dialog-confirm">Confirmer</button>
    </div>
  </form>
</dialog>
<script src="assets/js/admin.js?v=1"></script>
</body>
</html>
