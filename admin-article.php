<?php
/**
 * admin-article.php — Creation/edition d'un article de blog (trilingue
 * FR/DE/IT obligatoire). Fichier separe de admin.php (contrairement aux
 * evenements/messages, geres inline) car le formulaire est trop volumineux
 * pour rester lisible dans le meme fichier que les autres onglets.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$admin = require_admin_login();
$pdo = get_pdo();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$article = null;
if ($id !== null) {
    $stmt = $pdo->prepare('SELECT * FROM articles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $article = $stmt->fetch();
    if (!$article) {
        header('Location: admin.php?tab=articles');
        exit;
    }
}

$notice = '';
$noticeType = 'success';
$values = $article ?: [
    'slug' => '', 'titre_fr' => '', 'titre_de' => '', 'titre_it' => '',
    'extrait_fr' => '', 'extrait_de' => '', 'extrait_it' => '',
    'contenu_fr' => '', 'contenu_de' => '', 'contenu_it' => '',
    'meta_description_fr' => '', 'meta_description_de' => '', 'meta_description_it' => '',
    'image_cover' => '', 'statut' => 'brouillon',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $titreFr = trim((string) ($_POST['titre_fr'] ?? ''));
    $titreDe = trim((string) ($_POST['titre_de'] ?? ''));
    $titreIt = trim((string) ($_POST['titre_it'] ?? ''));
    $extraitFr = trim((string) ($_POST['extrait_fr'] ?? ''));
    $extraitDe = trim((string) ($_POST['extrait_de'] ?? ''));
    $extraitIt = trim((string) ($_POST['extrait_it'] ?? ''));
    $contenuFr = trim((string) ($_POST['contenu_fr'] ?? ''));
    $contenuDe = trim((string) ($_POST['contenu_de'] ?? ''));
    $contenuIt = trim((string) ($_POST['contenu_it'] ?? ''));
    $metaFr = trim((string) ($_POST['meta_description_fr'] ?? ''));
    $metaDe = trim((string) ($_POST['meta_description_de'] ?? ''));
    $metaIt = trim((string) ($_POST['meta_description_it'] ?? ''));
    $imageCover = trim((string) ($_POST['image_cover'] ?? ''));
    $statut = (string) ($_POST['statut'] ?? 'brouillon') === 'publie' ? 'publie' : 'brouillon';
    $slugInput = trim((string) ($_POST['slug'] ?? ''));

    $values = [
        'slug' => $slugInput, 'titre_fr' => $titreFr, 'titre_de' => $titreDe, 'titre_it' => $titreIt,
        'extrait_fr' => $extraitFr, 'extrait_de' => $extraitDe, 'extrait_it' => $extraitIt,
        'contenu_fr' => $contenuFr, 'contenu_de' => $contenuDe, 'contenu_it' => $contenuIt,
        'meta_description_fr' => $metaFr, 'meta_description_de' => $metaDe, 'meta_description_it' => $metaIt,
        'image_cover' => $imageCover, 'statut' => $statut,
    ];

    $requiredOk = $titreFr !== '' && $titreDe !== '' && $titreIt !== ''
        && $extraitFr !== '' && $extraitDe !== '' && $extraitIt !== ''
        && $contenuFr !== '' && $contenuDe !== '' && $contenuIt !== '';

    if (!$requiredOk) {
        $notice = "Titre, extrait et contenu sont obligatoires dans les 3 langues (FR/DE/IT).";
        $noticeType = 'error';
    } else {
        $slug = $slugInput !== '' ? slugify($slugInput) : slugify($titreFr);
        if ($slug === '') {
            $notice = "Impossible de générer une adresse (slug) à partir du titre.";
            $noticeType = 'error';
        } elseif (!slug_est_disponible($pdo, $slug, $id)) {
            $notice = "Cette adresse (" . $slug . ") est déjà utilisée par un autre article. Modifiez le titre ou le slug.";
            $noticeType = 'error';
        } else {
            $publieLe = null;
            if ($statut === 'publie') {
                $publieLe = ($article && $article['publie_le']) ? $article['publie_le'] : date('Y-m-d H:i:s');
            }

            if ($article) {
                $stmt = $pdo->prepare(
                    'UPDATE articles SET slug=:slug, titre_fr=:titre_fr, titre_de=:titre_de, titre_it=:titre_it,
                     extrait_fr=:extrait_fr, extrait_de=:extrait_de, extrait_it=:extrait_it,
                     contenu_fr=:contenu_fr, contenu_de=:contenu_de, contenu_it=:contenu_it,
                     meta_description_fr=:meta_fr, meta_description_de=:meta_de, meta_description_it=:meta_it,
                     image_cover=:image_cover, statut=:statut, publie_le=:publie_le
                     WHERE id=:id'
                );
                $stmt->execute([
                    ':slug' => $slug, ':titre_fr' => $titreFr, ':titre_de' => $titreDe, ':titre_it' => $titreIt,
                    ':extrait_fr' => $extraitFr, ':extrait_de' => $extraitDe, ':extrait_it' => $extraitIt,
                    ':contenu_fr' => $contenuFr, ':contenu_de' => $contenuDe, ':contenu_it' => $contenuIt,
                    ':meta_fr' => $metaFr !== '' ? $metaFr : null, ':meta_de' => $metaDe !== '' ? $metaDe : null, ':meta_it' => $metaIt !== '' ? $metaIt : null,
                    ':image_cover' => $imageCover !== '' ? $imageCover : null, ':statut' => $statut, ':publie_le' => $publieLe,
                    ':id' => $article['id'],
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO articles (slug, titre_fr, titre_de, titre_it, extrait_fr, extrait_de, extrait_it,
                     contenu_fr, contenu_de, contenu_it, meta_description_fr, meta_description_de, meta_description_it,
                     image_cover, statut, publie_le, created_by)
                     VALUES (:slug, :titre_fr, :titre_de, :titre_it, :extrait_fr, :extrait_de, :extrait_it,
                     :contenu_fr, :contenu_de, :contenu_it, :meta_fr, :meta_de, :meta_it,
                     :image_cover, :statut, :publie_le, :created_by)'
                );
                $stmt->execute([
                    ':slug' => $slug, ':titre_fr' => $titreFr, ':titre_de' => $titreDe, ':titre_it' => $titreIt,
                    ':extrait_fr' => $extraitFr, ':extrait_de' => $extraitDe, ':extrait_it' => $extraitIt,
                    ':contenu_fr' => $contenuFr, ':contenu_de' => $contenuDe, ':contenu_it' => $contenuIt,
                    ':meta_fr' => $metaFr !== '' ? $metaFr : null, ':meta_de' => $metaDe !== '' ? $metaDe : null, ':meta_it' => $metaIt !== '' ? $metaIt : null,
                    ':image_cover' => $imageCover !== '' ? $imageCover : null, ':statut' => $statut, ':publie_le' => $publieLe,
                    ':created_by' => $admin['id'],
                ]);
                $id = (int) $pdo->lastInsertId();
            }

            header('Location: admin-article.php?id=' . $id . '&saved=1');
            exit;
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = "Article enregistré.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $article ? 'Modifier' : 'Nouvel' ?> article — Espace admin OrTra</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=15">
</head>
<body>
<main>
  <section class="section section--light">
    <div class="container">
      <div class="form-card" style="max-width:900px;">
        <div class="admin-topbar">
          <h1><?= $article ? 'Modifier l\'article' : 'Nouvel article' ?></h1>
          <a href="admin.php?tab=articles" class="btn btn-secondary" style="font-size:0.85rem;">&larr; Retour aux articles</a>
        </div>

        <?php if ($notice !== ''): ?>
          <div class="form-alert is-visible form-alert--<?= $noticeType === 'error' ? 'error' : 'success' ?>" role="status"><?= e($notice) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

          <div class="field">
            <label for="slug">Adresse (slug) — laisser vide pour générer depuis le titre FR</label>
            <input type="text" id="slug" name="slug" value="<?= e((string) $values['slug']) ?>" placeholder="ex-mon-article">
          </div>

          <div class="field">
            <label for="image_cover">Image de couverture (URL) — uploadez-la d'abord dans assets/img/blog/ via le gestionnaire de fichiers Hostinger, puis collez son chemin ici</label>
            <input type="text" id="image_cover" name="image_cover" value="<?= e((string) $values['image_cover']) ?>" placeholder="assets/img/blog/mon-image.jpg">
          </div>

          <div class="field">
            <label for="statut">Statut</label>
            <select id="statut" name="statut">
              <option value="brouillon" <?= $values['statut'] === 'brouillon' ? 'selected' : '' ?>>Brouillon (non visible publiquement)</option>
              <option value="publie" <?= $values['statut'] === 'publie' ? 'selected' : '' ?>>Publié</option>
            </select>
          </div>

          <?php foreach (['fr' => 'Français', 'de' => 'Allemand', 'it' => 'Italien'] as $langCode => $langLabel): ?>
            <h2 class="admin-section-title">Contenu — <?= $langLabel ?></h2>
            <div class="field">
              <label for="titre_<?= $langCode ?>">Titre *</label>
              <input type="text" id="titre_<?= $langCode ?>" name="titre_<?= $langCode ?>" value="<?= e((string) $values['titre_' . $langCode]) ?>" required maxlength="200">
            </div>
            <div class="field">
              <label for="extrait_<?= $langCode ?>">Extrait * (résumé court affiché dans la liste, 1-2 phrases)</label>
              <textarea id="extrait_<?= $langCode ?>" name="extrait_<?= $langCode ?>" required maxlength="300" rows="2"><?= e((string) $values['extrait_' . $langCode]) ?></textarea>
            </div>
            <div class="field">
              <label for="contenu_<?= $langCode ?>">Contenu * (un paragraphe par ligne vide entre deux blocs de texte)</label>
              <textarea id="contenu_<?= $langCode ?>" name="contenu_<?= $langCode ?>" required rows="10"><?= e((string) $values['contenu_' . $langCode]) ?></textarea>
            </div>
            <div class="field">
              <label for="meta_description_<?= $langCode ?>">Méta-description SEO (optionnel, ~155 caractères — sinon l'extrait est réutilisé)</label>
              <input type="text" id="meta_description_<?= $langCode ?>" name="meta_description_<?= $langCode ?>" value="<?= e((string) $values['meta_description_' . $langCode]) ?>" maxlength="160">
            </div>
          <?php endforeach; ?>

          <button type="submit" class="btn btn-primary btn-block">Enregistrer</button>
        </form>
      </div>
    </div>
  </section>
</main>
</body>
</html>
