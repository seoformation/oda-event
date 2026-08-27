<?php
/**
 * admin-connexion.php — Connexion de l'équipe OrTra (table admins,
 * distincte des comptes membres). Comptes créés manuellement en base
 * (phpMyAdmin) pour l'instant, pas d'auto-inscription.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

start_secure_session();

if (!empty($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Session expirée, merci de réessayer.";
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $pdo = get_pdo();
        if (is_login_locked($pdo, 'admins', $email)) {
            $error = "Trop de tentatives, réessayez dans quelques minutes.";
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE email = :email');
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch();

            // password_verify() est systematiquement appele (sur un hash
            // factice si le compte n'existe pas) pour que le temps de
            // reponse ne revele pas quels e-mails ont un compte admin.
            $hashToCheck = $admin ? $admin['password_hash'] : '$2y$10$invalidsaltinvalidsaltinvalidsaltinva';
            $passwordOk = password_verify($password, $hashToCheck);

            if ($admin && $passwordOk) {
                reset_login_failures($pdo, 'admins', (int) $admin['id']);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $admin['id'];
                header('Location: admin.php');
                exit;
            }

            if ($admin) {
                register_login_failure($pdo, 'admins', $email);
            }
            $error = "Identifiants incorrects.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion admin — OrTra Suisse de l'Événementiel</title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main>
  <section class="section section--light">
    <div class="container">
      <div class="form-card" style="max-width:420px;">
        <h1 style="margin-top:0; font-size:1.4rem;">Connexion équipe OrTra</h1>
        <?php if ($error !== ''): ?>
          <div class="form-alert is-visible form-alert--error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="field">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autocomplete="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>">
          </div>
          <div class="field">
            <label for="password">Mot de passe</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
          </div>
          <div class="form-submit-row">
            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
          </div>
        </form>
      </div>
    </div>
  </section>
</main>
</body>
</html>
