<?php
/**
 * connexion.php — Connexion membre (compte créé automatiquement à la
 * soumission de devenir-membre*.html, voir inscription.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

start_secure_session();

if (!empty($_SESSION['membre_id'])) {
    header('Location: mon-compte.php');
    exit;
}

$lang = get_lang($_POST + $_GET);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = t($lang, 'err_csrf');
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $pdo = get_pdo();
        if (is_login_locked($pdo, 'membres_inscription', $email)) {
            $error = t($lang, 'err_login_locked');
        } else {
            $stmt = $pdo->prepare('SELECT * FROM membres_inscription WHERE email = :email');
            $stmt->execute([':email' => $email]);
            $membre = $stmt->fetch();

            // password_verify() est systematiquement appele (sur un hash
            // factice si le compte n'existe pas) pour que le temps de
            // reponse ne revele pas quels e-mails ont un compte.
            $hashToCheck = ($membre && $membre['password_hash']) ? $membre['password_hash'] : '$2y$10$invalidsaltinvalidsaltinvalidsaltinva';
            $passwordOk = password_verify($password, $hashToCheck);

            if ($membre && $membre['password_hash'] && $passwordOk) {
                reset_login_failures($pdo, 'membres_inscription', (int) $membre['id']);
                session_regenerate_id(true);
                $_SESSION['membre_id'] = (int) $membre['id'];
                header('Location: mon-compte.php');
                exit;
            }

            if ($membre) {
                register_login_failure($pdo, 'membres_inscription', $email);
            }
            $error = t($lang, 'err_login_invalid');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(t($lang, 'login_title')) ?> — OrTra Suisse de l'Événementiel</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/img/favicon.svg?v=2" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main>
  <section class="section section--light">
    <div class="container">
      <div class="form-card" style="max-width:420px;">
        <h1 style="margin-top:0; font-size:1.4rem;"><?= e(t($lang, 'login_title')) ?></h1>
        <?php if ($error !== ''): ?>
          <div class="form-alert is-visible form-alert--error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="field">
            <label for="email"><?= e(t($lang, 'field_email')) ?></label>
            <input type="email" id="email" name="email" required autocomplete="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>">
          </div>
          <div class="field">
            <label for="password"><?= e(t($lang, 'field_password')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
          </div>
          <div class="form-submit-row">
            <button type="submit" class="btn btn-primary btn-block"><?= e(t($lang, 'login_button')) ?></button>
          </div>
        </form>
        <p style="margin-top:1rem; font-size:0.85rem;"><?= e(t($lang, 'login_forgot')) ?> <a href="contact.html"><?= e(t($lang, 'login_contact_link')) ?></a></p>
      </div>
    </div>
  </section>
</main>
</body>
</html>
