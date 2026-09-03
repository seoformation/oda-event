<?php
/**
 * mon-compte.php — Tableau de bord membre : statut de la demande, facture,
 * édition du profil (avec proposition de synchronisation vers
 * event-swiss.com si un compte y est lié), prochains événements, messages.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$membre = require_membre_login();
$lang = in_array($membre['lang'] ?? 'fr', ['fr', 'de', 'it'], true) ? $membre['lang'] : 'fr';
$pdo = get_pdo();
$saved = false;
$syncError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $prenom = trim((string) ($_POST['prenom'] ?? ''));
    $nom = trim((string) ($_POST['nom'] ?? ''));
    $telephone = trim((string) ($_POST['telephone'] ?? ''));
    $adresse = trim((string) ($_POST['adresse'] ?? ''));
    $canton = trim((string) ($_POST['canton'] ?? ''));
    $entreprise = trim((string) ($_POST['entreprise'] ?? ''));
    $legalName = trim((string) ($_POST['legal_name'] ?? ''));
    $companyAddress = trim((string) ($_POST['company_address'] ?? ''));
    $companyEmail = trim((string) ($_POST['company_email'] ?? ''));
    $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));
    $wantsSync = (string) ($_POST['sync_event_swiss'] ?? '') === '1';

    if ($prenom !== '' && $nom !== '' && in_array($canton, CANTONS_SUISSE, true)) {
        $stmt = $pdo->prepare(
            'UPDATE membres_inscription SET
                prenom = :prenom, nom = :nom, nom_complet = :nom_complet, telephone = :telephone, es_address = :adresse,
                canton = :canton,
                entreprise = :entreprise, es_legal_name = :legal_name, es_company_address = :company_address,
                es_company_email = :company_email, es_company_phone = :company_phone
             WHERE id = :id'
        );
        $stmt->execute([
            ':prenom' => $prenom,
            ':nom' => $nom,
            ':nom_complet' => trim($prenom . ' ' . $nom),
            ':telephone' => $telephone !== '' ? $telephone : null,
            ':adresse' => $adresse,
            ':canton' => $canton,
            ':entreprise' => $membre['account_type'] === 'company' ? $entreprise : null,
            ':legal_name' => $membre['account_type'] === 'company' ? $legalName : null,
            ':company_address' => $membre['account_type'] === 'company' ? $companyAddress : null,
            ':company_email' => $membre['account_type'] === 'company' && $companyEmail !== '' ? $companyEmail : null,
            ':company_phone' => $membre['account_type'] === 'company' && $companyPhone !== '' ? $companyPhone : null,
            ':id' => $membre['id'],
        ]);

        if ($wantsSync && (int) $membre['event_swiss_account_linked'] === 1
            && defined('EVENT_SWISS_API_URL') && defined('EVENT_SWISS_API_SECRET') && EVENT_SWISS_API_SECRET !== '') {
            $ch = curl_init(rtrim(EVENT_SWISS_API_URL, '/') . '/api/oda/sync-profile');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'email' => $membre['email'],
                    'first_name' => $prenom,
                    'last_name' => $nom,
                    'phone' => $telephone !== '' ? $telephone : null,
                    'address' => $adresse,
                    'canton' => $canton,
                    'company_display_name' => $membre['account_type'] === 'company' ? $entreprise : null,
                    'company_address' => $membre['account_type'] === 'company' ? $companyAddress : null,
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . EVENT_SWISS_API_SECRET],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($curlErr !== '' || $code < 200 || $code >= 300) {
                error_log('Erreur sync-profile event-swiss.com : ' . ($curlErr !== '' ? $curlErr : (string) $resp));
                $syncError = t($lang, 'dashboard_sync_error');
            }
        }

        // Recharge les données à jour pour l'affichage.
        $stmt = $pdo->prepare('SELECT * FROM membres_inscription WHERE id = :id');
        $stmt->execute([':id' => $membre['id']]);
        $membre = $stmt->fetch();
        $saved = true;
    }
}

// Prochains événements publiés
$evenements = $pdo->query('SELECT * FROM evenements WHERE publie = 1 AND date_debut >= NOW() ORDER BY date_debut ASC')->fetchAll();

// Messages (personnels + diffusés à tous), marqués lus à l'affichage
$stmt = $pdo->prepare('SELECT * FROM messages_membres WHERE membre_id = :id OR membre_id IS NULL ORDER BY created_at DESC');
$stmt->execute([':id' => $membre['id']]);
$messages = $stmt->fetchAll();
foreach ($messages as $msg) {
    $mark = $pdo->prepare('INSERT IGNORE INTO messages_lectures (message_id, membre_id) VALUES (:mid, :bid)');
    $mark->execute([':mid' => $msg['id'], ':bid' => $membre['id']]);
}

$statutLabels = ['en_attente' => 'status_en_attente', 'accepte' => 'status_accepte', 'refuse' => 'status_refuse'];
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(t($lang, 'dashboard_title')) ?> — OrTra Suisse de l'Événementiel</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main>
  <section class="section section--light">
    <div class="container">
      <div class="form-card" style="max-width:720px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
          <h1 style="margin:0; font-size:1.4rem;"><?= e(t($lang, 'dashboard_title')) ?></h1>
          <a href="deconnexion.php" class="btn btn-secondary" style="font-size:0.85rem;"><?= e(t($lang, 'dashboard_logout')) ?></a>
        </div>

        <?php if ($saved): ?>
          <div class="form-alert is-visible form-alert--success" role="status"><?= e(t($lang, 'dashboard_saved')) ?></div>
        <?php endif; ?>
        <?php if ($syncError !== ''): ?>
          <div class="form-alert is-visible form-alert--error" role="alert"><?= e($syncError) ?></div>
        <?php endif; ?>

        <h2 style="font-size:1.05rem;"><?= e(t($lang, 'dashboard_status_label')) ?></h2>
        <p><strong><?= e(t($lang, $statutLabels[$membre['statut_admission']] ?? 'status_en_attente')) ?></strong></p>

        <h2 style="font-size:1.05rem; margin-top:2rem;"><?= e(t($lang, 'dashboard_invoice_title')) ?></h2>
        <?php if ($membre['paiement_statut'] === 'paye'): ?>
          <?= render_invoice_html($membre, $lang) ?>
        <?php else: ?>
          <p><?= e(t($lang, 'dashboard_invoice_none')) ?></p>
          <?php if ($membre['statut_admission'] === 'accepte' && $membre['payment_token']): ?>
            <p><a class="btn btn-primary" href="paiement.php?ref=<?= e((string) $membre['payment_token']) ?>"><?= e(t($lang, 'pay_button')) ?></a></p>
          <?php endif; ?>
        <?php endif; ?>

        <h2 style="font-size:1.05rem; margin-top:2rem;"><?= e(t($lang, 'dashboard_profile_title')) ?></h2>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="form-row-2 form-grid" style="display:grid;">
            <div class="field">
              <label for="prenom"><?= e(t($lang, 'field_prenom')) ?></label>
              <input type="text" id="prenom" name="prenom" required value="<?= e((string) $membre['prenom']) ?>">
            </div>
            <div class="field">
              <label for="nom"><?= e(t($lang, 'field_nom')) ?></label>
              <input type="text" id="nom" name="nom" required value="<?= e((string) $membre['nom']) ?>">
            </div>
          </div>
          <div class="field">
            <label for="telephone"><?= e(t($lang, 'field_telephone')) ?></label>
            <input type="tel" id="telephone" name="telephone" value="<?= e((string) ($membre['telephone'] ?? '')) ?>">
          </div>
          <div class="field">
            <label for="adresse"><?= e(t($lang, 'field_adresse')) ?></label>
            <input type="text" id="adresse" name="adresse" value="<?= e((string) ($membre['es_address'] ?? '')) ?>">
          </div>
          <div class="field">
            <label for="canton"><?= e(t($lang, 'field_canton')) ?></label>
            <select id="canton" name="canton" required><?= render_canton_options((string) ($membre['canton'] ?? '')) ?></select>
          </div>
          <?php if ($membre['account_type'] === 'company'): ?>
            <div class="form-row-2 form-grid" style="display:grid;">
              <div class="field">
                <label for="legal_name"><?= e(t($lang, 'field_legal_name')) ?></label>
                <input type="text" id="legal_name" name="legal_name" value="<?= e((string) ($membre['es_legal_name'] ?? '')) ?>">
              </div>
              <div class="field">
                <label for="entreprise"><?= e(t($lang, 'field_entreprise')) ?></label>
                <input type="text" id="entreprise" name="entreprise" value="<?= e((string) ($membre['entreprise'] ?? '')) ?>">
              </div>
            </div>
            <div class="field">
              <label for="company_address"><?= e(t($lang, 'field_company_address')) ?></label>
              <input type="text" id="company_address" name="company_address" value="<?= e((string) ($membre['es_company_address'] ?? '')) ?>">
            </div>
            <div class="form-row-2 form-grid" style="display:grid;">
              <div class="field">
                <label for="company_email"><?= e(t($lang, 'field_company_email')) ?></label>
                <input type="email" id="company_email" name="company_email" value="<?= e((string) ($membre['es_company_email'] ?? '')) ?>">
              </div>
              <div class="field">
                <label for="company_phone"><?= e(t($lang, 'field_company_phone')) ?></label>
                <input type="tel" id="company_phone" name="company_phone" value="<?= e((string) ($membre['es_company_phone'] ?? '')) ?>">
              </div>
            </div>
          <?php endif; ?>

          <?php if ((int) $membre['event_swiss_account_linked'] === 1): ?>
            <div class="field">
              <label class="checkbox-field" for="sync_event_swiss">
                <input type="checkbox" id="sync_event_swiss" name="sync_event_swiss" value="1">
                <span><?= e(t($lang, 'dashboard_sync_label')) ?></span>
              </label>
            </div>
          <?php endif; ?>

          <div class="form-submit-row">
            <button type="submit" class="btn btn-primary"><?= e(t($lang, 'dashboard_save')) ?></button>
          </div>
        </form>

        <h2 style="font-size:1.05rem; margin-top:2rem;"><?= e(t($lang, 'dashboard_events_title')) ?></h2>
        <?php if (empty($evenements)): ?>
          <p><?= e(t($lang, 'dashboard_events_empty')) ?></p>
        <?php else: ?>
          <ul>
            <?php foreach ($evenements as $ev): ?>
              <li>
                <strong><?= e((string) $ev['titre']) ?></strong> —
                <?= e(date('d.m.Y', strtotime((string) $ev['date_debut']))) ?>
                <?php if ($ev['lieu']): ?> · <?= e((string) $ev['lieu']) ?><?php endif; ?>
                <?php if ($ev['description']): ?><br><span style="font-size:0.85rem; color:#666;"><?= nl2br(e((string) $ev['description'])) ?></span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <h2 style="font-size:1.05rem; margin-top:2rem;"><?= e(t($lang, 'dashboard_messages_title')) ?></h2>
        <?php if (empty($messages)): ?>
          <p><?= e(t($lang, 'dashboard_messages_empty')) ?></p>
        <?php else: ?>
          <ul>
            <?php foreach ($messages as $msg): ?>
              <li style="margin-bottom:1rem;">
                <strong><?= e((string) $msg['titre']) ?></strong>
                <span style="font-size:0.8rem; color:#999;"> — <?= e(date('d.m.Y', strtotime((string) $msg['created_at']))) ?></span>
                <br><?= nl2br(e((string) $msg['corps'])) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      </div>
    </div>
  </section>
</main>
</body>
</html>
