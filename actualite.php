<?php
/**
 * actualite.php — Detail d'un article publie du blog OrTra, trilingue via
 * ?slug=...&lang=fr|de|it. Voir actualites.php pour la liste.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

$lang = get_lang($_GET);
$slug = trim((string) ($_GET['slug'] ?? ''));
$pdo = get_pdo();

if ($slug === '') {
    header('Location: actualites.php?lang=' . $lang);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM articles WHERE slug = :slug AND statut = 'publie'");
$stmt->execute([':slug' => $slug]);
$article = $stmt->fetch();

if (!$article) {
    http_response_code(404);
}

$langSwitchUrls = [
    'fr' => 'actualite.php?slug=' . urlencode($slug) . '&lang=fr',
    'de' => 'actualite.php?slug=' . urlencode($slug) . '&lang=de',
    'it' => 'actualite.php?slug=' . urlencode($slug) . '&lang=it',
];

if ($article) {
    $titre = (string) $article['titre_' . $lang];
    $extrait = (string) $article['extrait_' . $lang];
    $metaDescription = (string) ($article['meta_description_' . $lang] ?: $extrait);
    $contenuHtml = render_article_paragraphs((string) $article['contenu_' . $lang]);
    $canonicalUrl = 'https://oda-event.ch/actualite.php?slug=' . urlencode($slug) . '&lang=' . $lang;
}

$relatedLabels = [
    'fr' => ['back' => "← Retour aux actualités", 'notfound_title' => 'Article introuvable', 'notfound_body' => "Cet article n'existe pas ou n'est plus publié.", 'notfound_cta' => 'Voir toutes les actualités'],
    'de' => ['back' => '← Zurück zu Aktuelles', 'notfound_title' => 'Artikel nicht gefunden', 'notfound_body' => 'Dieser Artikel existiert nicht mehr oder ist nicht veröffentlicht.', 'notfound_cta' => 'Alle Beiträge ansehen'],
    'it' => ['back' => '← Torna alle novità', 'notfound_title' => 'Articolo non trovato', 'notfound_body' => 'Questo articolo non esiste o non è più pubblicato.', 'notfound_cta' => 'Vedi tutte le novità'],
];
$rl = $relatedLabels[$lang];
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $article ? e($titre . " — OrTra Suisse de l'Événementiel") : e($rl['notfound_title']) ?></title>
<?php if ($article): ?>
<meta name="description" content="<?= e($metaDescription) ?>">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=15">
<link rel="canonical" href="<?= e($canonicalUrl) ?>">
<link rel="alternate" hreflang="fr" href="https://oda-event.ch/actualite.php?slug=<?= e(urlencode($slug)) ?>&amp;lang=fr">
<link rel="alternate" hreflang="de" href="https://oda-event.ch/actualite.php?slug=<?= e(urlencode($slug)) ?>&amp;lang=de">
<link rel="alternate" hreflang="it" href="https://oda-event.ch/actualite.php?slug=<?= e(urlencode($slug)) ?>&amp;lang=it">
<link rel="alternate" hreflang="x-default" href="https://oda-event.ch/actualite.php?slug=<?= e(urlencode($slug)) ?>&amp;lang=fr">
<meta property="og:type" content="article">
<meta property="og:site_name" content="OrTra Suisse de l'Événementiel">
<meta property="og:title" content="<?= e($titre) ?>">
<meta property="og:description" content="<?= e($metaDescription) ?>">
<meta property="og:url" content="<?= e($canonicalUrl) ?>">
<?php if ($article['image_cover']): ?>
<meta property="og:image" content="https://oda-event.ch/<?= e((string) $article['image_cover']) ?>">
<?php endif; ?>
<script type="application/ld+json">
<?= safe_json_ld([
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $titre,
    'description' => $metaDescription,
    'author' => ['@type' => 'Organization', 'name' => "OrTra Suisse de l'Événementiel", 'url' => 'https://oda-event.ch/'],
    'publisher' => ['@type' => 'Organization', 'name' => "OrTra Suisse de l'Événementiel", 'logo' => ['@type' => 'ImageObject', 'url' => 'https://oda-event.ch/assets/img/OrTra_Logo_FR.svg']],
    'mainEntityOfPage' => $canonicalUrl,
    'inLanguage' => $lang . '-CH',
    'datePublished' => date('Y-m-d', strtotime((string) $article['publie_le'])),
    'dateModified' => date('Y-m-d', strtotime((string) $article['updated_at'])),
]) ?>
</script>
<?php else: ?>
<meta name="robots" content="noindex">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=15">
<?php endif; ?>
</head>
<body>

<?= render_public_header($lang, $langSwitchUrls) ?>

<main>
<?php if ($article): ?>
  <section class="section section--dark">
    <div class="container" style="max-width:820px;">
      <p class="eyebrow" style="justify-content:center;"><?= e(cs($lang, 'blog')) ?></p>
      <h1 style="text-align:center;"><?= e($titre) ?></h1>
      <p class="lede" style="text-align:center;"><?= e(date('d.m.Y', strtotime((string) $article['publie_le']))) ?></p>
    </div>
  </section>

  <section class="section section--light">
    <div class="container" style="max-width:760px;">
      <?php if ($article['image_cover']): ?>
        <img src="<?= e((string) $article['image_cover']) ?>" alt="" style="width:100%; border-radius:12px; margin-bottom:2rem;">
      <?php endif; ?>
      <div class="section-text" style="max-width:none;">
        <?= $contenuHtml ?>
      </div>
      <p style="margin-top:2.5rem;"><a href="actualites.php?lang=<?= e($lang) ?>"><?= e($rl['back']) ?></a></p>
    </div>
  </section>
<?php else: ?>
  <section class="section section--light">
    <div class="container" style="max-width:600px; text-align:center;">
      <h1><?= e($rl['notfound_title']) ?></h1>
      <p class="section-text" style="max-width:none;"><?= e($rl['notfound_body']) ?></p>
      <a href="actualites.php?lang=<?= e($lang) ?>" class="btn btn-primary"><?= e($rl['notfound_cta']) ?></a>
    </div>
  </section>
<?php endif; ?>
</main>

<?= render_public_footer($lang, $langSwitchUrls) ?>

<script src="assets/js/main.js?v=4"></script>
</body>
</html>
