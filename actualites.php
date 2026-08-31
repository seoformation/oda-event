<?php
/**
 * actualites.php — Liste des articles publiés du blog OrTra, trilingue via
 * ?lang=fr|de|it (meme convention que connexion.php). Premiere page
 * dynamique publique du site : les pages statiques utilisent un fichier par
 * langue, ce que le contenu genere depuis la base ne permet pas ici.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$lang = get_lang($_GET);
$pdo = get_pdo();

$articles = $pdo->query(
    "SELECT slug, titre_fr, titre_de, titre_it, extrait_fr, extrait_de, extrait_it, image_cover, publie_le
     FROM articles WHERE statut = 'publie' ORDER BY publie_le DESC"
)->fetchAll();

$titreChamp = 'titre_' . $lang;
$extraitChamp = 'extrait_' . $lang;

$langSwitchUrls = ['fr' => 'actualites.php?lang=fr', 'de' => 'actualites.php?lang=de', 'it' => 'actualites.php?lang=it'];

$pageTitles = [
    'fr' => "Actualités — OrTra Suisse de l'Événementiel",
    'de' => 'Aktuelles — OrTra Schweiz Eventbranche',
    'it' => "Novità — OrTra Svizzera dell'Eventistica",
];
$pageDescriptions = [
    'fr' => "Actualités, formation professionnelle et vie de l'OrTra Suisse de l'Événementiel.",
    'de' => 'Neuigkeiten, Berufsbildung und Vereinsleben der OrTra Schweiz Eventbranche.',
    'it' => "Novità, formazione professionale e vita associativa dell'OrTra Svizzera dell'Eventistica.",
];
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitles[$lang]) ?></title>
<meta name="description" content="<?= e($pageDescriptions[$lang]) ?>">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=15">
<link rel="canonical" href="https://oda-event.ch/actualites.php?lang=<?= e($lang) ?>">
<link rel="alternate" hreflang="fr" href="https://oda-event.ch/actualites.php?lang=fr">
<link rel="alternate" hreflang="de" href="https://oda-event.ch/actualites.php?lang=de">
<link rel="alternate" hreflang="it" href="https://oda-event.ch/actualites.php?lang=it">
<link rel="alternate" hreflang="x-default" href="https://oda-event.ch/actualites.php?lang=fr">
<meta property="og:type" content="website">
<meta property="og:site_name" content="OrTra Suisse de l'Événementiel">
<meta property="og:title" content="<?= e($pageTitles[$lang]) ?>">
<meta property="og:description" content="<?= e($pageDescriptions[$lang]) ?>">
</head>
<body>

<?= render_public_header($lang, $langSwitchUrls) ?>

<main>
  <section class="section section--dark">
    <div class="container" style="max-width:820px;">
      <p class="eyebrow" style="justify-content:center;"><?= e(cs($lang, 'blog')) ?></p>
      <h1 style="text-align:center;"><?= e($pageTitles[$lang]) ?></h1>
      <p class="lede" style="text-align:center;"><?= e($pageDescriptions[$lang]) ?></p>
    </div>
  </section>

  <section class="section section--light">
    <div class="container" style="max-width:900px;">
      <?php if (empty($articles)): ?>
        <p class="section-text" style="text-align:center; max-width:none;">
          <?= $lang === 'de' ? 'Noch keine Beiträge veröffentlicht.' : ($lang === 'it' ? 'Nessun articolo pubblicato per il momento.' : "Aucun article publié pour le moment.") ?>
        </p>
      <?php else: ?>
        <div class="card-grid card-grid--2">
          <?php foreach ($articles as $art): ?>
            <a href="actualite.php?slug=<?= e((string) $art['slug']) ?>&amp;lang=<?= e($lang) ?>" class="contrast-card contrast-card--light" style="text-decoration:none; display:block;">
              <?php if ($art['image_cover']): ?>
                <img src="<?= e((string) $art['image_cover']) ?>" alt="" style="width:100%; height:180px; object-fit:cover; border-radius:8px; margin-bottom:1rem;">
              <?php endif; ?>
              <p style="font-size:0.8rem; color:var(--text-on-light-secondary); margin-bottom:0.4rem;"><?= e(date('d.m.Y', strtotime((string) $art['publie_le']))) ?></p>
              <h3><?= e((string) $art[$titreChamp]) ?></h3>
              <p style="color:var(--text-on-light-secondary); font-size:0.95rem;"><?= e((string) $art[$extraitChamp]) ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<?= render_public_footer($lang, $langSwitchUrls) ?>

<script src="assets/js/main.js?v=4"></script>
</body>
</html>
